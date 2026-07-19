<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /**
     * Log a user in (after the caller verified credentials).
     *
     * @param array<string, mixed> $user users table row
     */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    /**
     * The current user row, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            unset($_SESSION['user_id']);

            return null;
        }

        return self::$user = $user;
    }

    /**
     * Require a logged-in user or redirect to /login.
     *
     * @return array<string, mixed>
     */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            redirect('/login');
        }

        return $user;
    }

    /**
     * Same as requireLogin() but for JSON endpoints.
     *
     * @return array<string, mixed>
     */
    public static function requireLoginJson(): array
    {
        $user = self::user();
        if ($user === null) {
            json_response(['success' => false, 'error' => 'Not logged in.'], 401);
        }

        return $user;
    }
}
