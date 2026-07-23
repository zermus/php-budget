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
     * Mail settings for an account: what the owner saved under Settings,
     * falling back per field to config.php for installs that predate 0.4
     * (or that prefer to keep mail in the config file).
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

        return [
            'transport'  => $pick($settings['mail_transport'] ?? null, 'mail.transport', 'smtp'),
            'from'       => $pick($settings['mail_from'] ?? null, 'mail.from', 'budget@localhost'),
            'from_name'  => $pick($settings['mail_from_name'] ?? null, 'mail.from_name', 'Budget App'),
            'host'       => $pick($settings['smtp_host'] ?? null, 'mail.smtp.host', '127.0.0.1'),
            'port'       => (int) ($settings['smtp_port'] ?? 0)
                ?: (int) App::config('mail.smtp.port', 25),
            'username'   => $pick($settings['smtp_username'] ?? null, 'mail.smtp.username', ''),
            'password'   => $pick($settings['smtp_password'] ?? null, 'mail.smtp.password', ''),
            'encryption' => $pick($settings['smtp_encryption'] ?? null, 'mail.smtp.encryption', 'none'),
            'log_path'   => App::config('mail.log_path', APP_ROOT . '/mail.log'),
        ];
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
        ?array $mailSettings = null
    ): bool {
        self::$lastError = null;
        $config = $mailSettings ?? self::settingsFor(null);
        $transport = (string) $config['transport'];

        if ($transport === 'log') {
            return self::sendToLog($to, $toName, $subject, $body, (string) $config['log_path']);
        }

        try {
            $mail = new PHPMailer(true);
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

            return $mail->send();
        } catch (MailException $e) {
            self::$lastError = trim($e->getMessage());
            error_log('[php-budget] Mail send failed to ' . $to . ': ' . $e->getMessage());

            return false;
        }
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

        return file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
