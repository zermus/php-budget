<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 8,
    'description' => 'Track earliest paycheck source for unpaid carryovers',
    'up' => function (PDO $pdo, Migrator $m): void {
        if (!$m->columnExists('allocations', 'carried_from_date')) {
            $pdo->exec('ALTER TABLE allocations ADD COLUMN carried_from_date DATE NULL');
        }
    },
];
