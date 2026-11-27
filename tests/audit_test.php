<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';

use App\App;
use App\Csrf;
use App\Mailer;
use App\Secrets;
use PHPMailer\PHPMailer\PHPMailer;
use App\Installer\Installer;
use App\Services\OccurrenceService;
use App\Services\ScheduleService;

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "OK: $label\n";
}

$_SESSION['csrf_token'] = 'test';
check(!Csrf::validate(['test']) && Csrf::validate('test'), 'CSRF rejects non-string tokens');
check(!password_meets_policy('Aa1!' . str_repeat('é', 511)), 'password ceiling is in bytes');
check(password_meets_policy('Aa1!' . str_repeat('é', 510)), 'password ceiling is inclusive');
check(!(new Installer())->setupAuthorized('short', 'short'), 'weak setup token is refused');

date_default_timezone_set('America/New_York');
$from = new DateTimeImmutable('2024-02-20');
$to = new DateTimeImmutable('2024-12-18');
foreach (['weekly', 'biweekly', 'monthly', 'semimonthly'] as $type) {
    $settings = ['schedule_type' => $type, 'anchor_date' => '2024-03-10',
        'day_of_month' => 31, 'days_of_month' => '[30,31]', 'schedule_effective_date' => '2024-03-08'];
    foreach (['2021-02-28', '2022-03-13', '2024-02-29', '2024-03-08', '2024-04-10'] as $anchor) {
        $bill = ['recurrence_type' => 'every_n_paychecks', 'recurrence_value' => json_encode(['n' => 3, 'anchor' => $anchor])];
        $sequence = array_column(ScheduleService::payDates($settings, new DateTimeImmutable($anchor), $to), 'date');
        $expected = array_values(array_filter(OccurrenceService::everyNth($sequence, $anchor, 3),
            static fn (string $date): bool => $date >= '2024-03-08'));
        check(OccurrenceService::dueDates($bill, $settings, $from, $to) === $expected, "$type phase from $anchor");
    }
}
foreach (['weekly', 'biweekly', 'monthly', 'semimonthly'] as $type) {
    $settings['schedule_type'] = $type;
    $bill['recurrence_value'] = '{"n":3,"anchor":"0001-01-01"}';
    check(OccurrenceService::dueDates($bill, $settings, $from, $to) !== [], "$type generates for a legacy anchor");
}

App::init(['base_url' => 'http://example.test']);
$proxy = ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_PROTO' => 'HTTPS'];
check(!secure_session_request($proxy), 'forwarded scheme is ignored without proxy trust');
App::init(['trusted_proxies' => ['192.0.2.1']]);
check(secure_session_request($proxy), 'configured proxy can signal HTTPS');
check(!secure_session_request(array_replace($proxy, ['HTTP_X_FORWARDED_PROTO' => 'https, http'])), 'ambiguous proxy metadata is rejected');
App::init(['base_url' => 'https://example.test']);
check(secure_session_request([]), 'HTTPS base URL selects secure cookies');
check(money_cents('1000.30') - money_cents('300.10') - money_cents('700.20') === 0, 'balanced paycheck has exactly zero cents remaining');

App::init(['app_key' => Secrets::generateKey()]);
$encrypted = Secrets::encrypt('synthetic-password');
App::init(['app_key' => Secrets::generateKey(), 'mail' => ['allowed_smtp_hosts' => ['127.0.0.1']]]);
$seen = [];
$fake = static function () use (&$seen): PHPMailer {
    return new class($seen) extends PHPMailer {
        public function __construct(private array &$seen) { parent::__construct(true); }
        public function send(): bool { $this->seen[] = $this->Host; return true; }
    };
};
$send = static fn (array $config): bool => Mailer::send('test@example.test', 'Test', 'Test', 'Test', $config, $fake);
check(!$send(Mailer::settingsFor(['smtp_host' => '127.0.0.1', 'smtp_password' => $encrypted])) && $seen === [], 'unreadable credentials are blocked before transport');
check(!$send(Mailer::settingsFor(['smtp_host' => 'relay.example.test'])) && $seen === [], 'operator policy is enforced before transport');
check($send(Mailer::settingsFor(['smtp_host' => '127.0.0.1'])) && $seen === ['127.0.0.1'], 'allowed local relay is passed to the fake transport');
check(!$send(['transport' => 'log', 'log_path' => APP_ROOT]) && str_contains(Mailer::lastError(), 'log'), 'log failure reports a useful error');
