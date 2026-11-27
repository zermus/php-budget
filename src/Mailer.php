<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /** Connect/auth timeout for SMTP, so a dead relay can't hang a request. */
    private const SMTP_TIMEOUT = 10;

    private static ?string $lastError = null;

    /**
     * Why the last send() failed, for surfacing in the UI (the test-email
     * button, the reminder cron's stderr). Null after a successful send.
     */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Mail settings for an account. SMTP credentials and connection details
     * always come from one profile, never a mixture of account and server.
     *
     * @param array<string, mixed>|null $settings a user_settings row
     * @return array<string, mixed>
     */
    public static function settingsFor(?array $settings): array
    {
        $pick = static function (?string $value, string $configPath, mixed $default = null): mixed {
            $value = $value !== null ? trim($value) : '';

            return $value !== '' ? $value : App::config($configPath, $default);
        };

        $settings ??= [];

        $account = false;
        foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'] as $field) {
            $account = $account || ($settings[$field] ?? null) !== null;
        }
        $smtp = static fn (string $field, mixed $default): mixed => $account
            ? ($settings['smtp_' . $field] ?? $default)
            : App::config('mail.smtp.' . $field, $default);

        $storedPassword = $settings['smtp_password'] ?? null;
        $password = $account ? Secrets::decrypt($storedPassword) : App::config('mail.smtp.password', '');

        return [
            'transport'  => $pick($settings['mail_transport'] ?? null, 'mail.transport', 'smtp'),
            'from'       => $pick($settings['mail_from'] ?? null, 'mail.from', self::defaultFrom()),
            'from_name'  => $pick($settings['mail_from_name'] ?? null, 'mail.from_name', 'Budget App'),
            'host'       => $smtp('host', '127.0.0.1'),
            'port'       => (int) $smtp('port', 25) ?: 25,
            'username'   => $smtp('username', ''),
            'password'   => $password ?? '',
            'password_unreadable' => $account && $storedPassword !== null && $password === null,
            'encryption' => $smtp('encryption', 'none'),
            'log_path'   => App::config('mail.log_path', APP_ROOT . '/mail.log'),
        ];
    }

    /**
     * From address when none is configured. PHPMailer rejects a domain with
     * no dot or an IP literal, so the old "budget@localhost" default made
     * every send fail with "Invalid address"; use the app's own hostname
     * when it is a real domain.
     */
    private static function defaultFrom(): string
    {
        $host = (string) parse_url((string) App::config('base_url', ''), PHP_URL_HOST);
        if (str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return 'budget@' . $host;
        }

        return 'budget@localhost.localdomain';
    }

    /**
     * Send a plain-text email. Returns true on success; failures are logged
     * and exposed via lastError(), never displayed raw.
     *
     * @param array<string, mixed>|null $mailSettings from settingsFor(); null
     *                                                uses config.php alone
     */
    public static function send(
        string $to,
        string $toName,
        string $subject,
        string $body,
        ?array $mailSettings = null,
        ?callable $createMailer = null
    ): bool {
        self::$lastError = null;
        $config = $mailSettings ?? self::settingsFor(null);
        $transport = (string) $config['transport'];

        if ($transport === 'log') {
            return self::sendToLog($to, $toName, $subject, $body, (string) $config['log_path']);
        }

        if ($transport === 'smtp') {
            if (!empty($config['password_unreadable'])) {
                return self::fail('Saved SMTP password cannot be decrypted. Restore the key or replace the password.');
            }
            if (!self::hostAllowed((string) $config['host'])) {
                return self::fail('SMTP host is not allowed by server policy.');
            }
        }
        try {
            $mail = $createMailer ? $createMailer() : new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = self::SMTP_TIMEOUT;

            if ($transport === 'smtp') {
                $mail->isSMTP();
                $mail->Host = (string) $config['host'];
                $mail->Port = (int) $config['port'] ?: 25;

                $username = (string) $config['username'];
                if ($username !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = $username;
                    $mail->Password = (string) $config['password'];
                }

                $encryption = (string) $config['encryption'];
                if ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    // PHPMailer opportunistically STARTTLSes whenever a relay
                    // advertises it, which breaks plain local relays with
                    // broken or self-signed TLS. "None" must mean none.
                    $mail->SMTPAutoTLS = false;
                    $mail->SMTPSecure = '';
                }
            } else {
                $mail->isMail();
            }

            $mail->setFrom((string) $config['from'], (string) $config['from_name']);
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send() ?: self::fail('Mail delivery failed. Check the configured transport and sender.');
        } catch (MailException) {
            return self::fail('Mail delivery failed. Check the configured transport and sender.');
        }
    }

    private static function fail(string $message): bool
    {
        self::$lastError = $message;
        error_log('[php-budget] ' . $message);
        return false;
    }

    private static function hostAllowed(string $host): bool
    {
        $allowed = App::config('mail.allowed_smtp_hosts', []);
        if ($allowed === []) {
            return true;
        }
        if (!is_array($allowed) || !self::validSmtpHost($host)) {
            return false;
        }
        foreach ($allowed as $entry) {
            if (is_string($entry) && self::validSmtpHost($entry) && self::canonicalHost($entry) === self::canonicalHost($host)) {
                return true;
            }
        }
        return false;
    }

    public static function canonicalHost(string $host): string
    {
        $packed = @inet_pton(trim($host, '[]'));
        return $packed === false ? strtolower(rtrim($host, '.')) : bin2hex($packed);
    }

    public static function validSmtpHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        return filter_var(rtrim($host, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private static function sendToLog(
        string $to,
        string $toName,
        string $subject,
        string $body,
        string $path
    ): bool {
        $entry = sprintf(
            "=== %s ===\nTo: %s <%s>\nSubject: %s\n\n%s\n\n",
            date('c'),
            $toName,
            $to,
            $subject,
            $body
        );

        return @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === strlen($entry)
            ?: self::fail('Cannot write reminder mail log. Check the configured log path and permissions.');
    }
}
