<?php

declare(strict_types=1);

namespace App\Installer;

use PDO;

final class Seeder
{
    /**
     * Seed the starter budget: a biweekly $3,200 schedule (anchored
     * 2026-07-22) with nine per-paycheck bills in two alternating phases.
     * Phase A bills land on the anchor check and every 2nd check after;
     * Phase B bills on the checks in between.
     */
    public static function seedStarterBudget(PDO $pdo, int $userId): void
    {
        $pdo->prepare(
            "UPDATE user_settings
             SET schedule_type = 'biweekly', anchor_date = ?, default_income = ?,
                 reminder_lead_days = 1
             WHERE user_id = ?"
        )->execute(['2026-07-22', '3200.00', $userId]);

        $phaseA = json_encode(['n' => 2, 'anchor' => '2026-07-22']);
        $phaseB = json_encode(['n' => 2, 'anchor' => '2026-08-05']);

        $bills = [
            // [name, amount, recurrence_value]
            ['Rent (first half)',  '2450.00', $phaseA],
            ['AT&T (first half)',  '140.00',  $phaseA],
            ['Chime loan',         '130.00',  $phaseA],
            ['Rent (second half)', '710.00',  $phaseB],
            ['State Farm',         '335.00',  $phaseB],
            ['Avant',              '400.00',  $phaseB],
            ['Brightway',          '500.00',  $phaseB],
            ['Colocrossing',       '185.00',  $phaseB],
            ['AT&T (second half)', '255.00',  $phaseB],
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
