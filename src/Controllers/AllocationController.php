<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Services\AllocationService;
use App\Services\ScheduleService;
use App\View;
use DateTimeImmutable;

final class AllocationController
{
    public function editForm(): void
    {
        Auth::requireBudgeter();
        $userId = Auth::dataUserId();

        $occurrence = $this->userOccurrence(input_int('occurrence_id', $_GET), $userId);
        if ($occurrence === null) {
            flash('Bill occurrence not found.', 'error');
            redirect('/dashboard');
        }

        echo View::render('allocations/edit', [
            'title'       => 'Edit Allocation',
            'occurrence'  => $occurrence,
            'paychecks'   => $this->windowPaychecks($userId, (int) $occurrence['id']),
            'allocations' => $this->occurrenceAllocations((int) $occurrence['id'], $userId),
        ]);
    }

    public function save(): void
    {
        Auth::requireBudgeter();
        Csrf::require();
        $userId = Auth::dataUserId();

        $occurrence = $this->userOccurrence(input_int('occurrence_id'), $userId);
        if ($occurrence === null) {
            flash('Bill occurrence not found.', 'error');
            redirect('/dashboard');
        }

        $paycheckIds = $_POST['paycheck_id'] ?? [];
        $amounts = $_POST['amount'] ?? [];
        if (!is_array($paycheckIds) || !is_array($amounts) || count($paycheckIds) !== count($amounts)) {
            flash('Invalid allocation form.', 'error');
            redirect('/allocations/edit?occurrence_id=' . (int) $occurrence['id']);
        }

        $rows = [];
        foreach ($paycheckIds as $i => $paycheckId) {
            $paycheckId = (int) $paycheckId;
            $amount = str_replace(['$', ',', ' '], '', trim((string) ($amounts[$i] ?? '')));
            if ($paycheckId < 1 && $amount === '') {
                continue; // blank extra row
            }
            if (isset($rows[$paycheckId])) {
                flash('Each paycheck can appear only once in a split.', 'error');
                redirect('/allocations/edit?occurrence_id=' . (int) $occurrence['id']);
            }
            if ((float) $amount > 99999999.99) {
                flash('Each split amount must be at most $99,999,999.99.', 'error');
                redirect('/allocations/edit?occurrence_id=' . (int) $occurrence['id']);
            }
            $rows[$paycheckId] = $amount;
        }

        $error = AllocationService::saveSplit((int) $occurrence['id'], $rows, $userId);
        if ($error !== null) {
            flash($error, 'error');
            redirect('/allocations/edit?occurrence_id=' . (int) $occurrence['id']);
        }

        flash(count($rows) > 1 ? 'Split saved.' : 'Allocation saved.');
        redirect('/dashboard');
    }

    /** @return array<string, mixed>|null */
    private function userOccurrence(int $occurrenceId, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT o.*, b.name AS bill_name
             FROM bill_occurrences o
             INNER JOIN bills b ON b.id = o.bill_id
             WHERE o.id = ? AND o.user_id = ?'
        );
        $stmt->execute([$occurrenceId, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    private function windowPaychecks(int $userId, int $occurrenceId): array
    {
        $settings = ScheduleService::userSettings($userId) ?? [];
        $today = new DateTimeImmutable('today');
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $latest = Database::pdo()->prepare('SELECT MAX(pay_date) FROM paychecks WHERE user_id = ? AND pay_date <= ?');
        $latest->execute([$userId, $today->format('Y-m-d')]);
        $current = $latest->fetchColumn();
        if ($current) {
            $from = min($from, (string) $current);
        }
        $to = $today->modify('+' . ScheduleService::windowDays($settings) . ' days')->format('Y-m-d');

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM paychecks
             WHERE user_id = ? AND (pay_date BETWEEN ? AND ?
                 OR id IN (SELECT paycheck_id FROM allocations WHERE occurrence_id = ? AND user_id = ?))
             ORDER BY pay_date'
        );
        $stmt->execute([$userId, $from, $to, $occurrenceId, $userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    private function occurrenceAllocations(int $occurrenceId, int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, p.pay_date FROM allocations a
             INNER JOIN paychecks p ON p.id = a.paycheck_id
             WHERE a.occurrence_id = ? AND a.user_id = ?
             ORDER BY p.pay_date'
        );
        $stmt->execute([$occurrenceId, $userId]);

        return $stmt->fetchAll();
    }
}
