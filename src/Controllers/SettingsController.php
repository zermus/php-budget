<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\LoginThrottle;
use App\Mailer;
use App\Secrets;
use App\Services\BudgetLock;
use App\Services\ScheduleService;
use App\Services\ScheduleTransitionService;
use App\View;
use DateTimeImmutable;

final class SettingsController
{
    private const SCHEDULE_FIELDS = ['scheduleType', 'anchorDate', 'semiDay1', 'semiDay2', 'monthDay',
        'defaultIncome', 'windowDays', 'reminderLeadDays'];
    private const MAIL_FIELDS = ['mailTransport', 'mailFrom', 'mailFromName', 'smtpHost', 'smtpPort',
        'smtpEncryption'];

    public function form(): void
    {
        $user = Auth::requireLogin();
        $settings = Auth::isAdmin() ? (ScheduleService::userSettings(Auth::dataUserId()) ?? []) : [];

        if (Auth::canManageAccount() && Auth::isOwner()) {
            $settings = $this->migrateMailPassword($settings, Auth::dataUserId());
        }
        $old = $_SESSION['settings_draft'] ?? [];
        unset($_SESSION['settings_draft']);
        $effective = Mailer::settingsFor($settings);
        $settings['smtp_password_unreadable'] = $effective['password_unreadable'];
        $settings['smtp_password_saved'] = !empty($settings['smtp_password']);
        $settings['effective_transport'] = $effective['transport'];
        $settings['effective_encryption'] = $effective['encryption'];
        unset($settings['smtp_password'], $user['password_hash']);

        echo View::render('settings/form', [
            'title'    => 'Settings',
            'user'     => $user,
            'settings' => $settings,
            'old'      => $old,
        ]);
    }

    public function save(): void
    {
        $user = Auth::requireLogin();
        Csrf::require();

        // Anyone may change their own password; the rest is admin-only.
        $action = input_string('action');
        if ($action === 'password') {
            $this->savePassword($user);

            return;
        }

        Auth::requireAdmin();
        if ($action === 'reminders') {
            $receive = input_checkbox('receiveReminders');
            if ($receive === null) {
                $this->fail('Invalid reminder preference.');
            }
            Database::pdo()->prepare('UPDATE users SET receive_reminders = ? WHERE id = ?')
                ->execute([(int) $receive, (int) $user['id']]);
            flash('Your reminder preference was saved.');
            redirect('/settings');
        }
        if ($action !== 'settings') {
            $this->fail('Invalid settings form.');
        }
        $this->saveSettings();
    }

    /**
     * Send a test email to the signed-in admin using the mail settings
     * currently in the form, so a change can be verified before it is saved.
     */
    public function testEmail(): void
    {
        $user = Auth::requireAdmin();
        Csrf::require();

        $stored = ScheduleService::userSettings(Auth::dataUserId()) ?? [];
        $owner = Auth::isOwner();
        $_SESSION['settings_draft'] = array_filter(
            input_draft($owner ? array_merge(self::SCHEDULE_FIELDS, self::MAIL_FIELDS) : self::SCHEDULE_FIELDS),
            static fn (string $value): bool => strlen($value) <= 1024
        );
        try {
            $profile = $owner ? array_replace($stored, $this->mailFields($stored)) : $stored;
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        $config = Mailer::settingsFor($profile);
        $target = (string) $config['transport'];

        $ok = Mailer::send(
            (string) $user['email'],
            (string) $user['email'],
            'php-budget test email',
            "This is a test message from php-budget.\n\n"
            . 'Sent via: ' . $target . "\n"
            . "If you received it, bill reminders will reach you at this address.\n",
            $config
        );

        if ($ok) {
            flash('Test email sent to ' . $user['email'] . ' via ' . $target . '.');
        } else {
            flash('Test email failed: ' . (Mailer::lastError() ?? 'unknown error'), 'error');
        }

        redirect('/settings');
    }

    /** @param array<string, mixed> $settings */
    private function migrateMailPassword(array $settings, int $userId): array
    {
        $stored = $settings['smtp_password'] ?? null;
        if ($stored !== null && strlen($stored) <= 734 && !Secrets::isEncrypted($stored) && Secrets::available()) {
            try {
                $encrypted = Secrets::encrypt($stored);
                Database::pdo()->prepare(
                    'UPDATE user_settings SET smtp_password = ? WHERE user_id = ? AND BINARY smtp_password = BINARY ?'
                )->execute([$encrypted, $userId, $stored]);
                return ScheduleService::userSettings($userId) ?? [];
            } catch (\Throwable $e) {
                // Recovery controls must remain accessible even if migration fails.
            }
        }
        return $settings;
    }

    /** @param array<string, mixed> $stored @return array<string, mixed> */
    private function mailFields(array $stored): array
    {
        if (!input_strings_valid([...self::MAIL_FIELDS, 'smtpUsername', 'smtpPassword']) || input_checkbox('smtpPasswordClear') === null) {
            throw new \InvalidArgumentException('Invalid email settings.');
        }
        $fallback = Mailer::settingsFor(null);
        $transport = input_string('mailTransport');
        $encryption = input_string('smtpEncryption');
        if (!in_array($transport, ['smtp', 'mail', 'log'], true)
            || !in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new \InvalidArgumentException('Choose a valid mail transport and encryption.');
        }
        foreach (['mailFrom' => ['From address', 190], 'mailFromName' => ['From name', 190],
                  'smtpUsername' => ['SMTP username', 190], 'smtpHost' => ['SMTP host', 255]] as $field => [$label, $limit]) {
            if (mb_strlen(input_raw_string($field)) > $limit) {
                throw new \InvalidArgumentException("$label must be at most $limit characters.");
            }
        }
        $host = input_string('smtpHost');
        if ($host !== '' && !Mailer::validSmtpHost($host)) {
            throw new \InvalidArgumentException('SMTP host must be a hostname or IP address (set the port separately).');
        }
        $port = input_string('smtpPort');
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new \InvalidArgumentException('SMTP port must be between 1 and 65535.');
        }
        $from = input_string('mailFrom');
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('The "from" address must be a valid email address.');
        }
        $username = input_raw_string('smtpUsername');
        $password = input_raw_string('smtpPassword');
        if (strlen($password) > 734) {
            throw new \InvalidArgumentException('SMTP password must be at most 734 bytes.');
        }
        $clear = input_checkbox('smtpPasswordClear');
        $server = true;
        foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'] as $field) {
            $server = $server && ($stored[$field] ?? null) === null;
        }
        $server = $server && $host === '' && $port === '' && $username === '' && $password === '' && !$clear
            && $encryption === (string) $fallback['encryption'];
        $mail = [
            'mail_transport' => self::unlessFallback($transport, $stored['mail_transport'] ?? null, (string) $fallback['transport']),
            'mail_from' => $from !== '' ? $from : null,
            'mail_from_name' => input_string('mailFromName') ?: null,
            'smtp_host' => $server ? null : ($host !== '' ? $host : '127.0.0.1'),
            'smtp_port' => $server ? null : (int) ($port !== '' ? $port : 25),
            'smtp_encryption' => $server ? null : $encryption,
            'smtp_username' => $server ? null : $username,
            'smtp_password' => null,
        ];
        if ($server) {
            return $mail;
        }
        $previous = Mailer::settingsFor($stored);
        $unchanged = Mailer::canonicalHost((string) $mail['smtp_host']) === Mailer::canonicalHost((string) $previous['host'])
            && $mail['smtp_port'] === (int) $previous['port']
            && $encryption === $previous['encryption'] && $username === $previous['username'];
        try {
            if ($clear) {
                $password = null;
            } elseif ($password !== '') {
                $password = Secrets::encrypt($password);
            } else {
                $password = $stored['smtp_password'] ?? null;
                if ($transport === 'smtp' && Secrets::isEncrypted($password) && Secrets::decrypt($password) === null) {
                    throw new \InvalidArgumentException('Replace or explicitly clear the unreadable SMTP password.');
                }
                if ($password !== null && !$unchanged) {
                    throw new \InvalidArgumentException('Connection details changed. Supply a replacement password or clear both username and password.');
                }
                if ($transport === 'smtp' && $password !== null && !Secrets::isEncrypted($password) && strlen($password) <= 734) {
                    $password = Secrets::encrypt($password);
                }
            }
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException('SMTP password encryption failed. Configure a valid app_key and supported OpenSSL before saving passwords.');
        }
        if ($transport === 'smtp' && $username !== '' && ($password === null || Secrets::decrypt($password) === '')) {
            throw new \InvalidArgumentException('Supply an SMTP password, or clear both username and password for an unauthenticated relay.');
        }
        $mail['smtp_password'] = $password;
        return $mail;
    }

    /**
     * NULL when the stored value is already NULL and the choice matches the
     * config.php fallback, so an untouched field keeps following config.php.
     */
    private static function unlessFallback(string $chosen, ?string $stored, string $fallback): ?string
    {
        return ($stored === null && $chosen === $fallback) ? null : $chosen;
    }

    private function saveSettings(): void
    {
        if (!input_strings_valid(self::SCHEDULE_FIELDS) || !ctype_digit(input_string('reminderLeadDays'))) {
            $this->fail('Invalid schedule or reminder settings.');
        }
        $userId = Auth::dataUserId();
        $owner = Auth::isOwner();
        $type = input_string('scheduleType');
        $anchorDate = null;
        $daysOfMonth = null;
        $dayOfMonth = null;

        switch ($type) {
            case 'weekly':
            case 'biweekly':
                $anchorDate = input_string('anchorDate');
                if (parse_form_date($anchorDate) === null) {
                    $this->fail('Choose an anchor pay date between 1970 and 2100.');
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

        $windowDays = input_int('windowDays');
        if ($windowDays < 14 || $windowDays > 365) {
            $this->fail('The dashboard window must be between 14 and 365 days.');
        }

        $today = new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');

        try {
            $scheduleChanged = BudgetLock::run($userId, function (\PDO $pdo) use (
                $userId, $owner, $type, $anchorDate, $daysOfMonth, $dayOfMonth, $income, $leadDays, $windowDays, $today, $todayStr
            ): bool {
                $pdo->prepare('INSERT IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
                $lock = $pdo->prepare('SELECT * FROM user_settings WHERE user_id = ? FOR UPDATE');
                $lock->execute([$userId]);
                $old = $lock->fetch();
                $mail = $owner ? $this->mailFields($old) : $old;
                $new = array_merge($old, [
                    'schedule_type' => $type,
                    'anchor_date' => $anchorDate !== '' ? $anchorDate : null,
                    'days_of_month' => $daysOfMonth,
                    'day_of_month' => $dayOfMonth,
                    'default_income' => $income,
                    'window_days' => $windowDays,
                ]);
                $scheduleChanged = $type !== (string) $old['schedule_type']
                    || ($anchorDate ?? '') !== (string) ($old['anchor_date'] ?? '')
                    || ($daysOfMonth ?? '') !== (string) ($old['days_of_month'] ?? '')
                    || ($dayOfMonth ?? 0) !== (int) ($old['day_of_month'] ?? 0);
                if ($scheduleChanged) {
                    $new['schedule_effective_date'] = $todayStr;
                }

                $pdo->prepare(
                    'UPDATE user_settings
                     SET schedule_type = ?, anchor_date = ?, days_of_month = ?, day_of_month = ?,
                         schedule_effective_date = ?, default_income = ?, reminder_lead_days = ?, window_days = ?,
                         mail_transport = ?, mail_from = ?, mail_from_name = ?,
                         smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?,
                         smtp_encryption = ?
                     WHERE user_id = ?'
                )->execute([
                    $type,
                    $anchorDate !== '' ? $anchorDate : null,
                    $daysOfMonth,
                    $dayOfMonth,
                    $new['schedule_effective_date'] ?? null,
                    $income,
                    $leadDays,
                    $windowDays,
                    $mail['mail_transport'],
                    $mail['mail_from'],
                    $mail['mail_from_name'],
                    $mail['smtp_host'],
                    $mail['smtp_port'],
                    $mail['smtp_username'],
                    $mail['smtp_password'],
                    $mail['smtp_encryption'],
                    $userId,
                ]);

                if ($scheduleChanged) {
                    ScheduleTransitionService::reconcile($pdo, $userId, $new, $today);
                }
                if ($income !== (string) $old['default_income']) {
                    $pdo->prepare(
                        'UPDATE paychecks SET amount = ? WHERE user_id = ? AND pay_date >= ? AND amount_overridden = 0'
                    )->execute([$income, $userId, $todayStr]);
                }

                return $scheduleChanged;
            });
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                $this->fail($e->getMessage());
            }
            throw $e;
        }

        if ($scheduleChanged) {
            flash('Schedule changed — upcoming schedule dates were updated in place. '
                . 'Paid and past history were preserved; customizations on removed dates were discarded for automatic reallocation.', 'warning');
        }

        flash('Settings saved.');
        redirect('/settings');
    }

    /** @param array<string, mixed> $user */
    private function savePassword(array $user): void
    {
        if (!input_strings_valid(['currentPassword', 'password', 'verifyPassword'])) {
            $this->fail('Invalid password form.');
        }
        $current = input_raw_string('currentPassword');
        $new = input_raw_string('password');
        $verify = input_raw_string('verifyPassword');
        $id = (int) $user['id'];
        if (LoginThrottle::confirmationBlocked($id)) {
            $this->fail('Too many password confirmation attempts. Please wait 15 minutes.');
        }
        $valid = password_length_valid($current) && password_verify($current, (string) $user['password_hash']);
        LoginThrottle::recordConfirmation($id, $valid);
        if (!$valid) {
            $this->fail('Current password is incorrect.');
        }
        if (!password_length_valid($new) || !password_length_valid($verify)) {
            $this->fail('Passwords must be at most ' . PASSWORD_MAX_BYTES . ' bytes.');
        }
        if ($new !== $verify) {
            $this->fail('The new passwords do not match.');
        }
        if (!password_meets_policy($new)) {
            $this->fail('Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.');
        }

        $hash = password_hash($new, PASSWORD_ARGON2ID);
        Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, (int) $user['id']]);
        Auth::passwordChanged($hash);

        flash('Password updated. Any other devices signed in to this account were signed out.');
        redirect('/settings');
    }

    private function fail(string $message): never
    {
        flash($message, 'error');
        redirect('/settings');
    }
}
