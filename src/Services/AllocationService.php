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

        $settings = ScheduleService::userSettings($userId) ?? [];
        $to = (new DateTimeImmutable('today'))
            ->modify('+' . ScheduleService::windowDays($settings) . ' days')
            ->format('Y-m-d');

        $stmt = $pdo->prepare(
            'SELECT o.id, o.due_date, o.amount
             FROM bill_occurrences o
             LEFT JOIN allocations a ON a.occurrence_id = o.id
             WHERE o.user_id = ? AND o.due_date <= ? AND o.skipped = 0 AND a.id IS NULL'
        );
        $stmt->execute([$userId, $to]);
        $orphans = $stmt->fetchAll();

        if ($orphans === []) {
            return;
        }

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
                return; // no paychecks at all yet
            }

            $insert->execute([$userId, (int) $paycheckId, (int) $occ['id'], (string) $occ['amount']]);
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

        $occ = self::userOccurrence($pdo, $occurrenceId, $userId);
        if ($occ === null) {
            return 'Bill occurrence not found.';
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

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM allocations WHERE occurrence_id = ? AND user_id = ?')
                ->execute([$occurrenceId, $userId]);
            $insert = $pdo->prepare(
                'INSERT INTO allocations (user_id, paycheck_id, occurrence_id, amount)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($rows as $paycheckId => $amount) {
                $insert->execute([$userId, (int) $paycheckId, $occurrenceId, $amount]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
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
        $count = $pdo->prepare('SELECT COUNT(*) FROM allocations WHERE occurrence_id = ? AND user_id = ?');
        $count->execute([$occurrenceId, $userId]);

        if ((int) $count->fetchColumn() === 1) {
            $pdo->prepare('UPDATE allocations SET amount = ? WHERE occurrence_id = ? AND user_id = ?')
                ->execute([$amount, $occurrenceId, $userId]);
        }
    }

    /**
     * Allocation totals for a set of paychecks, for AJAX responses:
     * paycheck_id => [paycheck_id, bills_total, remaining].
     *
     * @param list<int> $paycheckIds
     * @return array<int, array{paycheck_id: int, bills_total: string, remaining: string}>
     */
    public static function paycheckTotals(int $userId, array $paycheckIds): array
    {
        if ($paycheckIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($paycheckIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT p.id, p.amount, COALESCE(SUM(a.amount), 0) AS bills_total
             FROM paychecks p
             LEFT JOIN allocations a ON a.paycheck_id = p.id
             WHERE p.user_id = ? AND p.id IN ($placeholders)
             GROUP BY p.id, p.amount"
        );
        $stmt->execute([$userId, ...$paycheckIds]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(int) $row['id']] = [
                'paycheck_id' => (int) $row['id'],
                'bills_total' => number_format((float) $row['bills_total'], 2, '.', ''),
                'remaining'   => number_format((float) $row['amount'] - (float) $row['bills_total'], 2, '.', ''),
            ];
        }

        return $totals;
    }

    /** @return array<string, mixed>|null */
    private static function userOccurrence(PDO $pdo, int $occurrenceId, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM bill_occurrences WHERE id = ? AND user_id = ?');
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
