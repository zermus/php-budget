<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Mailer;
use App\Secrets;
use App\Services\ScheduleService;
use App\Services\ScheduleTransitionService;
use App\View;
use DateTimeImmutable;

final class SettingsController
{
    public function form(): void
    {
        $user = Auth::requireLogin();
        $settings = Auth::isAdmin() ? (ScheduleService::userSettings(Auth::dataUserId()) ?? []) : [];

        $settings = $this->migrateMailPassword($settings, Auth::dataUserId());

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

        // Anyone may change their own password; the rest is admin-only.
        $action = input_string('action');
        if ($action === 'password') {
            $this->savePassword($user);

            return;
        }

        Auth::requireAdmin();
        $this->saveSettings($user);
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
        try {
            $posted = $this->mailFields($stored);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        $config = Mailer::settingsFor(array_merge($stored, $posted));

        $target = $config['transport'] === 'smtp'
            ? sprintf('SMTP %s:%d (%s)', $config['host'], $config['port'], $config['encryption'])
            : (string) $config['transport'];

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
        if ($stored !== null && !Secrets::isEncrypted($stored) && Secrets::available()) {
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
        $fallback = Mailer::settingsFor(null);
        $transport = input_string('mailTransport');
        $encryption = input_string('smtpEncryption');
        if (!in_array($transport, ['smtp', 'mail', 'log'], true)
            || !in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new \InvalidArgumentException('Choose a valid mail transport and encryption.');
        }
        $host = input_string('smtpHost');
        if ($host !== '' && !self::validSmtpHost($host)) {
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
        $username = input_string('smtpUsername');
        $password = $_POST['smtpPassword'] ?? '';
        if (!is_string($password)) {
            throw new \InvalidArgumentException('Enter a valid SMTP password.');
        }
        $clear = !empty($_POST['smtpPasswordClear']);
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
        $unchanged = self::canonicalHost((string) $mail['smtp_host']) === self::canonicalHost((string) $previous['host'])
            && $mail['smtp_port'] === (int) $previous['port']
            && $encryption === $previous['encryption'] && $username === $previous['username'];
        try {
            if ($clear) {
                $password = null;
            } elseif ($password !== '') {
                $password = Secrets::encrypt($password);
            } else {
                $password = $stored['smtp_password'] ?? null;
                if (Secrets::isEncrypted($password) && Secrets::decrypt($password) === null) {
                    throw new \InvalidArgumentException('Replace or explicitly clear the unreadable SMTP password.');
                }
                if ($password !== null && !$unchanged) {
                    throw new \InvalidArgumentException('Connection details changed. Supply a replacement password or clear both username and password.');
                }
                if ($password !== null && !Secrets::isEncrypted($password)) {
                    $password = Secrets::encrypt($password);
                }
            }
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException('SMTP password encryption failed. Configure a valid app_key and supported OpenSSL before saving passwords.');
        }
        if ($username !== '' && ($password === null || Secrets::decrypt($password) === '')) {
            throw new \InvalidArgumentException('Supply an SMTP password, or clear both username and password for an unauthenticated relay.');
        }
        $mail['smtp_password'] = $password;
        return $mail;
    }

    private static function canonicalHost(string $host): string
    {
        $host = trim($host, " []\t\n\r\0\x0B");
        $packed = @inet_pton($host);
        return $packed === false ? strtolower(rtrim($host, '.')) : bin2hex($packed);
    }

    private static function validSmtpHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        return filter_var(rtrim($host, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * NULL when the stored value is already NULL and the choice matches the
     * config.php fallback, so an untouched field keeps following config.php.
     */
    private static function unlessFallback(string $chosen, ?string $stored, string $fallback): ?string
    {
        return ($stored === null && $chosen === $fallback) ? null : $chosen;
    }

    /** @param array<string, mixed> $user */
    private function saveSettings(array $user): void
    {
        $userId = Auth::dataUserId();
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

        $windowDays = input_int('windowDays');
        if ($windowDays < 14 || $windowDays > 365) {
            $this->fail('The dashboard window must be between 14 and 365 days.');
        }

        $pdo = Database::pdo();
        $today = new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$userId]);
            $lock = $pdo->prepare('SELECT * FROM user_settings WHERE user_id = ? FOR UPDATE');
            $lock->execute([$userId]);
            $old = $lock->fetch();
            $mail = $this->mailFields($old);
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
            } elseif ($income !== (string) $old['default_income']) {
                $pdo->prepare(
                    'UPDATE paychecks SET amount = ? WHERE user_id = ? AND pay_date >= ? AND amount_overridden = 0'
                )->execute([$income, $userId, $todayStr]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \InvalidArgumentException) {
                $this->fail($e->getMessage());
            }
            throw $e;
        }

        if ($scheduleChanged) {
            flash('Schedule changed — upcoming schedule dates were updated in place. '
                . 'Paid bills, allocations, skips, and amount overrides were preserved.', 'warning');
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
