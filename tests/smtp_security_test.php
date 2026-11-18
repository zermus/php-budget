<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('APP_ROOT', dirname(__DIR__));
foreach (['helpers', 'App', 'Secrets', 'Mailer', 'Controllers/SettingsController'] as $file) {
    require APP_ROOT . '/src/' . $file . '.php';
}

use App\App;
use App\Mailer;
use App\Secrets;
use App\Controllers\SettingsController;

require APP_ROOT . '/src/Database.php';
require APP_ROOT . '/src/Auth.php';
require APP_ROOT . '/src/Services/ScheduleService.php';

function testPdo(?string $database = null): PDO
{
    $dsn = 'mysql:host=' . getenv('PHP_BUDGET_TEST_DB_HOST') . ';port='
        . (getenv('PHP_BUDGET_TEST_DB_PORT') ?: 3306) . ($database ? ';dbname=' . $database : '');
    return new PDO($dsn, getenv('PHP_BUDGET_TEST_DB_USER'), getenv('PHP_BUDGET_TEST_DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
if (($argv[1] ?? '') === '--rollback') {
    $pdo = testPdo($argv[2]);
    (new ReflectionProperty(\App\Database::class, 'pdo'))->setValue(null, $pdo);
    (new ReflectionProperty(\App\Auth::class, 'user'))->setValue(null, ['id' => (int) ($argv[3] ?? 1)]);
    $before = $pdo->query('SELECT * FROM user_settings')->fetchAll();
    register_shutdown_function(static function () use ($pdo, $before): void {
        echo !$pdo->inTransaction() && $before === $pdo->query('SELECT * FROM user_settings')->fetchAll() ? 'rollback-ok' : 'rollback-failed';
    });
    App::init(['app_key' => '']);
    $_POST = ['scheduleType' => 'monthly', 'monthDay' => '1', 'defaultIncome' => '100',
        'reminderLeadDays' => '1', 'windowDays' => '90', 'mailTransport' => 'smtp',
        'smtpHost' => 'changed.example', 'smtpPort' => '587', 'smtpEncryption' => 'tls',
        'smtpUsername' => 'synthetic-user', 'smtpPassword' => 'synthetic-replacement'];
    (new ReflectionMethod(SettingsController::class, 'saveSettings'))->invoke(new SettingsController(), []);
    exit(1);
}

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok: ' : 'FAIL: ') . $label . "\n";
    $failures += (int) !$ok;
}
function rejected(callable $call): bool
{
    try {
        $call();
        return false;
    } catch (InvalidArgumentException | RuntimeException $e) {
        return true;
    }
}
$config = ['app_key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'mail' => ['smtp' => [
    'host' => 'server.example', 'port' => 465, 'encryption' => 'ssl',
    'username' => 'synthetic-server-user', 'password' => 'synthetic-server-password',
]]];
App::init($config);
$parse = new ReflectionMethod(SettingsController::class, 'mailFields');
$controller = new SettingsController();
$fields = static fn (array $stored): array => $parse->invoke($controller, $stored);
$post = ['mailTransport' => 'smtp', 'smtpHost' => 'account.example', 'smtpPort' => '587',
    'smtpEncryption' => 'tls', 'smtpUsername' => 'synthetic-account-user', 'smtpPassword' => ''];
$stored = ['smtp_host' => 'account.example', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    'smtp_username' => 'synthetic-account-user', 'smtp_password' => Secrets::encrypt('synthetic-account-password')];
foreach (['smtpHost' => 'other.example', 'smtpPort' => '2525', 'smtpEncryption' => 'none', 'smtpUsername' => 'other-user'] as $field => $value) {
    $_POST = array_replace($post, [$field => $value]);
    check(rejected(fn () => $fields($stored)), "$field change requires replacement");
    $_POST['smtpPassword'] = 'synthetic-replacement';
    check(Secrets::decrypt($fields($stored)['smtp_password']) === 'synthetic-replacement', "$field explicit replacement accepted");
}
$_POST = $post;
check($fields($stored)['smtp_password'] === $stored['smtp_password'], 'unchanged profile retains ciphertext');
$_POST['smtpHost'] = ' ACCOUNT.EXAMPLE. ';
$_POST['smtpPort'] = '0587';
check($fields($stored)['smtp_password'] === $stored['smtp_password'], 'canonical equivalent profile retains password');
$_POST = array_replace($post, ['smtpUsername' => '', 'smtpPasswordClear' => '1']);
$cleared = $fields($stored);
check($cleared['smtp_password'] === null && Mailer::settingsFor($cleared)['username'] === '', 'explicit authentication removal');
check(Mailer::settingsFor($cleared)['password'] === '', 'cleared password does not inherit');
$_POST = array_replace($post, ['smtpPasswordClear' => '1']);
check(rejected(fn () => $fields($stored)), 'clear with username rejected');
foreach ([null, '', 'enc:v1:invalid'] as $password) {
    $partial = ['smtp_host' => 'account.example', 'smtp_password' => $password];
    $resolved = Mailer::settingsFor($partial);
    check($resolved['password'] === '' && $resolved['username'] === '' && $resolved['port'] === 25 && $resolved['encryption'] === 'none', 'partial profile uses neutral defaults');
}
check(Mailer::settingsFor(null)['password'] === 'synthetic-server-password', 'server-only profile resolves as a whole');
$allNull = array_fill_keys(array_keys($stored), null);
check(Mailer::settingsFor($allNull) === Mailer::settingsFor(null), 'all-null profile selects server');
foreach (array_keys($stored) as $field) {
    $partial = array_replace($allNull, [$field => $field === 'smtp_port' ? 25 : '']);
    check(Mailer::settingsFor($partial)['password'] === '', "$field override isolates account profile");
}
$_POST = ['mailTransport' => 'smtp', 'smtpEncryption' => 'ssl'];
check($fields($allNull)['smtp_password'] === null && $fields($allNull)['smtp_host'] === null, 'untouched server form never copies credentials');
$_POST['smtpHost'] = 'other.example';
check(Mailer::settingsFor($fields($allNull))['password'] === '', 'new account destination never receives server password');
$_POST = $post;
$unreadable = array_replace($stored, ['smtp_password' => 'enc:v1:invalid']);
check(rejected(fn () => $fields($unreadable)), 'unreadable ciphertext rejected');
$_POST['smtpPassword'] = 'synthetic-replacement';
check(Secrets::decrypt($fields($unreadable)['smtp_password']) === 'synthetic-replacement', 'unreadable ciphertext replaceable');
$_POST = array_replace($post, ['smtpUsername' => '', 'smtpPasswordClear' => '1']);
check($fields($unreadable)['smtp_password'] === null, 'unreadable ciphertext clearable');
foreach (['127.0.0.1', '::1', '[2001:db8::1]', 'relay', 'relay.example.'] as $host) {
    $_POST = array_replace($post, ['smtpHost' => $host, 'smtpPassword' => 'synthetic-replacement']);
    check(!rejected(fn () => $fields($stored)), 'supported host syntax');
}
foreach (['bad host', 'relay:25', 'https://relay', 'a;b', '[invalid]'] as $host) {
    $_POST = array_replace($post, ['smtpHost' => $host, 'smtpPassword' => 'synthetic-replacement']);
    check(rejected(fn () => $fields($stored)), 'invalid host rejected');
}
foreach (['0', '65536', '25junk', '-1'] as $port) {
    $_POST = array_replace($post, ['smtpPort' => $port]);
    check(rejected(fn () => $fields($stored)), 'invalid port rejected');
}
foreach (['mailFrom' => 190, 'mailFromName' => 190, 'smtpUsername' => 190, 'smtpHost' => 255] as $field => $limit) {
    $value = match ($field) {
        'mailFrom' => str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 59) . '.test',
        'smtpHost' => str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61) . '.',
        default => str_repeat('é', $limit),
    };
    $_POST = array_replace($post, [$field => $value, 'smtpPassword' => 'synthetic-replacement']);
    if ($field === 'smtpHost') {
        check(rejected(fn () => $fields($stored)), '255-character host retains existing DNS syntax validation');
        $_POST[$field] = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61);
        check(!rejected(fn () => $fields($stored)), 'largest valid DNS hostname accepted');
        $_POST[$field] = $value;
    } else {
        check(mb_strlen($value) === $limit && !rejected(fn () => $fields($stored)), "$field accepts Unicode character limit");
    }
    $_POST[$field] .= 'x';
    check(rejected(fn () => $fields($stored)), "$field rejects limit plus one");
}
$_POST = $post;
$legacy = array_replace($stored, ['smtp_password' => 'synthetic-legacy']);
check(Secrets::isEncrypted($fields($legacy)['smtp_password']), 'legacy retention encrypts');
check(Secrets::decrypt('synthetic-legacy') === 'synthetic-legacy', 'legacy reads preserved');
foreach (['', 'invalid', 'base64:' . base64_encode('short')] as $key) {
    App::init(array_replace($config, ['app_key' => $key]));
    check(rejected(fn () => Secrets::encrypt('synthetic')), 'missing or invalid key rejects encryption');
    check(rejected(fn () => $fields($legacy)), 'legacy retention fails safely without key');
}
App::init($config);
$reminders = file_get_contents(APP_ROOT . '/bin/send_reminders.php');
check(str_contains($reminders, 'Mailer::settingsFor($user)'), 'reminders use the tested profile resolver');

foreach (['openssl_encrypt', 'openssl_get_cipher_methods', 'openssl_decrypt'] as $disabled) {
    $code = 'require ' . var_export(APP_ROOT . '/src/App.php', true) . '; require ' . var_export(APP_ROOT . '/src/Secrets.php', true) . ';'
        . 'App\\App::init(["app_key"=>"base64:".base64_encode(str_repeat("k",32))]);'
        . 'try { App\\Secrets::encrypt("synthetic"); exit(1); } catch (RuntimeException $e) { exit(0); }';
    $proc = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($proc) === 0 && $out === '', 'unavailable OpenSSL fails closed: ' . $disabled);
}
// Fault injection is needed to exercise an otherwise unreachable OpenSSL failure.
$code = 'namespace App { function openssl_encrypt(...$args) { return false; } } namespace {'
    . 'require ' . var_export(APP_ROOT . '/src/App.php', true) . '; require ' . var_export(APP_ROOT . '/src/Secrets.php', true) . ';'
    . 'App\\App::init(["app_key"=>"base64:".base64_encode(str_repeat("k",32))]);'
    . 'try { App\\Secrets::encrypt("synthetic"); exit(1); } catch (RuntimeException $e) { exit(0); }}';
$proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($proc) === 0 && $out === '', 'encryption primitive failure fails closed');

if (!extension_loaded('pdo_mysql') || !getenv('PHP_BUDGET_TEST_DB_HOST') || !getenv('PHP_BUDGET_TEST_DB_USER')) {
    echo "Database migration CAS and rejected-save rollback: SKIPPED (pdo_mysql or isolated test database unavailable).\n";
} else {
    $server = null;
    $database = 'php_budget_smtp_test_' . bin2hex(random_bytes(6));
    try {
        $server = testPdo();
        $server->exec("CREATE DATABASE `$database`");
        $pdo = testPdo($database);
        $pdo->exec('CREATE TABLE user_settings (user_id INT PRIMARY KEY, smtp_host VARCHAR(255), smtp_port INT,
            smtp_encryption VARCHAR(16), smtp_username VARCHAR(255), smtp_password TEXT) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO user_settings VALUES (1, 'account.example', 587, 'tls', 'synthetic-user', 'synthetic-legacy')");
        (new ReflectionProperty(\App\Database::class, 'pdo'))->setValue(null, $pdo);
        $migrate = new ReflectionMethod(SettingsController::class, 'migrateMailPassword');
        $stale = $pdo->query('SELECT * FROM user_settings WHERE user_id = 1')->fetch();
        $pdo->exec("UPDATE user_settings SET smtp_password = 'SYNTHETIC-LEGACY' WHERE user_id = 1");
        $current = $migrate->invoke($controller, $stale, 1);
        check($current['smtp_password'] === 'SYNTHETIC-LEGACY', 'migration CAS preserves concurrent case-sensitive replacement and reloads');
        $current = $migrate->invoke($controller, $current, 1);
        check(Secrets::isEncrypted($current['smtp_password']) && Secrets::decrypt($current['smtp_password']) === 'SYNTHETIC-LEGACY', 'legacy migration encrypts');
        $before = $pdo->query('SELECT * FROM user_settings')->fetchAll();
        App::init([]);
        $migrate->invoke($controller, $stale, 1);
        check($before === $pdo->query('SELECT * FROM user_settings')->fetchAll(), 'unavailable migration leaves data intact');
        foreach ([1, 2] as $userId) {
            $proc = proc_open([PHP_BINARY, __FILE__, '--rollback', $database, (string) $userId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            check(proc_close($proc) === 0 && $out === 'rollback-ok' && $errors === '', 'rejected save rolls back and preserves all settings, user ' . $userId);
        }
    } catch (Throwable $e) {
        check(false, 'isolated database integration failed (details suppressed)');
    } finally {
        if ($server !== null) {
            $server->exec("DROP DATABASE IF EXISTS `$database`");
        }
    }
}
if (getenv('PHP_BUDGET_TEST_DISPOSABLE') === '1' && is_file(APP_ROOT . '/config.php')) {
    require_once APP_ROOT . '/src/Installer/Migrator.php';
    $pdo = testPdo(getenv('PHP_BUDGET_TEST_DB_NAME'));
    (new \App\Installer\Migrator($pdo))->migrate();
    $ids = [];
    try {
        foreach (['broken', 'later'] as $name) {
            $pdo->prepare("INSERT INTO users (email,password_hash,role) VALUES (?, 'test', 'admin')")->execute(["reminder-$name@example.test"]);
            $ids[] = $owner = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO user_settings (user_id,mail_transport,schedule_type,day_of_month) VALUES (?, 'log','monthly',1)")->execute([$owner]);
            $pdo->prepare("INSERT INTO bills (user_id,name,default_amount,recurrence_type,recurrence_value) VALUES (?, ?, 10, 'one_time', ?)")
                ->execute([$owner, "Reminder $name", json_encode(['date'=>date('Y-m-d')])]);
        }
        // A database trigger exercises a real refresh failure without a production test hook.
        $pdo->exec("CREATE TRIGGER reminder_fault BEFORE INSERT ON paychecks FOR EACH ROW BEGIN IF NEW.user_id = {$ids[0]} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic refresh failure'; END IF; END");
        $log = APP_ROOT . '/mail.log';
        $offset = is_file($log) ? filesize($log) : 0;
        $proc = proc_open([PHP_BINARY, APP_ROOT . '/bin/send_reminders.php'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 1 && str_contains($out, '1 budget(s) failed'), 'reminder failure counts budget and exits 1');
        check(str_contains($err, "owner {$ids[0]} failed:") && str_contains($err, "synthetic refresh failure"), 'reminder reports owner and refresh error');
        check(str_contains(substr(file_get_contents($log), $offset), 'reminder-later@example.test'), 'later owner reminder delivered through log transport');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS reminder_fault');
        foreach ($ids as $owner) {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$owner]);
        }
    }
} else {
    echo "Not verified: reminder isolation integration requires disposable configured database.\n";
}
echo "Not verified: actual SMTP relay delivery (log transport tested).\n";
exit($failures > 0 ? 1 : 0);
