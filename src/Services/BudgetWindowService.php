<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class BudgetWindowService
{
    public static function refresh(int $userId): ?array
    {
        return BudgetLock::run($userId, static function (PDO $pdo) use ($userId): ?array {
            ScheduleService::generateForUserLocked($pdo, $userId);
            OccurrenceService::generateForUserLocked($pdo, $userId);
            AllocationService::autoAllocateLocked($pdo, $userId);
            return ScheduleService::userSettings($userId, $pdo);
        });
    }
}
