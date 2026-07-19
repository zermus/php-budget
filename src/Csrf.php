<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Validate the token in $_POST or stop the request (HTML 403).
     */
    public static function require(): void
    {
        if (!self::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo View::render('error', [
                'title'   => 'Forbidden',
                'heading' => 'Request Blocked',
                'message' => 'Your session expired or the request was invalid. Please go back and try again.',
            ]);
            exit;
        }
    }

    /**
     * Validate the token in $_POST or stop the request (JSON 403).
     */
    public static function requireJson(): void
    {
        if (!self::validate($_POST['csrf_token'] ?? null)) {
            json_response(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }
    }
}
