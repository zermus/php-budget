<?php

declare(strict_types=1);

namespace App\Installer;

use PDO;

final class Seeder
{
    /**
     * Seed an example starter budget: a biweekly $2,500 schedule with
     * per-paycheck bills in two alternating phases. Phase A bills land on
     * the anchor check and every 2nd check after; Phase B bills on the
     * checks in between. Meant to be edited or deleted under Bills.
     */
    public static function seedStarterBudget(PDO $pdo, int $userId): void
    {
        $pdo->prepare(
            "UPDATE user_settings
             SET schedule_type = 'biweekly', anchor_date = ?, default_income = ?,
                 reminder_lead_days = 1
             WHERE user_id = ?"
        )->execute(['2026-01-07', '2500.00', $userId]);

        $phaseA = json_encode(['n' => 2, 'anchor' => '2026-01-07']);
        $phaseB = json_encode(['n' => 2, 'anchor' => '2026-01-21']);

        $bills = [
            // [name, amount, recurrence_value]
            ['Rent (first half)',  '1200.00', $phaseA],
            ['Car payment',        '320.00',  $phaseA],
            ['Internet',           '90.00',   $phaseA],
            ['Rent (second half)', '400.00',  $phaseB],
            ['Car insurance',      '150.00',  $phaseB],
            ['Electric',           '110.00',  $phaseB],
            ['Cell phone',         '95.00',   $phaseB],
            ['Streaming',          '25.00',   $phaseB],
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO bills (user_id, name, default_amount, recurrence_type, recurrence_value, active)
             VALUES (?, ?, ?, 'every_n_paychecks', ?, 1)"
        );

        foreach ($bills as [$name, $amount, $recurrence]) {
            $stmt->execute([$userId, $name, $amount, $recurrence]);
        }
    }
}
