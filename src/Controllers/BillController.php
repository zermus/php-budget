<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\View;
use DateTimeImmutable;

final class BillController
{
    public function index(): void
    {
        Auth::requireAdmin();

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
        Auth::requireAdmin();

        echo View::render('bills/form', [
            'title' => 'Add Bill',
            'bill'  => null,
            'error' => null,
            'old'   => [],
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();
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

        $stmt = Database::pdo()->prepare(
            'INSERT INTO bills (user_id, name, default_amount, recurrence_type, recurrence_value, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Auth::dataUserId(),
            $fields['name'],
            $fields['amount'],
            $fields['type'],
            $fields['value'],
            $fields['notes'],
        ]);

        flash('Bill added.');
        redirect('/bills');
    }

    public function editForm(): void
    {
        Auth::requireAdmin();

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
        Auth::requireAdmin();
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

        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE bills SET name = ?, default_amount = ?, recurrence_type = ?,
                    recurrence_value = ?, notes = ?
             WHERE id = ? AND user_id = ?'
        )->execute([
            $fields['name'], $fields['amount'], $fields['type'],
            $fields['value'], $fields['notes'],
            (int) $bill['id'], $userId,
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

        flash($rebuilt ? 'Bill saved; its upcoming occurrences were rebuilt.' : 'Bill saved.');
        redirect('/bills');
    }

    public function toggleActive(): void
    {
        Auth::requireAdmin();
        Csrf::require();
        $userId = Auth::dataUserId();

        $bill = $this->userBill(input_int('id'), $userId);
        if ($bill === null) {
            flash('Bill not found.', 'error');
            redirect('/bills');
        }

        $newActive = empty($bill['active']) ? 1 : 0;
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE bills SET active = ? WHERE id = ? AND user_id = ?')
            ->execute([$newActive, (int) $bill['id'], $userId]);

        if ($newActive === 0) {
            // Deactivating clears upcoming unpaid occurrences; paid rows stay
            // as history. Reactivating regenerates lazily.
            $pdo->prepare(
                'DELETE FROM bill_occurrences
                 WHERE bill_id = ? AND user_id = ? AND paid = 0'
            )->execute([(int) $bill['id'], $userId]);
        }

        flash($newActive ? 'Bill reactivated.' : 'Bill deactivated; upcoming occurrences removed.');
        redirect('/bills');
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        Csrf::require();

        $stmt = Database::pdo()->prepare('DELETE FROM bills WHERE id = ? AND user_id = ?');
        $stmt->execute([input_int('id'), Auth::dataUserId()]);

        flash($stmt->rowCount() > 0 ? 'Bill deleted, including its history.' : 'Bill not found.', $stmt->rowCount() > 0 ? 'success' : 'error');
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
    private function userBill(int $billId, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM bills WHERE id = ? AND user_id = ?');
        $stmt->execute([$billId, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
