<?php

declare(strict_types=1);
namespace App\Services;
use DateTimeImmutable;
use PDO;

final class ScheduleTransitionService
{
    /**
     * Reconcile generated schedule rows in-place so their IDs, allocations,
     * paid state, skips, and amount overrides survive a schedule change.
     * The caller owns the surrounding transaction.
     *
     * @param array<string, mixed> $newSettings
     */
    public static function reconcile(
        PDO $pdo,
        int $userId,
        array $newSettings,
        DateTimeImmutable $today
    ): void {
        $today = $today->setTime(0, 0);
        $to = $today->modify('+' . ScheduleService::windowDays($newSettings) . ' days');

        self::reconcilePaychecks($pdo, $userId, $newSettings, $today, $to);
        self::reconcilePaycheckBills($pdo, $userId, $newSettings, $today, $to);
    }

    /**
     * Pair old dates with new dates deterministically. Exact matches remain in
     * place; returned pairs can be updated without unique-key collisions.
     *
     * @param list<string> $existing
     * @param list<string> $desired
     * @return array{pairs:list<array{old:string,new:string}>,excess:list<string>,missing:list<string>}
     */
    public static function datePlan(array $existing, array $desired): array
    {
        $existing = array_values(array_unique($existing));
        $desired = array_values(array_unique($desired));
        sort($existing);
        sort($desired);

        $oldOnly = array_values(array_diff($existing, $desired));
        $newOnly = array_values(array_diff($desired, $existing));
        $pairCount = min(count($oldOnly), count($newOnly));
        $pairs = [];
        for ($i = 0; $i < $pairCount; $i++) {
            $pairs[] = ['old' => $oldOnly[$i], 'new' => $newOnly[$i]];
        }

        return [
            'pairs' => $pairs,
            'excess' => array_slice($oldOnly, $pairCount),
            'missing' => array_slice($newOnly, $pairCount),
        ];
    }

    /** @param array<string, mixed> $newSettings */
    private static function reconcilePaychecks(
        PDO $pdo,
        int $userId,
        array $newSettings,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): void {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.pay_date, p.amount, p.amount_overridden,
                    EXISTS(
                        SELECT 1 FROM allocations a
                        INNER JOIN bill_occurrences o ON o.id = a.occurrence_id
                        WHERE a.paycheck_id = p.id AND a.user_id = ? AND o.user_id = ? AND o.paid = 1
                    ) AS has_paid
             FROM paychecks p
             WHERE p.user_id = ? AND p.pay_date >= ? ORDER BY p.pay_date FOR UPDATE'
        );
        $stmt->execute([$userId, $userId, $userId, $from->format('Y-m-d')]);
        $byDate = [];
        $fixedDates = [];
        foreach ($stmt->fetchAll() as $row) {
            $date = (string) $row['pay_date'];
            if ((bool) $row['has_paid']) {
                $fixedDates[] = $date;
            } else {
                $byDate[$date] = $row;
            }
        }

        $wanted = ScheduleService::payDates($newSettings, $from, $to);
        $wantedByDate = [];
        foreach ($wanted as $payDate) {
            $wantedByDate[$payDate['date']] = $payDate;
        }
        $plan = self::datePlan(
            array_keys($byDate),
            array_values(array_diff(array_keys($wantedByDate), $fixedDates))
        );

        $move = $pdo->prepare(
            'UPDATE paychecks
             SET pay_date = ?, is_wave = ?, amount = CASE WHEN amount_overridden = 0 THEN ? ELSE amount END
             WHERE id = ? AND user_id = ?'
        );
        foreach ($plan['pairs'] as $pair) {
            $row = $byDate[$pair['old']];
            $target = $wantedByDate[$pair['new']];
            $move->execute([
                $pair['new'],
                (int) $target['is_wave'],
                (string) ($newSettings['default_income'] ?? '0.00'),
                (int) $row['id'],
                $userId,
            ]);
        }

        $delete = $pdo->prepare(
            'DELETE p FROM paychecks p
             LEFT JOIN allocations a ON a.paycheck_id = p.id AND a.user_id = ?
             WHERE p.id = ? AND p.user_id = ? AND a.id IS NULL AND p.amount_overridden = 0'
        );
        foreach ($plan['excess'] as $date) {
            $delete->execute([$userId, (int) $byDate[$date]['id'], $userId]);
        }

        $insert = $pdo->prepare(
            'INSERT INTO paychecks (user_id, pay_date, amount, is_wave) VALUES (?, ?, ?, ?)'
        );
        foreach ($plan['missing'] as $date) {
            $insert->execute([
                $userId,
                $date,
                (string) ($newSettings['default_income'] ?? '0.00'),
                (int) $wantedByDate[$date]['is_wave'],
            ]);
        }

        $refreshWave = $pdo->prepare(
            'UPDATE paychecks SET is_wave = ? WHERE user_id = ? AND pay_date = ?'
        );
        foreach ($wantedByDate as $date => $payDate) {
            $refreshWave->execute([(int) $payDate['is_wave'], $userId, $date]);
        }
    }

    /** @param array<string, mixed> $settings */
    private static function reconcilePaycheckBills(
        PDO $pdo,
        int $userId,
        array $settings,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): void {
        $bills = $pdo->prepare(
            "SELECT * FROM bills WHERE user_id = ? AND active = 1 AND recurrence_type = 'every_n_paychecks'"
        );
        $bills->execute([$userId]);

        $existing = $pdo->prepare(
            'SELECT o.id, o.due_date, o.amount, o.paid, o.skipped,
                    EXISTS(SELECT 1 FROM allocations a WHERE a.occurrence_id = o.id AND a.user_id = ?) AS allocated
             FROM bill_occurrences o
             WHERE o.user_id = ? AND o.bill_id = ? AND o.due_date >= ?
             ORDER BY o.due_date FOR UPDATE'
        );
        $move = $pdo->prepare(
            'UPDATE bill_occurrences SET due_date = ? WHERE id = ? AND user_id = ?'
        );
        $delete = $pdo->prepare(
            'DELETE FROM bill_occurrences
             WHERE id = ? AND user_id = ? AND paid = 0 AND skipped = 0 AND amount = ?'
        );
        $insert = $pdo->prepare(
            'INSERT INTO bill_occurrences (user_id, bill_id, due_date, amount) VALUES (?, ?, ?, ?)'
        );

        foreach ($bills->fetchAll() as $bill) {
            $billId = (int) $bill['id'];
            $existing->execute([$userId, $userId, $billId, $from->format('Y-m-d')]);
            $rows = $existing->fetchAll();
            $byDate = [];
            $fixedDates = [];
            foreach ($rows as $row) {
                $date = (string) $row['due_date'];
                if ((bool) $row['paid']) {
                    $fixedDates[] = $date;
                } else {
                    $byDate[$date] = $row;
                }
            }

            $wanted = OccurrenceService::dueDates($bill, $settings, $from, $to);
            $plan = self::datePlan($byDate === [] ? [] : array_keys($byDate), array_values(array_diff($wanted, $fixedDates)));
            foreach ($plan['pairs'] as $pair) {
                $move->execute([$pair['new'], (int) $byDate[$pair['old']]['id'], $userId]);
            }
            foreach ($plan['excess'] as $date) {
                $row = $byDate[$date];
                if (!(bool) $row['allocated']) {
                    $delete->execute([(int) $row['id'], $userId, (string) $bill['default_amount']]);
                }
            }
            foreach ($plan['missing'] as $date) {
                $insert->execute([$userId, $billId, $date, (string) $bill['default_amount']]);
            }
        }
    }
}
