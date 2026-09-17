<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 6,
    'description' => 'Login throttling; mail transport and encryption fall back to config.php again (0.6)',
    'up' => function (PDO $pdo, Migrator $m): void {
        if (!$m->tableExists('login_attempts')) {
            $pdo->exec(
                "CREATE TABLE login_attempts (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    email_hash CHAR(64) NOT NULL,
                    ip VARCHAR(45) NOT NULL,
                    attempted_at DATETIME NOT NULL,
                    KEY idx_login_email (email_hash, attempted_at),
                    KEY idx_login_ip (ip, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // 0.4 added these as NOT NULL DEFAULT 'smtp' / 'none'. A non-empty
        // value always beats config.php, so installs that kept a different
        // transport or STARTTLS/SSL in config.php were silently switched to
        // plain, unencrypted SMTP. NULL means "use config.php" again.
        $pdo->exec(
            'ALTER TABLE user_settings
             MODIFY COLUMN mail_transport VARCHAR(10) NULL DEFAULT NULL,
             MODIFY COLUMN smtp_encryption VARCHAR(10) NULL DEFAULT NULL'
        );

        // Rows where email was never configured in the app still carry only
        // those column defaults; put them back on the config.php fallback.
        // With no mail block in config.php the result is the same smtp/none.
        $pdo->exec(
            "UPDATE user_settings
             SET mail_transport = NULL, smtp_encryption = NULL
             WHERE mail_transport = 'smtp' AND smtp_encryption = 'none'
               AND mail_from IS NULL AND mail_from_name IS NULL AND smtp_port IS NULL
               AND smtp_username IS NULL AND smtp_password IS NULL"
        );
    },
];
