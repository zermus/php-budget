<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use LogicException;
use PDO;
use PDOException;
use Throwable;

final class BudgetLock
{
    /** Database-only work; mutable reads must be repeated inside each attempt. */
    public static function run(int $userId, callable $work): mixed
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            throw new LogicException('BudgetLock::run cannot own an existing transaction.');
        }
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $pdo->beginTransaction();
                self::lock($pdo, $userId);
                $result = $work($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!$e instanceof PDOException || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                    || !in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true) || $attempt === 3) {
                    throw $e;
                }
                usleep(random_int(10000, 50000));
            }
        }
    }

    /** Caller owns the transaction; acquire before any other budget reads/writes. */
    public static function lock(PDO $pdo, int $userId): void
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Budget locking requires an owned transaction.');
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $stmt->fetchAll();
        foreach (['paychecks', 'bill_occurrences'] as $table) {
            $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE user_id = ? ORDER BY id FOR UPDATE");
            $stmt->execute([$userId]);
            $stmt->fetchAll();
        }
    }
}
