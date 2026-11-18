<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 9,
    'description' => 'Track the start of new and reactivated recurring bills',
    'up' => function (PDO $pdo, Migrator $m): void {
        if (!$m->columnExists('bills', 'starts_on')) {
            $pdo->exec('ALTER TABLE bills ADD COLUMN starts_on DATE NULL');
        }
    },
];
