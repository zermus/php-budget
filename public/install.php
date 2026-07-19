<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Csrf;
use App\Installer\Installer;
use App\Installer\Migrator;
use App\Installer\Seeder;
use App\View;

$installer = new Installer();

try {
    $pdo = $installer->connect();
} catch (PDOException $e) {
    error_log('[php-budget] Installer DB connection failed: ' . $e->getMessage());
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

// ---------------------------------------------------------------- POST --

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = input_string('action');

    if ($action === 'upgrade' && $version > 0 && $version < App::SCHEMA_VERSION) {
        $applied = $migrator->migrate();

        echo View::render('install/upgraded', [
            'title'   => 'Upgrade Complete',
            'applied' => $applied,
        ]);
        exit;
    }

    if ($action === 'install' && !$hasUser) {
        if ($version < App::SCHEMA_VERSION) {
            $migrator->migrate();
        }

        $userId = null;
        $error = $installer->createUser(
            $pdo,
            input_string('email'),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['verifyPassword'] ?? ''),
            $userId
        );

        if ($error === null) {
            if (!empty($_POST['seedBudget']) && $userId !== null) {
                Seeder::seedStarterBudget($pdo, $userId);
            }

            echo View::render('install/installed', [
                'title'    => 'Installation Complete',
                'seeded'   => !empty($_POST['seedBudget']),
                'cronPath' => realpath(APP_ROOT . '/bin/send_reminders.php') ?: APP_ROOT . '/bin/send_reminders.php',
            ]);
            exit;
        }

        echo View::render('install/form', [
            'title' => 'Install Budget App',
            'error' => $error,
            'old'   => $_POST,
        ]);
        exit;
    }

    // Fall through to GET rendering for stale/invalid posts.
}

// ----------------------------------------------------------------- GET --

if ($version > 0 && $version < App::SCHEMA_VERSION) {
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
        'body'    => 'This Budget App installation (version ' . App::VERSION . ') is complete and '
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
