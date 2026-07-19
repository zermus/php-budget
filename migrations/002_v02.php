<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 2,
    'description' => 'Dashboard window length and bill sort preferences (0.2)',
    'up' => function (PDO $pdo, Migrator $m): void {
        if (!$m->columnExists('user_settings', 'window_days')) {
            $pdo->exec(
                "ALTER TABLE user_settings
                 ADD COLUMN window_days SMALLINT UNSIGNED NOT NULL DEFAULT 90
                 AFTER reminder_lead_days"
            );
        }

        if (!$m->columnExists('user_settings', 'dashboard_sort')) {
            $pdo->exec(
                "ALTER TABLE user_settings
                 ADD COLUMN dashboard_sort VARCHAR(20) NOT NULL DEFAULT 'amount_desc'
                 AFTER window_days"
            );
        }
    },
];
