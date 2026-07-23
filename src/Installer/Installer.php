<?php

declare(strict_types=1);

namespace App\Installer;

use App\App;
use App\Database;
use PDO;
use PDOException;

final class Installer
{
    /**
     * Connect to the app database, creating it first if it doesn't exist.
     */
    public function connect(): PDO
    {
        try {
            return Database::pdo();
        } catch (PDOException $e) {
            // 1049 = unknown database
            if (($e->errorInfo[1] ?? null) !== 1049) {
                throw $e;
            }
        }

        $name = (string) App::config('db.name');
        $server = Database::serverPdo();
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name)
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        return Database::pdo();
    }

    public function firstUserExists(PDO $pdo, Migrator $migrator): bool
    {
        if (!$migrator->tableExists('users')) {
            return false;
        }

        // Only an account owner counts — sub-users can't exist without one.
        $sql = $migrator->columnExists('users', 'owner_id')
            ? 'SELECT COUNT(*) FROM users WHERE owner_id IS NULL'
            : 'SELECT COUNT(*) FROM users';

        return (int) $pdo->query($sql)->fetchColumn() > 0;
    }

    /**
     * Create the first user account (argon2id) with a default settings row.
     * Returns an error message, or null on success (user id in $userId).
     */
    public function createUser(
        PDO $pdo,
        string $email,
        string $password,
        string $verifyPassword,
        ?int &$userId = null
    ): ?string {
        if ($email === '') {
            return 'Email is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }
        if ($password !== $verifyPassword) {
            return 'The passwords do not match. Please try again.';
        }
        if (!password_meets_policy($password)) {
            return 'Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.';
        }

        // The first account owns the budget and administers it; any users it
        // adds later are payer/viewer sub-users pointing back at this row.
        $stmt = $pdo->prepare(
            "INSERT INTO users (email, password_hash, role, owner_id) VALUES (?, ?, 'admin', NULL)"
        );
        $stmt->execute([$email, password_hash($password, PASSWORD_ARGON2ID)]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$userId]);

        return null;
    }
}
