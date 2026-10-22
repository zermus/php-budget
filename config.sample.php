<?php

/**
 * php-budget configuration.
 *
 * Copy this file to config.php (in the same directory) and fill in your values.
 * config.php is never overwritten by upgrades.
 */

return [

    // MySQL / MariaDB connection.
    'db' => [
        'host' => 'localhost',
        'name' => 'budgetdbname',
        'user' => 'budgetdbuser',
        'pass' => 'budgetdbpassword',
    ],

    // Public URL of the app, with trailing slash.
    // Flat deploy (extracted into the web root):  https://your.website.com/budget/
    // public/ deploy (document root = public/):   https://budget.your.website.com/
    'base_url' => 'https://your.website.com/budget/',

    // Authorizes the one-time creation of the first account owner. Generate a
    // random value, keep it private, and remove this entry after installation:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'setup_token' => '',

    // All dates (pay dates, due dates, "today") use this timezone.
    'timezone' => 'America/New_York',

    // Key for encrypting secrets stored in the database (the SMTP password).
    // Generate one with:
    //   php -r "echo 'base64:' . base64_encode(random_bytes(32)), PHP_EOL;"
    // Keep a copy with your database backups — a saved SMTP password can't
    // be read without it. New password writes require this valid 32-byte key
    // and OpenSSL AES-256-GCM support; plaintext storage is not supported.
    // Legacy plaintext migrates on Settings access. If the key is lost, restore
    // it or replace the password; clearing both username/password removes auth.
    'app_key' => '',

    // Email is configured in the app: sign in as the administrator and use
    // Settings -> Email (transport, from address, SMTP host/port/auth/
    // encryption), with a "Send test email" button to verify it.
    //
    // Server SMTP is used only when ALL account SMTP fields are NULL. Any
    // account override selects an account-only profile with neutral defaults,
    // never server credentials. Old mixed profiles may need reconfiguration.
    // Transport/from fields still fall back individually. New installs can
    // delete this optional block entirely (log_path remains server-managed).
    'mail' => [
        // Where 'log' transport writes. Handy for testing without a relay.
        'log_path' => __DIR__ . '/mail.log',
    ],
];
