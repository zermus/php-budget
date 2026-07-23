<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\View;

final class UserController
{
    /**
     * Roles an administrator may hand out. A sub-user with 'admin' is a
     * co-administrator: same access as the owner, except that the owner's
     * own account can never be edited or removed from here.
     */
    private const ASSIGNABLE_ROLES = ['admin', 'budgeter', 'payer', 'viewer'];

    public function index(): void
    {
        $me = Auth::requireAdmin();
        $ownerId = Auth::dataUserId();

        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT id, email, role, receive_reminders, created
             FROM users WHERE owner_id = ? ORDER BY role, email'
        );
        $stmt->execute([$ownerId]);
        $users = $stmt->fetchAll();

        // The owner is shown for context but is never editable here.
        $stmt = $pdo->prepare('SELECT id, email, receive_reminders FROM users WHERE id = ?');
        $stmt->execute([$ownerId]);
        $owner = $stmt->fetch() ?: [];

        echo View::render('users/index', [
            'title' => 'Users',
            'me'    => $me,
            'owner' => $owner,
            'users' => $users,
        ]);
    }

    public function createForm(): void
    {
        Auth::requireAdmin();

        echo View::render('users/form', [
            'title' => 'Add User',
            'error' => null,
            'old'   => [],
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();
        Csrf::require();

        $email = input_string('email');
        $password = (string) ($_POST['password'] ?? '');
        $verify = (string) ($_POST['verifyPassword'] ?? '');
        $role = input_string('role');
        $reminders = !empty($_POST['receiveReminders']) ? 1 : 0;

        $error = null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            $error = 'Choose a role for this user.';
        } elseif ($password !== $verify) {
            $error = 'The passwords do not match.';
        } elseif (!password_meets_policy($password)) {
            $error = 'Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.';
        } elseif ($this->emailTaken($email)) {
            $error = 'That email address is already in use.';
        }

        if ($error !== null) {
            echo View::render('users/form', [
                'title' => 'Add User',
                'error' => $error,
                'old'   => $_POST,
            ]);

            return;
        }

        // Sub-users have no user_settings row of their own — they read and
        // write the owner's budget via Auth::dataUserId().
        Database::pdo()->prepare(
            'INSERT INTO users (email, password_hash, role, owner_id, receive_reminders)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $email,
            password_hash($password, PASSWORD_ARGON2ID),
            $role,
            Auth::dataUserId(),
            $reminders,
        ]);

        flash('User added.');
        redirect('/users');
    }

    public function update(): void
    {
        Auth::requireAdmin();
        Csrf::require();

        $user = $this->ownedUser(input_int('id'));
        if ($user === null) {
            flash('User not found, or not one you can change (you cannot change your own role).', 'error');
            redirect('/users');
        }

        $role = input_string('role');
        if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            flash('Choose a valid role.', 'error');
            redirect('/users');
        }

        Database::pdo()->prepare(
            'UPDATE users SET role = ?, receive_reminders = ? WHERE id = ? AND owner_id = ?'
        )->execute([
            $role,
            !empty($_POST['receiveReminders']) ? 1 : 0,
            (int) $user['id'],
            Auth::dataUserId(),
        ]);

        flash('User updated.');
        redirect('/users');
    }

    public function resetPassword(): void
    {
        Auth::requireAdmin();
        Csrf::require();

        $user = $this->ownedUser(input_int('id'));
        if ($user === null) {
            flash('User not found, or not one you can change (change your own password under Settings).', 'error');
            redirect('/users');
        }

        $password = (string) ($_POST['password'] ?? '');
        if (!password_meets_policy($password)) {
            flash('Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.', 'error');
            redirect('/users');
        }

        Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND owner_id = ?')
            ->execute([password_hash($password, PASSWORD_ARGON2ID), (int) $user['id'], Auth::dataUserId()]);

        flash('Password reset for ' . $user['email'] . '.');
        redirect('/users');
    }

    public function delete(): void
    {
        Auth::requireAdmin();
        Csrf::require();

        // ownedUser() blocks the owner, other households, and self-deletion.
        $user = $this->ownedUser(input_int('id'));
        if ($user === null) {
            flash('User not found, or not one you can remove.', 'error');
            redirect('/users');
        }

        Database::pdo()->prepare('DELETE FROM users WHERE id = ? AND owner_id = ?')
            ->execute([(int) $user['id'], Auth::dataUserId()]);

        flash('Removed ' . $user['email'] . '.');
        redirect('/users');
    }

    /**
     * A user in this household that the caller may act on. Scoping by
     * owner_id keeps the owner (owner_id NULL) untouchable, and the id
     * check stops an administrator editing or deleting themselves.
     *
     * @return array<string, mixed>|null
     */
    private function ownedUser(int $userId): ?array
    {
        $me = Auth::user() ?? [];
        if ($userId === (int) ($me['id'] ?? 0)) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? AND owner_id = ?');
        $stmt->execute([$userId, Auth::dataUserId()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function emailTaken(string $email): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
