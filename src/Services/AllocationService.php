<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use PDO;

final class AllocationService
{
    /**
     * Give every unallocated occurrence (due within the window) one
     * suggested allocation: the latest paycheck with pay_date <= due_date,
     * falling back to the earliest paycheck on record. The user can
     * reassign or split afterwards.
     */
    public static function autoAllocate(int $userId): void
    {
        $pdo = Database::pdo();

        $pdo->beginTransaction();
        try {
            self::lockBudget($pdo, $userId);
            $settings = ScheduleService::userSettings($userId) ?? [];
            $to = (new DateTimeImmutable('today'))
                ->modify('+' . ScheduleService::windowDays($settings) . ' days')
                ->format('Y-m-d');

            $stmt = $pdo->prepare(
                'SELECT o.id, o.due_date, o.amount
                 FROM bill_occurrences o
                 LEFT JOIN allocations a ON a.occurrence_id = o.id
                 WHERE o.user_id = ? AND o.due_date <= ? AND o.skipped = 0 AND a.id IS NULL FOR UPDATE'
            );
            $stmt->execute([$userId, $to]);
            $orphans = $stmt->fetchAll();

            $latestBefore = $pdo->prepare(
                'SELECT id FROM paychecks WHERE user_id = ? AND pay_date <= ?
                 ORDER BY pay_date DESC LIMIT 1'
            );
            $earliest = $pdo->prepare(
                'SELECT id FROM paychecks WHERE user_id = ? ORDER BY pay_date ASC LIMIT 1'
            );
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO allocations (user_id, paycheck_id, occurrence_id, amount)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($orphans as $occ) {
                $latestBefore->execute([$userId, $occ['due_date']]);
                $paycheckId = $latestBefore->fetchColumn();

                if ($paycheckId === false) {
                    $earliest->execute([$userId]);
                    $paycheckId = $earliest->fetchColumn();
                }
                if ($paycheckId === false) {
                    break; // no paychecks at all yet
                }

                $insert->execute([$userId, (int) $paycheckId, (int) $occ['id'], (string) $occ['amount']]);
            }
            self::carryForward($pdo, $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function lockBudget(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        // Match schedule reconciliation's paycheck-before-occurrence order;
        // occurrence locks also serialize payment and skip writers.
        foreach (['paychecks', 'bill_occurrences'] as $table) {
            $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE user_id = ? ORDER BY id FOR UPDATE");
            $stmt->execute([$userId]);
            $stmt->fetchAll();
        }
    }

    private static function carryForward(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, pay_date FROM paychecks WHERE user_id = ? AND pay_date <= ?
             ORDER BY pay_date DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$userId, date('Y-m-d')]);
        $current = $stmt->fetch();
        if (!$current) {
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT a.id, a.occurrence_id, a.amount,
                    LEAST(p.pay_date, COALESCE(a.carried_from_date, p.pay_date)) AS source_date
             FROM allocations a
             INNER JOIN paychecks p ON p.id = a.paycheck_id AND p.user_id = a.user_id
             INNER JOIN bill_occurrences o ON o.id = a.occurrence_id AND o.user_id = a.user_id
             WHERE a.user_id = ? AND p.pay_date < ? AND o.paid = 0 AND o.skipped = 0
             ORDER BY a.id FOR UPDATE'
        );
        $stmt->execute([$userId, $current['pay_date']]);
        $sources = $stmt->fetchAll();
        $merge = $pdo->prepare(
            'INSERT INTO allocations (user_id, paycheck_id, occurrence_id, amount, carried_from_date)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = allocations.amount + VALUES(amount),
                 carried_from_date = LEAST(COALESCE(allocations.carried_from_date, VALUES(carried_from_date)),
                                           VALUES(carried_from_date))'
        );
        $delete = $pdo->prepare('DELETE FROM allocations WHERE id = ? AND user_id = ?');
        foreach ($sources as $source) {
            $merge->execute([$userId, $current['id'], $source['occurrence_id'], $source['amount'], $source['source_date']]);
            $delete->execute([$source['id'], $userId]);
        }
    }

    /**
     * Replace an occurrence's allocations with a split across paychecks.
     * A single row moves the occurrence wholesale (a "reassign").
     * $rows = [paycheck_id => amount string]. The amounts must be positive
     * and sum to the occurrence amount. Returns an error message or null.
     *
     * @param array<int, string> $rows
     */
    public static function saveSplit(int $occurrenceId, array $rows, int $userId): ?string
    {
        $pdo = Database::pdo();

        $pdo->beginTransaction();
        try {
            self::lockBudget($pdo, $userId);
            $error = self::replaceSplit($pdo, $occurrenceId, $rows, $userId);
            $pdo->commit();
            return $error;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<int, string> $rows */
    private static function replaceSplit(PDO $pdo, int $occurrenceId, array $rows, int $userId): ?string
    {
        $occ = self::userOccurrence($pdo, $occurrenceId, $userId);
        if ($occ === null) {
            return 'Bill occurrence not found.';
        }
        if ((bool) $occ['skipped']) {
            return 'Skipped occurrences cannot be allocated.';
        }
        if ($rows === []) {
            return 'At least one allocation row is required.';
        }

        $totalCents = 0;
        foreach ($rows as $paycheckId => $amount) {
            if (!self::userOwnsPaycheck($pdo, (int) $paycheckId, $userId)) {
                return 'Paycheck not found.';
            }
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
                return 'Each split amount must be a positive dollar amount.';
            }
            $totalCents += (int) round(((float) $amount) * 100);
        }

        $occCents = (int) round(((float) $occ['amount']) * 100);
        if ($totalCents !== $occCents) {
            return sprintf(
                'Split amounts must add up to the bill amount ($%s). They currently total $%s.',
                money((string) $occ['amount']),
                money(sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100))
            );
        }

        $pdo->prepare('DELETE FROM allocations WHERE occurrence_id = ? AND user_id = ?')
            ->execute([$occurrenceId, $userId]);
        $insert = $pdo->prepare(
            'INSERT INTO allocations (user_id, paycheck_id, occurrence_id, amount)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as $paycheckId => $amount) {
            $insert->execute([$userId, (int) $paycheckId, $occurrenceId, $amount]);
        }

        return null;
    }

    /**
     * Keep a single (unsplit) allocation's amount in lockstep with its
     * occurrence amount after an inline edit. Splits are left alone — the
     * user manages those explicitly.
     */
    public static function syncSingleAllocation(int $occurrenceId, int $userId, string $amount): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::lockBudget($pdo, $userId);
            $occ = self::userOccurrence($pdo, $occurrenceId, $userId);
            $count = $pdo->prepare('SELECT id FROM allocations WHERE occurrence_id = ? AND user_id = ? FOR UPDATE');
            $count->execute([$occurrenceId, $userId]);
            if ($occ !== null && count($count->fetchAll()) === 1) {
                // A later edit may have committed before this request synced.
                $pdo->prepare('UPDATE allocations SET amount = ? WHERE occurrence_id = ? AND user_id = ?')
                    ->execute([$occ['amount'], $occurrenceId, $userId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Allocation totals for a set of paychecks, for AJAX responses.
     *
     * bills_total counts only UNPAID allocations, so the Bills line counts
     * down as bills are ticked off; remaining stays income minus everything
     * allocated (paid or not), so it still reflects real leftover money.
     *
     * @param list<int> $paycheckIds
     * @return array<int, array{paycheck_id: int, bills_total: string, paid_total: string, remaining: string}>
     */
    public static function paycheckTotals(int $userId, array $paycheckIds): array
    {
        if ($paycheckIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($paycheckIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT p.id, p.amount,
                    COALESCE(SUM(a.amount), 0) AS allocated_total,
                    COALESCE(SUM(CASE WHEN o.paid = 0 THEN a.amount ELSE 0 END), 0) AS unpaid_total,
                    COALESCE(SUM(CASE WHEN o.paid = 1 THEN a.amount ELSE 0 END), 0) AS paid_total
             FROM paychecks p
             LEFT JOIN allocations a ON a.paycheck_id = p.id
             LEFT JOIN bill_occurrences o ON o.id = a.occurrence_id
             WHERE p.user_id = ? AND p.id IN ($placeholders)
             GROUP BY p.id, p.amount"
        );
        $stmt->execute([$userId, ...$paycheckIds]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(int) $row['id']] = [
                'paycheck_id' => (int) $row['id'],
                'bills_total' => number_format((float) $row['unpaid_total'], 2, '.', ''),
                'paid_total'  => number_format((float) $row['paid_total'], 2, '.', ''),
                'remaining'   => number_format((float) $row['amount'] - (float) $row['allocated_total'], 2, '.', ''),
            ];
        }

        return $totals;
    }

    /** @return array<string, mixed>|null */
    private static function userOccurrence(PDO $pdo, int $occurrenceId, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM bill_occurrences WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$occurrenceId, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private static function userOwnsPaycheck(PDO $pdo, int $paycheckId, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM paychecks WHERE id = ? AND user_id = ?');
        $stmt->execute([$paycheckId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
