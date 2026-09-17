<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\LoginThrottle;
use App\View;

final class AuthController
{
    /**
     * Verified against when the email is unknown, so a miss costs the same
     * Argon2id work as a wrong password and response timing doesn't reveal
     * which emails have accounts. The password behind it was random and
     * discarded.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ePZXjqOBxZnhT3+9JV6B4w$/+w6wUMuIzTXRgrM4zL9DLmFtbUKMOkyzbg7RXFq2xA';

    public function loginForm(): void
    {
        if (Auth::user() !== null) {
            redirect('/dashboard');
        }

        echo View::render('auth/login', ['title' => 'Budget Login', 'error' => null]);
    }

    public function login(): void
    {
        if (Auth::user() !== null) {
            redirect('/dashboard');
        }
        Csrf::require();

        $email = input_string('email');
        $password = (string) ($_POST['password'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Checked before the password, so a locked-out guesser learns nothing.
        if (LoginThrottle::tooManyAttempts($email, $ip)) {
            http_response_code(429);
            echo View::render('auth/login', [
                'title' => 'Budget Login',
                'error' => 'Too many failed sign-in attempts. Please wait '
                    . LoginThrottle::WINDOW_MINUTES . ' minutes and try again.',
            ]);

            return;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $valid = password_verify($password, $user['password_hash']);
        } else {
            password_verify($password, self::DUMMY_HASH);
            $valid = false;
        }

        if (!$valid) {
            LoginThrottle::recordFailure($email, $ip);
            echo View::render('auth/login', [
                'title' => 'Budget Login',
                'error' => 'Invalid email or password.',
            ]);

            return;
        }

        LoginThrottle::clear($email, $ip);

        // Transparently upgrade hashes if the algorithm or cost changes.
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $user['password_hash'] = password_hash($password, PASSWORD_ARGON2ID);
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$user['password_hash'], $user['id']]);
        }

        Auth::login($user);
        redirect('/dashboard');
    }

    public function logout(): void
    {
        Csrf::require();
        Auth::logout();
        redirect('/login');
    }
}
