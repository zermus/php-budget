<?php

declare(strict_types=1);
use App\Installer\Migrator;

return [
    'version' => 7,
    'description' => 'Track paycheck amount overrides for safe schedule changes',
    'up' => function (PDO $pdo, Migrator $m): void {
        if (!$m->columnExists('paychecks', 'amount_overridden')) {
            $pdo->exec(
                'ALTER TABLE paychecks
                 ADD COLUMN amount_overridden TINYINT(1) NOT NULL DEFAULT 0
                 AFTER amount'
            );
            $pdo->exec(
                'UPDATE paychecks p
                 INNER JOIN user_settings s ON s.user_id = p.user_id
                 SET p.amount_overridden = 1
                 WHERE p.amount <> s.default_income'
            );
        }

        if (!$m->columnExists('user_settings', 'schedule_effective_date')) {
            $pdo->exec(
                'ALTER TABLE user_settings
                 ADD COLUMN schedule_effective_date DATE NULL
                 AFTER day_of_month'
            );
        }
    },
];
