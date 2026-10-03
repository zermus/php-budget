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

    public function setupAuthorized(string $configuredToken, string $providedToken): bool
    {
        return $configuredToken !== '' && hash_equals($configuredToken, $providedToken);
    }

    public function upgradeAuthorized(
        bool $needsUpgrade,
        bool $authenticated,
        bool $roleColumnExists,
        bool $isAdmin
    ): bool {
        return $needsUpgrade && $authenticated && (!$roleColumnExists || $isAdmin);
    }

    /**
     * Serialize first-owner setup across requests, including fresh migrations.
     * Returns an error message, or null on success (user id in $userId).
     */
    public function installFirstOwner(
        PDO $pdo,
        Migrator $migrator,
        string $configuredToken,
        string $providedToken,
        string $email,
        string $password,
        string $verifyPassword,
        ?int &$userId = null
    ): ?string {
        if (!$this->setupAuthorized($configuredToken, $providedToken)) {
            return 'The setup token is invalid or is not configured.';
        }

        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'php-budget:install:' . hash('sha256', $database);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            return 'Another installation is in progress. Please try again.';
        }

        try {
            if ($migrator->currentVersion() < App::SCHEMA_VERSION) {
                $migrator->migrate();
            }
            if ($this->firstUserExists($pdo, $migrator)) {
                return 'Installation has already been claimed by an account owner.';
            }

            return $this->createUser($pdo, $email, $password, $verifyPassword, $userId);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
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
     * Atomically create the first owner account and its settings row.
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

        $pdo->beginTransaction();
        try {
            // Every migrated database has this singleton row. Locking it makes
            // the empty-owner check and insert one serialized operation.
            $lock = $pdo->prepare("SELECT value FROM settings WHERE name = 'schema_version' FOR UPDATE");
            $lock->execute();

            $owner = $pdo->query('SELECT id FROM users WHERE owner_id IS NULL LIMIT 1 FOR UPDATE');
            if ($owner->fetchColumn() !== false) {
                $pdo->rollBack();

                return 'Installation has already been claimed by an account owner.';
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users (email, password_hash, role, owner_id) VALUES (?, ?, 'admin', NULL)"
            );
            $stmt->execute([$email, password_hash($password, PASSWORD_ARGON2ID)]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return null;
    }
}
