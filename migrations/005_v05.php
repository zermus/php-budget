<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 5,
    'description' => 'Co-administrator and budgeter roles (0.5)',
    'up' => function (PDO $pdo, Migrator $m): void {
        // Widen the role enum. Existing values keep their meaning; 'admin'
        // on a sub-user now means a co-administrator of the owner's budget.
        $pdo->exec(
            "ALTER TABLE users
             MODIFY COLUMN role ENUM('admin','budgeter','payer','viewer')
             NOT NULL DEFAULT 'admin'"
        );
    },
];
