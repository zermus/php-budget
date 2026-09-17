<?php

declare(strict_types=1);

namespace App;

use PDOException;

/**
 * Failed-login throttling, stored in the login_attempts table.
 *
 * Three limits over a sliding window, so a guesser is slowed without
 * handing them an easy way to lock the real user out:
 *   - per email + IP: the tight limit a single attacker hits first
 *   - per IP:         stops one address spraying many accounts
 *   - per email:      a looser brake on distributed guessing
 *
 * Before the 0.6 schema upgrade the table doesn't exist yet; every method
 * then quietly does nothing so sign-in (needed to run the upgrade) works.
 */
final class LoginThrottle
{
    public const WINDOW_MINUTES = 15;

    private const MAX_PER_EMAIL_IP = 5;
    private const MAX_PER_IP = 20;
    private const MAX_PER_EMAIL = 50;

    public static function tooManyAttempts(string $email, string $ip): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT
                    SUM(email_hash = ? AND ip = ?) AS email_ip,
                    SUM(ip = ?)                    AS ip_total,
                    SUM(email_hash = ?)            AS email_total
                 FROM login_attempts
                 WHERE attempted_at > NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE
                   AND (email_hash = ? OR ip = ?)'
            );
            $key = self::emailKey($email);
            $stmt->execute([$key, $ip, $ip, $key, $key, $ip]);
            $row = $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            return false;
        }

        return (int) ($row['email_ip'] ?? 0) >= self::MAX_PER_EMAIL_IP
            || (int) ($row['ip_total'] ?? 0) >= self::MAX_PER_IP
            || (int) ($row['email_total'] ?? 0) >= self::MAX_PER_EMAIL;
    }

    public static function recordFailure(string $email, string $ip): void
    {
        try {
            $pdo = Database::pdo();
            $pdo->prepare('INSERT INTO login_attempts (email_hash, ip, attempted_at) VALUES (?, ?, NOW())')
                ->execute([self::emailKey($email), $ip]);
            // Keep the table small; nothing older than a day is ever consulted.
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        } catch (PDOException $e) {
            // Pre-upgrade schema: throttling is simply unavailable.
        }
    }

    /** Forget this email + IP's failures after a successful sign-in. */
    public static function clear(string $email, string $ip): void
    {
        try {
            Database::pdo()->prepare('DELETE FROM login_attempts WHERE email_hash = ? AND ip = ?')
                ->execute([self::emailKey($email), $ip]);
        } catch (PDOException $e) {
        }
    }

    /** Emails are stored hashed: the table is a counter, not an address book. */
    private static function emailKey(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
