<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\View;

final class AuthController
{
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

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            echo View::render('auth/login', [
                'title' => 'Budget Login',
                'error' => 'Invalid email or password.',
            ]);

            return;
        }

        // Transparently upgrade hashes if the algorithm or cost changes.
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_ARGON2ID), $user['id']]);
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
