<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 4,
    'description' => 'Mail settings move from config.php into the app (0.4)',
    'up' => function (PDO $pdo, Migrator $m): void {
        $columns = [
            // Blank/NULL means "fall back to config.php", so upgrading
            // installs keep sending exactly as they did before.
            'mail_transport'  => "VARCHAR(10) NOT NULL DEFAULT 'smtp'",
            'mail_from'       => 'VARCHAR(190) NULL',
            'mail_from_name'  => 'VARCHAR(190) NULL',
            'smtp_port'       => 'SMALLINT UNSIGNED NULL',
            'smtp_username'   => 'VARCHAR(190) NULL',
            'smtp_password'   => 'VARCHAR(255) NULL',
            'smtp_encryption' => "VARCHAR(10) NOT NULL DEFAULT 'none'",
        ];

        foreach ($columns as $name => $definition) {
            if (!$m->columnExists('user_settings', $name)) {
                $pdo->exec("ALTER TABLE user_settings ADD COLUMN {$name} {$definition}");
            }
        }

        // smtp_host has existed since 0.1 and may carry a "host:port" value.
        // Split it so the new port column is authoritative.
        $rows = $pdo->query(
            "SELECT user_id, smtp_host FROM user_settings
             WHERE smtp_host IS NOT NULL AND smtp_host LIKE '%:%'"
        )->fetchAll();

        $update = $pdo->prepare('UPDATE user_settings SET smtp_host = ?, smtp_port = ? WHERE user_id = ?');
        foreach ($rows as $row) {
            [$host, $port] = explode(':', (string) $row['smtp_host'], 2);
            $update->execute([$host, (int) $port ?: 25, (int) $row['user_id']]);
        }
    },
];
