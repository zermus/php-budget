<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Auth;
use App\Csrf;
use App\Database;
use App\Installer\Installer;
use App\Installer\Migrator;
use App\Installer\Seeder;
use App\View;

$installer = new Installer();

$setupToken = App::config('setup_token', '');
$setupToken = is_string($setupToken) ? $setupToken : '';
$action = '';
$old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = input_string('action');
    if ($action === 'install') {
        $providedToken = input_raw_string('setupToken');
        $seed = input_checkbox('seedBudget');
        $old = input_draft(['email', 'seedBudget']);
        // Delay setup checks before touching the database, including when no DB is configured yet.
        usleep(500_000);
        $error = null;
        if (!$installer->setupAuthorized($setupToken, $providedToken)) {
            http_response_code(403);
            $error = 'The setup token is invalid or is not configured.';
        } elseif ($seed === null || !input_strings_valid(['email', 'password', 'verifyPassword'])) {
            http_response_code(400);
            $error = 'Invalid setup form.';
        }
        if ($error !== null) {
            echo View::render('install/form', ['title' => 'Install Budget App', 'error' => $error, 'old' => $old]);
            exit;
        }
    }
}

try {
    $pdo = $action === 'install' ? $installer->connect() : Database::pdo();
} catch (PDOException $e) {
    if ($action !== 'install' && (int) ($e->errorInfo[1] ?? 0) === 1049) {
        echo View::render('install/form', ['title' => 'Install Budget App', 'error' => null, 'old' => []]);
        exit;
    }
    error_log('[php-budget] Installer DB connection failed.');
    http_response_code(500);
    echo View::render('install/message', [
        'title'   => 'Install Budget App',
        'heading' => 'Database Connection Failed',
        'body'    => 'Could not connect to the database with the credentials in config.php. '
            . 'Check the db settings (host, name, user, pass) and that your database user may '
            . 'create the database, then reload this page.',
    ]);
    exit;
}

$migrator = new Migrator($pdo);
$version = $migrator->currentVersion();
$hasUser = $installer->firstUserExists($pdo, $migrator);
$needsUpgrade = $version > 0 && $version < App::SCHEMA_VERSION;

// Upgrading an existing install requires a signed-in administrator, so a
// stranger can't run migrations before the owner has taken a backup.
// Before 0.3 there were no roles and every account administered its own.
$authenticated = Auth::user() !== null;
$roleColumnExists = $needsUpgrade && $migrator->columnExists('users', 'role');
$canUpgrade = $installer->upgradeAuthorized(
    $needsUpgrade,
    $authenticated,
    $roleColumnExists,
    $authenticated && Auth::role() === 'admin'
);

// ---------------------------------------------------------------- POST --

if ($action === 'upgrade' && $canUpgrade) {
    $applied = $installer->upgrade($pdo, $migrator);
    echo View::render('install/upgraded', ['title' => 'Upgrade Complete', 'applied' => $applied]);
    exit;
}

if ($action === 'install' && !$hasUser) {
    $userId = null;
    $error = $installer->installFirstOwner(
        $pdo,
        $migrator,
        $setupToken,
        $providedToken,
        input_string('email'),
        input_raw_string('password'),
        input_raw_string('verifyPassword'),
        $userId
    );

    if ($error === null) {
        if ($seed && $userId !== null) {
            Seeder::seedStarterBudget($pdo, $userId);
        }

        echo View::render('install/installed', [
            'title'    => 'Installation Complete',
            'seeded'   => $seed,
            'cronPath' => realpath(APP_ROOT . '/bin/send_reminders.php') ?: APP_ROOT . '/bin/send_reminders.php',
        ]);
        exit;
    }

    echo View::render('install/form', [
        'title' => 'Install Budget App',
        'error' => $error,
        'old'   => $old,
    ]);
    exit;
}

// ----------------------------------------------------------------- GET --

if ($needsUpgrade && !$canUpgrade) {
    echo View::render('install/message', [
        'title'    => 'Upgrade Budget App',
        'heading'  => 'Sign In to Upgrade',
        'body'     => 'This installation needs a database upgrade. Sign in as an administrator, then open install.php again to run it.',
        'homeLink' => true,
    ]);
    exit;
}

if ($needsUpgrade) {
    echo View::render('install/upgrade', [
        'title'       => 'Upgrade Budget App',
        'fromVersion' => $version,
        'toVersion'   => App::SCHEMA_VERSION,
        'appVersion'  => App::VERSION,
    ]);
    exit;
}

if ($hasUser) {
    echo View::render('install/message', [
        'title'   => 'Budget App',
        'heading' => 'Already Installed',
        'body'    => 'This Budget App installation is complete and '
            . 'up to date. For security you may delete install.php from the public/ directory. '
            . 'It will refuse to reinstall either way.',
        'homeLink' => true,
    ]);
    exit;
}

echo View::render('install/form', [
    'title' => 'Install Budget App',
    'error' => null,
    'old'   => [],
]);
