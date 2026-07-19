<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 1,
    'description' => 'Baseline schema (0.1)',
    'up' => function (PDO $pdo, Migrator $m): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                value TEXT NOT NULL,
                UNIQUE KEY uq_settings_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_settings (
                user_id INT NOT NULL PRIMARY KEY,
                schedule_type ENUM('weekly','biweekly','semimonthly','monthly') NOT NULL DEFAULT 'biweekly',
                anchor_date DATE NULL,
                days_of_month VARCHAR(64) NULL,
                day_of_month TINYINT UNSIGNED NULL,
                default_income DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                reminder_lead_days TINYINT UNSIGNED NOT NULL DEFAULT 1,
                smtp_host VARCHAR(255) NULL,
                CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS bills (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(190) NOT NULL,
                default_amount DECIMAL(10,2) NOT NULL,
                recurrence_type ENUM('monthly_day','every_n_paychecks','one_time') NOT NULL,
                recurrence_value VARCHAR(255) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                KEY idx_bills_user_active (user_id, active),
                CONSTRAINT fk_bills_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS paychecks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                pay_date DATE NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                is_wave TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY uq_paychecks_user_date (user_id, pay_date),
                CONSTRAINT fk_paychecks_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS bill_occurrences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                bill_id INT NOT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                paid TINYINT(1) NOT NULL DEFAULT 0,
                paid_at DATETIME NULL,
                paid_source ENUM('manual','sync') NULL,
                skipped TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY uq_occurrence_bill_date (bill_id, due_date),
                KEY idx_occ_user_due (user_id, due_date),
                KEY idx_occ_user_paid_due (user_id, paid, due_date),
                CONSTRAINT fk_occ_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_occ_bill FOREIGN KEY (bill_id)
                    REFERENCES bills (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS allocations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                paycheck_id INT NOT NULL,
                occurrence_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                UNIQUE KEY uq_alloc_paycheck_occ (paycheck_id, occurrence_id),
                KEY idx_alloc_user (user_id),
                KEY idx_alloc_occ (occurrence_id),
                CONSTRAINT fk_alloc_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_alloc_paycheck FOREIGN KEY (paycheck_id)
                    REFERENCES paychecks (id) ON DELETE CASCADE,
                CONSTRAINT fk_alloc_occ FOREIGN KEY (occurrence_id)
                    REFERENCES bill_occurrences (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
