<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /**
     * Send a plain-text email. Returns true on success; failures are logged,
     * never displayed.
     *
     * $smtpHost, when given, forces SMTP to that relay ("host" or "host:port",
     * default port 25, no auth/encryption). This is the per-user smtp_host
     * override used by the reminder cron; null falls back to the global
     * mail.transport config.
     */
    public static function send(
        string $to,
        string $toName,
        string $subject,
        string $body,
        ?string $smtpHost = null
    ): bool {
        $transport = (string) App::config('mail.transport', 'smtp');

        if ($smtpHost === null && $transport === 'log') {
            return self::sendToLog($to, $toName, $subject, $body);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            if ($smtpHost !== null) {
                $host = $smtpHost;
                $port = 25;
                if (str_contains($smtpHost, ':')) {
                    [$host, $portPart] = explode(':', $smtpHost, 2);
                    $port = (int) $portPart ?: 25;
                }
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Port = $port;
            } elseif ($transport === 'smtp') {
                $mail->isSMTP();
                $mail->Host = (string) App::config('mail.smtp.host', '127.0.0.1');
                $mail->Port = (int) App::config('mail.smtp.port', 25);
                $username = (string) App::config('mail.smtp.username', '');
                if ($username !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = $username;
                    $mail->Password = (string) App::config('mail.smtp.password', '');
                }
                $encryption = (string) App::config('mail.smtp.encryption', 'none');
                if ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                }
            } else {
                $mail->isMail();
            }

            $mail->setFrom(
                (string) App::config('mail.from'),
                (string) App::config('mail.from_name', 'Budget App')
            );
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (MailException $e) {
            error_log('[php-budget] Mail send failed to ' . $to . ': ' . $e->getMessage());

            return false;
        }
    }

    private static function sendToLog(string $to, string $toName, string $subject, string $body): bool
    {
        $path = (string) App::config('mail.log_path', APP_ROOT . '/mail.log');

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
