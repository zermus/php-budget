<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Services\ScheduleService;
use App\View;
use DateTimeImmutable;

final class SettingsController
{
    public function form(): void
    {
        $user = Auth::requireLogin();
        $settings = ScheduleService::userSettings((int) $user['id']) ?? [];

        echo View::render('settings/form', [
            'title'    => 'Settings',
            'user'     => $user,
            'settings' => $settings,
            'old'      => [],
        ]);
    }

    public function save(): void
    {
        $user = Auth::requireLogin();
        Csrf::require();

        $action = input_string('action');
        if ($action === 'password') {
            $this->savePassword($user);

            return;
        }

        $this->saveSettings($user);
    }

    /** @param array<string, mixed> $user */
    private function saveSettings(array $user): void
    {
        $userId = (int) $user['id'];
        $old = ScheduleService::userSettings($userId);
        if ($old === null) {
            Database::pdo()->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
            $old = ScheduleService::userSettings($userId) ?? [];
        }

        $type = input_string('scheduleType');
        $anchorDate = null;
        $daysOfMonth = null;
        $dayOfMonth = null;

        switch ($type) {
            case 'weekly':
            case 'biweekly':
                $anchorDate = input_string('anchorDate');
                if (parse_date($anchorDate) === null) {
                    $this->fail('Choose a valid anchor pay date.');
                }
                break;

            case 'semimonthly':
                $day1 = input_int('semiDay1');
                $day2 = input_int('semiDay2');
                if ($day1 < 1 || $day1 > 31 || $day2 < 1 || $day2 > 31 || $day1 === $day2) {
                    $this->fail('Semimonthly needs two different days of the month (1–31).');
                }
                $days = [$day1, $day2];
                sort($days);
                $daysOfMonth = json_encode($days);
                break;

            case 'monthly':
                $dayOfMonth = input_int('monthDay');
                if ($dayOfMonth < 1 || $dayOfMonth > 31) {
                    $this->fail('Day of month must be between 1 and 31.');
                }
                break;

            default:
                $this->fail('Choose a pay schedule type.');
        }

        $income = input_decimal('defaultIncome');
        if ($income === null) {
            $this->fail('Enter a valid default paycheck amount.');
        }

        $leadDays = input_int('reminderLeadDays');
        if ($leadDays < 0 || $leadDays > 30) {
            $this->fail('Reminder lead days must be between 0 and 30.');
        }

        $smtpHost = input_string('smtpHost');
        if ($smtpHost !== '' && !preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $smtpHost)) {
            $this->fail('SMTP host must be a hostname or IP, optionally with :port.');
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE user_settings
             SET schedule_type = ?, anchor_date = ?, days_of_month = ?, day_of_month = ?,
                 default_income = ?, reminder_lead_days = ?, smtp_host = ?
             WHERE user_id = ?'
        )->execute([
            $type,
            $anchorDate !== '' ? $anchorDate : null,
            $daysOfMonth,
            $dayOfMonth,
            $income,
            $leadDays,
            $smtpHost !== '' ? $smtpHost : null,
            $userId,
        ]);

        $todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');

        $scheduleChanged = $type !== (string) $old['schedule_type']
            || ($anchorDate ?? '') !== (string) ($old['anchor_date'] ?? '')
            || ($daysOfMonth ?? '') !== (string) ($old['days_of_month'] ?? '')
            || ($dayOfMonth ?? 0) !== (int) ($old['day_of_month'] ?? 0);

        if ($scheduleChanged) {
            // Rebuild the future: future paychecks go (their allocations
            // cascade), future unpaid occurrences go. Paid history stays.
            // The next dashboard load regenerates and re-allocates.
            $pdo->prepare('DELETE FROM paychecks WHERE user_id = ? AND pay_date >= ?')
                ->execute([$userId, $todayStr]);
            $pdo->prepare('DELETE FROM bill_occurrences WHERE user_id = ? AND paid = 0 AND due_date >= ?')
                ->execute([$userId, $todayStr]);
            flash('Schedule changed — upcoming paychecks and bill occurrences were rebuilt. '
                . 'Check the anchor dates on any every-N-paychecks bills.', 'warning');
        }

        // A new default income applies to future paychecks that still carry
        // the old default; per-paycheck overrides are preserved.
        if ($income !== (string) $old['default_income'] && !$scheduleChanged) {
            $pdo->prepare(
                'UPDATE paychecks SET amount = ? WHERE user_id = ? AND pay_date >= ? AND amount = ?'
            )->execute([$income, $userId, $todayStr, (string) $old['default_income']]);
        }

        flash('Settings saved.');
        redirect('/settings');
    }

    /** @param array<string, mixed> $user */
    private function savePassword(array $user): void
    {
        $current = (string) ($_POST['currentPassword'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $verify = (string) ($_POST['verifyPassword'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->fail('Current password is incorrect.');
        }
        if ($new !== $verify) {
            $this->fail('The new passwords do not match.');
        }
        if (!password_meets_policy($new)) {
            $this->fail('Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.');
        }

        Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_ARGON2ID), (int) $user['id']]);

        flash('Password updated.');
        redirect('/settings');
    }

    private function fail(string $message): never
    {
        flash($message, 'error');
        redirect('/settings');
    }
}
