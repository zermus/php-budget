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

    // Email is configured in the app: sign in as the administrator and use
    // Settings -> Email (transport, from address, SMTP host/port/auth/
    // encryption), with a "Send test email" button to verify it.
    //
    // The block below is an optional fallback, used only for fields left
    // blank in Settings. New installs can delete it entirely.
    'mail' => [
        // Where 'log' transport writes. Handy for testing without a relay.
        'log_path' => __DIR__ . '/mail.log',
    ],
];
