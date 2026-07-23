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

    // --- Roles and household access -------------------------------------

    /**
     * The user whose budget the current user works with: their own if they
     * are an account owner, otherwise the owner who invited them. EVERY
     * budget query scopes to this id, never to the logged-in id.
     */
    public static function dataUserId(): int
    {
        $user = self::requireLogin();

        return (int) ($user['owner_id'] ?? 0) ?: (int) $user['id'];
    }

    public static function role(): string
    {
        $user = self::user();

        return (string) ($user['role'] ?? 'viewer');
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Admins and payers may tick the paid checkboxes. */
    public static function canPay(): bool
    {
        return in_array(self::role(), ['admin', 'payer'], true);
    }

    /**
     * Require an admin (bills, schedule settings, allocations, users).
     *
     * @return array<string, mixed>
     */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!self::isAdmin()) {
            flash('That area is only available to the account administrator.', 'error');
            redirect('/dashboard');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public static function requireAdminJson(): array
    {
        $user = self::requireLoginJson();
        if (!self::isAdmin()) {
            json_response(['success' => false, 'error' => 'Administrator access required.'], 403);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public static function requirePayJson(): array
    {
        $user = self::requireLoginJson();
        if (!self::canPay()) {
            json_response(['success' => false, 'error' => 'Your account is read-only.'], 403);
        }

        return $user;
    }
}
