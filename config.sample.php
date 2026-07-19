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

    // All dates (pay dates, due dates, "today") use this timezone.
    'timezone' => 'America/New_York',

    'mail' => [
        'from'      => 'budget@your.website.com',
        'from_name' => 'Budget App',

        // 'smtp' = SMTP relay (default: local relay on 127.0.0.1:25),
        // 'mail' = PHP mail(), 'log' = write to a file (for testing).
        'transport' => 'smtp',

        // Only used when transport is 'smtp'. A user can override the host
        // per account in Settings (host or host:port, no auth) for reminders.
        'smtp' => [
            'host'       => '127.0.0.1',
            'port'       => 25,
            'username'   => '',
            'password'   => '',
            'encryption' => 'none',   // 'tls', 'ssl', or 'none'
        ],

        // Only used when transport is 'log'.
        'log_path' => __DIR__ . '/mail.log',
    ],
];
