<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Services\BudgetLock;
use App\View;
use DateTimeImmutable;

final class BillController
{
    public function index(): void
    {
        Auth::requireBudgeter();

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM bills WHERE user_id = ? ORDER BY active DESC, name'
        );
        $stmt->execute([Auth::dataUserId()]);

        echo View::render('bills/index', [
            'title' => 'Bills',
            'bills' => $stmt->fetchAll(),
        ]);
    }

    public function createForm(): void
    {
        Auth::requireBudgeter();

        echo View::render('bills/form', [
            'title' => 'Add Bill',
            'bill'  => null,
            'error' => null,
            'old'   => [],
        ]);
    }

    public function create(): void
    {
        Auth::requireBudgeter();
        Csrf::require();

        [$fields, $error] = $this->validated();
        if ($error !== null) {
            echo View::render('bills/form', [
                'title' => 'Add Bill',
                'bill'  => null,
                'error' => $error,
                'old'   => $_POST,
            ]);

            return;
        }

        $userId = Auth::dataUserId();
        BudgetLock::run($userId, static function (\PDO $pdo) use ($userId, $fields): void {
            $stmt = $pdo->prepare(
                'INSERT INTO bills (user_id, name, default_amount, recurrence_type, recurrence_value, notes, starts_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $fields['name'],
                $fields['amount'],
                $fields['type'],
                $fields['value'],
                $fields['notes'],
                (new DateTimeImmutable('today'))->format('Y-m-d'),
            ]);
        });

        flash('Bill added.');
        redirect('/bills');
    }

    public function editForm(): void
    {
        Auth::requireBudgeter();

        $bill = $this->userBill(input_int('id', $_GET), Auth::dataUserId());
        if ($bill === null) {
            flash('Bill not found.', 'error');
            redirect('/bills');
        }

        echo View::render('bills/form', [
            'title' => 'Edit Bill',
            'bill'  => $bill,
            'error' => null,
            'old'   => [],
        ]);
    }

    public function update(): void
    {
        Auth::requireBudgeter();
        Csrf::require();
        $userId = Auth::dataUserId();

        $bill = $this->userBill(input_int('id'), $userId);
        if ($bill === null) {
            flash('Bill not found.', 'error');
            redirect('/bills');
        }

        [$fields, $error] = $this->validated();
        if ($error !== null) {
            echo View::render('bills/form', [
                'title' => 'Edit Bill',
                'bill'  => $bill,
                'error' => $error,
                'old'   => $_POST,
            ]);

            return;
        }

        $billId = (int) $bill['id'];
        $rebuilt = BudgetLock::run($userId, function (\PDO $pdo) use ($userId, $billId, $fields): ?bool {
            $bill = $this->userBill($billId, $userId, true);
            if ($bill === null) {
                return null;
            }
            $pdo->prepare(
                'UPDATE bills SET name = ?, default_amount = ?, recurrence_type = ?,
                        recurrence_value = ?, notes = ?
                 WHERE id = ?'
            )->execute([
                $fields['name'], $fields['amount'], $fields['type'],
                $fields['value'], $fields['notes'],
                (int) $bill['id'],
            ]);

            // Amount or recurrence changes rebuild the bill's future unpaid
            // occurrences (regenerated on the next dashboard load). Paid history
            // is untouched; manual edits and skips on future ones are reset.
            $rebuilt = $fields['amount'] !== (string) $bill['default_amount']
                || $fields['type'] !== (string) $bill['recurrence_type']
                || $fields['value'] !== (string) $bill['recurrence_value'];

            if ($rebuilt) {
                $pdo->prepare(
                    'DELETE FROM bill_occurrences
                     WHERE bill_id = ? AND user_id = ? AND paid = 0 AND due_date >= ?'
                )->execute([(int) $bill['id'], $userId, (new DateTimeImmutable('today'))->format('Y-m-d')]);
            }
            return $rebuilt;
        });
        if ($rebuilt === null) {
            flash('Bill not found.', 'error');
            redirect('/bills');
        }

        flash($rebuilt ? 'Bill saved; its upcoming occurrences were rebuilt.' : 'Bill saved.');
        redirect('/bills');
    }

    public function toggleActive(): void
    {
        Auth::requireBudgeter();
        Csrf::require();
        $userId = Auth::dataUserId();

        $billId = input_int('id');
        $newActive = BudgetLock::run($userId, function (\PDO $pdo) use ($userId, $billId): ?int {
            $bill = $this->userBill($billId, $userId, true);
            if ($bill === null) {
                return null;
            }
            $newActive = empty($bill['active']) ? 1 : 0;
            $pdo->prepare('UPDATE bills SET active = ?, starts_on = CASE WHEN ? = 1 THEN ? ELSE starts_on END WHERE id = ?')
                ->execute([$newActive, $newActive, (new DateTimeImmutable('today'))->format('Y-m-d'), (int) $bill['id']]);

            if ($newActive === 0) {
                // Deactivating clears upcoming unpaid occurrences; paid rows stay
                // as history. Reactivating regenerates lazily.
                $pdo->prepare(
                    'DELETE FROM bill_occurrences
                     WHERE bill_id = ? AND user_id = ? AND paid = 0'
                )->execute([(int) $bill['id'], $userId]);
            }
            return $newActive;
        });
        if ($newActive === null) {
            flash('Bill not found.', 'error');
            redirect('/bills');
        }

        flash($newActive ? 'Bill reactivated.' : 'Bill deactivated; upcoming occurrences removed.');
        redirect('/bills');
    }

    public function delete(): void
    {
        Auth::requireBudgeter();
        Csrf::require();

        $userId = Auth::dataUserId();
        $billId = input_int('id');
        $deleted = BudgetLock::run($userId, function (\PDO $pdo) use ($userId, $billId): bool {
            if ($this->userBill($billId, $userId, true) === null) {
                return false;
            }
            $pdo->prepare('DELETE FROM bills WHERE id = ?')->execute([$billId]);
            return true;
        });

        flash($deleted ? 'Bill deleted, including its history.' : 'Bill not found.', $deleted ? 'success' : 'error');
        redirect('/bills');
    }

    /**
     * Validate the bill form. Returns [fields, null] or [[], error].
     *
     * @return array{0: array<string, ?string>, 1: ?string}
     */
    private function validated(): array
    {
        $name = input_string('name');
        $amount = input_decimal('amount');
        $type = input_string('recurrenceType');
        $notes = input_string('notes');

        if ($name === '') {
            return [[], 'Name is required.'];
        }
        if (mb_strlen($name) > 190) {
            return [[], 'Name must be at most 190 characters.'];
        }
        if ($amount === null || (float) $amount <= 0) {
            return [[], 'Enter a valid dollar amount.'];
        }

        switch ($type) {
            case 'monthly_day':
                $day = input_int('monthDay');
                if ($day < 1 || $day > 31) {
                    return [[], 'Day of month must be between 1 and 31.'];
                }
                $value = json_encode(['day' => $day]);
                break;

            case 'every_n_paychecks':
                $n = input_int('everyN');
                $anchor = input_string('anchorPaycheck');
                if ($n < 1 || $n > 12) {
                    return [[], 'Paycheck interval must be between 1 and 12.'];
                }
                if (parse_date($anchor) === null) {
                    return [[], 'Choose the first paycheck date this bill applies to.'];
                }
                $value = json_encode(['n' => $n, 'anchor' => $anchor]);
                break;

            case 'one_time':
                $date = input_string('oneTimeDate');
                if (parse_date($date) === null) {
                    return [[], 'Choose a valid due date.'];
                }
                $value = json_encode(['date' => $date]);
                break;

            default:
                return [[], 'Choose a recurrence type.'];
        }

        return [[
            'name'   => $name,
            'amount' => $amount,
            'type'   => $type,
            'value'  => (string) $value,
            'notes'  => $notes !== '' ? $notes : null,
        ], null];
    }

    /** @return array<string, mixed>|null */
    private function userBill(int $billId, int $userId, bool $lock = false): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM bills WHERE id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$billId, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
