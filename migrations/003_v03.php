<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 3,
    'description' => 'Household sub-users with roles and per-user reminders (0.3)',
    'up' => function (PDO $pdo, Migrator $m): void {
        // Existing accounts keep the defaults and become admins of their own
        // budget (owner_id NULL), which is exactly the 0.2 behaviour.
        if (!$m->columnExists('users', 'role')) {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN role ENUM('admin','payer','viewer') NOT NULL DEFAULT 'admin'
                 AFTER password_hash"
            );
        }

        if (!$m->columnExists('users', 'owner_id')) {
            $pdo->exec(
                'ALTER TABLE users
                 ADD COLUMN owner_id INT NULL AFTER role,
                 ADD CONSTRAINT fk_users_owner FOREIGN KEY (owner_id)
                     REFERENCES users (id) ON DELETE CASCADE'
            );
        }

        if (!$m->columnExists('users', 'receive_reminders')) {
            $pdo->exec(
                'ALTER TABLE users
                 ADD COLUMN receive_reminders TINYINT(1) NOT NULL DEFAULT 1
                 AFTER owner_id'
            );
        }
    },
];
