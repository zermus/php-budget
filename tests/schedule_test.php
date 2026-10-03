<?php

declare(strict_types=1);

/**
 * CLI assertions for the pure pay-schedule engine. No database needed:
 *   php tests/schedule_test.php
 * Not shipped in releases.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/App.php';
require APP_ROOT . '/src/Installer/Migrator.php';
require APP_ROOT . '/src/Services/ScheduleService.php';
require APP_ROOT . '/src/Services/OccurrenceService.php';
require APP_ROOT . '/src/Services/ScheduleTransitionService.php';
require APP_ROOT . '/src/Installer/Installer.php';

use App\App;
use App\Installer\Installer;
use App\Installer\Migrator;
use App\Services\OccurrenceService;
use App\Services\ScheduleService;
use App\Services\ScheduleTransitionService;

$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "  ok: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

/** @param list<array{date:string,is_wave:bool}> $payDates */
function dates(array $payDates): array
{
    return array_column($payDates, 'date');
}

/** @param list<array{date:string,is_wave:bool}> $payDates */
function waves(array $payDates): array
{
    return array_column(
        array_values(array_filter($payDates, static fn (array $p): bool => $p['is_wave'])),
        'date'
    );
}

$d = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);

// --- Biweekly (the seeded schedule) -------------------------------------

echo "Biweekly, anchor 2026-07-22:\n";
$biweekly = ['schedule_type' => 'biweekly', 'anchor_date' => '2026-07-22'];
$seq = ScheduleService::payDates($biweekly, $d('2026-07-01'), $d('2027-01-31'));

check(dates($seq) === [
    '2026-07-08', '2026-07-22', '2026-08-05', '2026-08-19',
    '2026-09-02', '2026-09-16', '2026-09-30', '2026-10-14',
    '2026-10-28', '2026-11-11', '2026-11-25', '2026-12-09',
    '2026-12-23', '2027-01-06', '2027-01-20',
], 'pay-date sequence Jul 2026 – Jan 2027');

check(waves($seq) === ['2026-09-30'], 'September 2026 has three checks; only 9/30 is the wave check');

// Mid-month "today" must not shift in-month ordinals.
$late = ScheduleService::payDates($biweekly, $d('2026-09-20'), $d('2026-10-31'));
check(waves($late) === ['2026-09-30'], 'wave flag survives a mid-month window start');

// The series extends backward from the anchor too.
$early = ScheduleService::payDates($biweekly, $d('2026-01-01'), $d('2026-02-01'));
check(dates($early) === ['2026-01-07', '2026-01-21'], 'series extends backward from the anchor');

// --- Weekly: five checks in a month are never wave-flagged ---------------

echo "Weekly, anchor 2026-07-03:\n";
$weekly = ['schedule_type' => 'weekly', 'anchor_date' => '2026-07-03'];
$julyWeekly = ScheduleService::payDates($weekly, $d('2026-07-01'), $d('2026-07-31'));
check(count(dates($julyWeekly)) === 5, 'July 2026 has five Friday paychecks');
check(waves($julyWeekly) === [], 'weekly is never wave-flagged');

// --- Semimonthly ----------------------------------------------------------

echo "Semimonthly:\n";
$semi = ['schedule_type' => 'semimonthly', 'days_of_month' => '[1,15]'];
$semiDates = ScheduleService::payDates($semi, $d('2026-07-01'), $d('2026-09-30'));
check(dates($semiDates) === [
    '2026-07-01', '2026-07-15', '2026-08-01', '2026-08-15', '2026-09-01', '2026-09-15',
], 'days [1,15]');

$semi31 = ['schedule_type' => 'semimonthly', 'days_of_month' => '[15,31]'];
$semi31Dates = ScheduleService::payDates($semi31, $d('2026-09-01'), $d('2026-09-30'));
check(dates($semi31Dates) === ['2026-09-15', '2026-09-30'], 'day 31 clamps to Sep 30');
check(waves($semi31Dates) === [], 'semimonthly is never wave-flagged');

// --- Monthly with clamping -------------------------------------------------

echo "Monthly, day 31:\n";
$monthly = ['schedule_type' => 'monthly', 'day_of_month' => 31];
$feb27 = ScheduleService::payDates($monthly, $d('2027-02-01'), $d('2027-02-28'));
check(dates($feb27) === ['2027-02-28'], 'clamps to Feb 28 in a common year');

$feb28 = ScheduleService::payDates($monthly, $d('2028-02-01'), $d('2028-02-29'));
check(dates($feb28) === ['2028-02-29'], 'clamps to Feb 29 in a leap year');
check(waves(ScheduleService::payDates($monthly, $d('2026-07-01'), $d('2026-12-31'))) === [], 'monthly is never wave-flagged');

// --- every_n_paychecks phase math (the seeded A/B bills) --------------------

echo "every_n_paychecks phases:\n";
$seqDates = dates($seq);
$phaseA = OccurrenceService::everyNth($seqDates, '2026-07-22', 2);
$phaseB = OccurrenceService::everyNth($seqDates, '2026-08-05', 2);

check($phaseA === [
    '2026-07-22', '2026-08-19', '2026-09-16', '2026-10-14',
    '2026-11-11', '2026-12-09', '2027-01-06',
], 'phase A (anchor 7/22): alternating checks incl. 9/16');

check($phaseB === [
    '2026-08-05', '2026-09-02', '2026-09-30', '2026-10-28',
    '2026-11-25', '2026-12-23', '2027-01-20',
], 'phase B (anchor 8/5): alternating checks incl. the 9/30 wave check');

check(array_intersect($phaseA, $phaseB) === [], 'phases are disjoint');
check(!in_array('2026-07-08', array_merge($phaseA, $phaseB), true), 'no occurrences before a bill\'s anchor');

// Anchor that no longer lands on a pay date snaps to the nearest earlier one.
$snapped = OccurrenceService::everyNth($seqDates, '2026-07-20', 2);
check($snapped[0] === '2026-08-05', 'off-schedule anchor snaps to the 7/8 phase, first date >= anchor is 8/5');

// --- Bill due-date generation ----------------------------------------------

echo "Bill dueDates:\n";
$monthlyBill = [
    'recurrence_type'  => 'monthly_day',
    'recurrence_value' => '{"day":31}',
];
$due = OccurrenceService::dueDates($monthlyBill, $biweekly, $d('2027-01-01'), $d('2027-03-31'));
check($due === ['2027-01-31', '2027-02-28', '2027-03-31'], 'monthly_day 31 clamps per month');

$oneTime = [
    'recurrence_type'  => 'one_time',
    'recurrence_value' => '{"date":"2026-06-01"}',
];
$due = OccurrenceService::dueDates($oneTime, $biweekly, $d('2026-07-01'), $d('2026-10-01'));
check($due === ['2026-06-01'], 'past one_time date still generates (shows as overdue)');

$everyN = [
    'recurrence_type'  => 'every_n_paychecks',
    'recurrence_value' => '{"n":2,"anchor":"2026-08-05"}',
];
$due = OccurrenceService::dueDates($everyN, $biweekly, $d('2026-09-01'), $d('2026-10-31'));
check($due === ['2026-09-02', '2026-09-30', '2026-10-28'], 'every_n dueDates stay in phase across a window start');

// ---------------------------------------------------------------------------

// --- Schedule transition planning ------------------------------------------

echo "Schedule transitions:\n";
$oldDates = dates(ScheduleService::payDates($biweekly, $d('2026-10-03'), $d('2026-12-31')));
$newSchedule = ['schedule_type' => 'semimonthly', 'days_of_month' => '[15,31]'];
$newDates = dates(ScheduleService::payDates($newSchedule, $d('2026-10-03'), $d('2026-12-31')));
$plan = ScheduleTransitionService::datePlan($oldDates, $newDates);
check($plan['pairs'] === [
    ['old' => '2026-10-14', 'new' => '2026-10-15'],
    ['old' => '2026-10-28', 'new' => '2026-10-31'],
    ['old' => '2026-11-11', 'new' => '2026-11-15'],
    ['old' => '2026-11-25', 'new' => '2026-11-30'],
    ['old' => '2026-12-09', 'new' => '2026-12-15'],
    ['old' => '2026-12-23', 'new' => '2026-12-31'],
], 'biweekly rows map chronologically to semimonthly rows, including November 31 clamp');
check($plan['excess'] === [] && $plan['missing'] === [], 'equal-frequency transition has no destructive surplus');

$withExactMatch = ScheduleTransitionService::datePlan(
    ['2026-10-15', '2026-10-29', '2026-11-12'],
    ['2026-10-15', '2026-10-31']
);
check($withExactMatch === [
    'pairs' => [['old' => '2026-10-29', 'new' => '2026-10-31']],
    'excess' => ['2026-11-12'],
    'missing' => [],
], 'exact dates retain identity and only unmatched dates move');


$effective = $newSchedule + ['schedule_effective_date' => '2026-10-20'];
check(
    ScheduleService::generationStart($effective, $d('2026-10-20'))->format('Y-m-d') === '2026-10-20',
    'mid-month transition starts the new schedule today, not at month start'
);
check(
    dates(ScheduleService::payDates(
        $effective,
        ScheduleService::generationStart($effective, $d('2026-10-20')),
        $d('2026-11-30')
    )) === ['2026-10-31', '2026-11-15', '2026-11-30'],
    'effective boundary excludes retroactive semimonthly paychecks and clamps short months'
);

// --- First-owner setup authorization ---------------------------------------

echo "Installer authorization:\n";
$installer = new Installer();
check(!$installer->setupAuthorized('', ''), 'missing configured token fails closed');
check(!$installer->setupAuthorized('operator-secret', 'wrong'), 'incorrect setup token is rejected');
check($installer->setupAuthorized('operator-secret', 'operator-secret'), 'matching setup token is accepted');
check(!$installer->upgradeAuthorized(true, false, true, false), 'anonymous upgrades are rejected');
check($installer->upgradeAuthorized(true, true, true, true), 'authenticated administrator upgrades are accepted');
check(!$installer->upgradeAuthorized(true, true, true, false), 'authenticated non-administrator upgrades are rejected');
check($installer->upgradeAuthorized(true, true, false, false), 'authenticated pre-role upgrades remain accepted');

// --- Optional MySQL/MariaDB integration coverage ---------------------------

/** @return array{host:string,port:int,user:string,pass:string}|null */
function integrationConfig(): ?array
{
    $host = getenv('PHP_BUDGET_TEST_DB_HOST');
    $user = getenv('PHP_BUDGET_TEST_DB_USER');
    if ($host === false || $host === '' || $user === false || $user === '') {
        return null;
    }

    return [
        'host' => $host,
        'port' => (int) (getenv('PHP_BUDGET_TEST_DB_PORT') ?: 3306),
        'user' => $user,
        'pass' => (string) (getenv('PHP_BUDGET_TEST_DB_PASS') ?: ''),
    ];
}

/** @param array{host:string,port:int,user:string,pass:string} $config */
function serverPdo(array $config): PDO
{
    return new PDO(
        "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4",
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** @param array{host:string,port:int,user:string,pass:string} $config */
function databasePdo(array $config, string $database): PDO
{
    return new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function databaseName(string $suffix): string
{
    return 'php_budget_zc13_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_' . $suffix;
}

/** @param array{host:string,port:int,user:string,pass:string} $config */
function runDatabaseChecks(array $config): void
{
    echo "Database integration:\n";
    $server = serverPdo($config);
    $databases = [];

    try {
        $database = databaseName('main');
        $databases[] = $database;
        $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = databasePdo($config, $database);
        $migrator = new Migrator($pdo);
        $installer = new Installer();

        $userId = null;
        $error = $installer->installFirstOwner(
            $pdo,
            $migrator,
            'private-token',
            'wrong-token',
            'owner@example.test',
            'Valid1!Password',
            'Valid1!Password',
            $userId
        );
        check($error !== null && !$migrator->tableExists('settings'), 'wrong setup token creates neither schema nor owner');

        $error = $installer->installFirstOwner(
            $pdo,
            $migrator,
            'private-token',
            'private-token',
            'owner@example.test',
            'Valid1!Password',
            'Valid1!Password',
            $userId
        );
        check($error === null && $userId !== null, 'valid setup token migrates and creates the first owner');
        check(
            (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1
            && (int) $pdo->query('SELECT COUNT(*) FROM user_settings')->fetchColumn() === 1,
            'first owner and settings are committed atomically'
        );

        $secondId = null;
        $secondError = $installer->installFirstOwner(
            $pdo,
            $migrator,
            'private-token',
            'private-token',
            'second@example.test',
            'Valid1!Password',
            'Valid1!Password',
            $secondId
        );
        check($secondError !== null && $secondId === null, 'serialized owner re-check rejects a second claimant');

        if (function_exists('pcntl_fork')) {
            $concurrentDatabase = databaseName('concurrent');
            $databases[] = $concurrentDatabase;
            $server->exec("CREATE DATABASE `{$concurrentDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $children = [];
            foreach (['first', 'second'] as $name) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    $childPdo = databasePdo($config, $concurrentDatabase);
                    $childId = null;
                    $childError = (new Installer())->installFirstOwner(
                        $childPdo,
                        new Migrator($childPdo),
                        'private-token',
                        'private-token',
                        "{$name}@example.test",
                        'Valid1!Password',
                        'Valid1!Password',
                        $childId
                    );
                    exit($childError === null ? 0 : 2);
                }
                if ($pid < 0) {
                    throw new RuntimeException('Could not fork concurrent setup test.');
                }
                $children[] = $pid;
            }
            $successfulChildren = 0;
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                    $successfulChildren++;
                }
            }
            $concurrentPdo = databasePdo($config, $concurrentDatabase);
            check(
                $successfulChildren === 1
                && (int) $concurrentPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1
                && (int) $concurrentPdo->query('SELECT COUNT(*) FROM user_settings')->fetchColumn() === 1,
                'concurrent valid setup creates exactly one owner/settings pair'
            );
        } else {
            echo "  skip: concurrent setup check requires pcntl.\n";
        }

        $pdo->prepare(
            "INSERT INTO users (email, password_hash, role, owner_id) VALUES (?, ?, 'admin', NULL)"
        )->execute(['tenant@example.test', password_hash('Valid1!Password', PASSWORD_ARGON2ID)]);
        $tenantId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO user_settings
             (user_id, schedule_type, anchor_date, default_income, window_days)
             VALUES (?, 'biweekly', '2026-10-14', 1000.00, 90)"
        )->execute([$tenantId]);

        $otherId = null;
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, role, owner_id) VALUES (?, ?, 'admin', NULL)"
        )->execute(['other@example.test', password_hash('Valid1!Password', PASSWORD_ARGON2ID)]);
        $otherId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$otherId]);
        $pdo->prepare(
            'INSERT INTO paychecks (user_id, pay_date, amount, amount_overridden) VALUES (?, ?, ?, ?)'
        )->execute([$otherId, '2026-10-14', '777.00', 1]);
        $otherPaycheckId = (int) $pdo->lastInsertId();

        $paycheckRows = [
            ['2026-10-14', '1000.00', 0],
            ['2026-10-28', '1000.00', 1],
            ['2026-11-11', '1250.00', 1],
            ['2026-11-25', '1000.00', 0],
        ];
        $insertPaycheck = $pdo->prepare(
            'INSERT INTO paychecks (user_id, pay_date, amount, amount_overridden) VALUES (?, ?, ?, ?)'
        );
        $paycheckIds = [];
        foreach ($paycheckRows as [$date, $amount, $overridden]) {
            $insertPaycheck->execute([$tenantId, $date, $amount, $overridden]);
            $paycheckIds[$date] = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            "INSERT INTO bills (user_id, name, default_amount, recurrence_type, recurrence_value)
             VALUES (?, 'Alternating', 100.00, 'every_n_paychecks', ?)"
        )->execute([$tenantId, '{"n":2,"anchor":"2026-10-14"}']);
        $billId = (int) $pdo->lastInsertId();
        $insertOccurrence = $pdo->prepare(
            'INSERT INTO bill_occurrences (user_id, bill_id, due_date, amount, paid, skipped)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertOccurrence->execute([$tenantId, $billId, '2026-10-14', '100.00', 1, 0]);
        $paidOccurrenceId = (int) $pdo->lastInsertId();
        $insertOccurrence->execute([$tenantId, $billId, '2026-11-11', '80.00', 0, 1]);
        $editedOccurrenceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO allocations (user_id, paycheck_id, occurrence_id, amount) VALUES (?, ?, ?, ?)'
        )->execute([$tenantId, $paycheckIds['2026-10-14'], $paidOccurrenceId, '60.00']);

        $newSettings = [
            'schedule_type' => 'semimonthly',
            'days_of_month' => '[15,31]',
            'default_income' => '1100.00',
            'window_days' => 90,
            'schedule_effective_date' => '2026-10-03',
        ];
        $pdo->beginTransaction();
        ScheduleTransitionService::reconcile($pdo, $tenantId, $newSettings, new DateTimeImmutable('2026-10-03'));
        $pdo->commit();

        $paychecks = $pdo->query(
            "SELECT id, pay_date, amount, amount_overridden FROM paychecks
             WHERE user_id = {$tenantId} ORDER BY pay_date"
        )->fetchAll();
        $paychecksById = array_column($paychecks, null, 'id');
        check(
            ($paychecksById[$paycheckIds['2026-10-14']]['pay_date'] ?? null) === '2026-10-14'
            && ($paychecksById[$paycheckIds['2026-10-28']]['pay_date'] ?? null) === '2026-10-15'
            && ($paychecksById[$paycheckIds['2026-11-11']]['pay_date'] ?? null) === '2026-10-31',
            'paid checks stay fixed while other paycheck IDs map deterministically'
        );
        check(
            ($paychecksById[$paycheckIds['2026-10-28']]['amount'] ?? null) === '1000.00'
            && ($paychecksById[$paycheckIds['2026-11-11']]['amount'] ?? null) === '1250.00',
            'explicit equal-default and edited paycheck overrides survive'
        );
        check(
            (int) $pdo->query("SELECT COUNT(*) FROM allocations WHERE user_id = {$tenantId}")->fetchColumn() === 1
            && (int) $pdo->query("SELECT COUNT(*) FROM bill_occurrences WHERE id = {$paidOccurrenceId} AND paid = 1")->fetchColumn() === 1
            && (int) $pdo->query("SELECT COUNT(*) FROM bill_occurrences WHERE id = {$editedOccurrenceId} AND skipped = 1 AND amount = 80.00")->fetchColumn() === 1,
            'paid allocations, skips, occurrence IDs, and amount edits survive'
        );
        check(
            (int) $pdo->query("SELECT COUNT(*) FROM paychecks WHERE id = {$otherPaycheckId} AND pay_date = '2026-10-14'")->fetchColumn() === 1,
            'transition writes remain tenant-scoped'
        );
        check(
            (int) $pdo->query("SELECT COUNT(*) FROM paychecks WHERE user_id = {$tenantId} AND pay_date < '2026-10-03'")->fetchColumn() === 0,
            'transition creates no new rows before its effective boundary'
        );

        $before = $pdo->query("SELECT pay_date FROM paychecks WHERE user_id = {$tenantId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE user_settings SET schedule_type = 'monthly', day_of_month = 1 WHERE user_id = ?")
                ->execute([$tenantId]);
            ScheduleTransitionService::reconcile(
                $pdo,
                $tenantId,
                ['schedule_type' => 'monthly', 'day_of_month' => 1, 'default_income' => '500.00', 'window_days' => 90],
                new DateTimeImmutable('2026-10-03')
            );
            throw new RuntimeException('Injected transition failure.');
        } catch (RuntimeException) {
            $pdo->rollBack();
        }
        $after = $pdo->query("SELECT pay_date FROM paychecks WHERE user_id = {$tenantId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        check(
            $after === $before
            && $pdo->query("SELECT schedule_type FROM user_settings WHERE user_id = {$tenantId}")->fetchColumn() === 'biweekly',
            'injected failure rolls back settings and every reconciliation write'
        );

        $legacyDatabase = databaseName('legacy');
        $databases[] = $legacyDatabase;
        $server->exec("CREATE DATABASE `{$legacyDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $legacy = databasePdo($config, $legacyDatabase);
        $legacyMigrator = new Migrator($legacy);
        $legacyMigrator->migrate();
        $legacy->exec("UPDATE settings SET value = '6' WHERE name = 'schema_version'");
        $legacy->exec('ALTER TABLE paychecks DROP COLUMN amount_overridden');
        $legacy->exec('ALTER TABLE user_settings DROP COLUMN schedule_effective_date');
        $legacy->prepare(
            "INSERT INTO users (email, password_hash, role, owner_id) VALUES (?, ?, 'admin', NULL)"
        )->execute(['legacy@example.test', password_hash('Valid1!Password', PASSWORD_ARGON2ID)]);
        $legacyUserId = (int) $legacy->lastInsertId();
        $legacy->prepare('INSERT INTO user_settings (user_id, default_income) VALUES (?, 1000.00)')->execute([$legacyUserId]);
        $legacy->prepare('INSERT INTO paychecks (user_id, pay_date, amount) VALUES (?, ?, ?)')->execute([$legacyUserId, '2026-10-15', '1200.00']);
        $legacyMigrator->migrate();
        check(
            (int) $legacy->query('SELECT amount_overridden FROM paychecks')->fetchColumn() === 1
            && $legacyMigrator->currentVersion() === 7,
            'v7 migration marks identifiable legacy overrides and reaches schema version 7'
        );
    } finally {
        foreach (array_reverse($databases) as $database) {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    }
}

$config = integrationConfig();
if (!extension_loaded('pdo_mysql')) {
    echo "Database integration: SKIPPED (pdo_mysql is unavailable).\n";
} elseif ($config === null) {
    echo "Database integration: SKIPPED (set PHP_BUDGET_TEST_DB_HOST and PHP_BUDGET_TEST_DB_USER).\n";
} else {
    runDatabaseChecks($config);
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
