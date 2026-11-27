<?php

declare(strict_types=1);

use App\App;

/**
 * HTML-escape for template output.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Absolute URL for an app route, e.g. url('/bills') or url('/bills/edit?id=5').
 */
function url(string $path = '/'): string
{
    $base = rtrim((string) App::config('base_url', '/'), '/');

    return $base . '/' . ltrim($path, '/');
}

/**
 * URL for a static asset inside public/, e.g. asset('css/app.css').
 *
 * The app version is appended so browsers pick up new CSS/JS immediately
 * after an upgrade instead of serving a cached copy.
 */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/')) . '?v=' . rawurlencode(App::VERSION);
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Queue a one-shot message for the next rendered page.
 */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** @return list<array{message: string, type: string}> */
function flash_pull(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

/**
 * Send a JSON response and stop.
 *
 * @param array<string, mixed> $payload
 */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Read a trimmed string field from an input array (default $_POST).
 *
 * @param array<string, mixed>|null $source
 */
function input_string(string $key, ?array $source = null): string
{
    return trim(input_raw_string($key, $source));
}

function input_raw_string(string $key, ?array $source = null): string
{
    $value = ($source ?? $_POST)[$key] ?? '';

    return is_string($value) ? $value : '';
}

function input_strings_valid(array $keys, ?array $source = null): bool
{
    $source ??= $_POST;
    foreach ($keys as $key) {
        if (array_key_exists($key, $source) && !is_string($source[$key])) {
            return false;
        }
    }
    return true;
}

function input_draft(array $keys): array
{
    return array_filter(array_intersect_key($_POST, array_flip($keys)), 'is_string');
}

function input_checkbox(string $key): ?bool
{
    return match ($_POST[$key] ?? '0') {
        '1', 'on' => true,
        '0', '' => false,
        default => null,
    };
}

/**
 * Read an integer field from an input array (default $_POST); 0 when absent
 * or not numeric.
 *
 * @param array<string, mixed>|null $source
 */
function input_int(string $key, ?array $source = null): int
{
    $source ??= $_POST;
    $value = $source[$key] ?? null;

    return is_string($value) && ctype_digit($value) ? (int) $value : 0;
}

/**
 * Parse a money amount from user input ("1,234.56", "$500") into a
 * "1234.56" string suitable for a DECIMAL column, or null when invalid.
 * Negative amounts are rejected.
 */
function input_decimal(string $key, ?array $source = null): ?string
{
    $raw = input_string($key, $source);
    $raw = str_replace(['$', ',', ' '], '', $raw);

    if ($raw === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $raw) || (float) $raw > 99999999.99) {
        return null;
    }

    return number_format((float) $raw, 2, '.', '');
}

/**
 * Format a DECIMAL string for display: money('3200.00') => "3,200.00".
 */
function money(string|float|null $amount): string
{
    return number_format((float) ($amount ?? 0), 2);
}

/**
 * Parse a YYYY-MM-DD date string, or null when invalid.
 */
function parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

/**
 * "Mon 7/22" style short label for dashboard cards.
 */
function short_date(string $ymd): string
{
    $date = parse_date($ymd);

    return $date ? $date->format('D n/j/y') : $ymd;
}

/**
 * Human description of a bill's recurrence, e.g. "Every 2nd paycheck from 7/22/26".
 *
 * @param array<string, mixed> $bill bills table row
 */
function describe_recurrence(array $bill): string
{
    $value = json_decode((string) $bill['recurrence_value'], true);
    if (!is_array($value)) {
        return '—';
    }

    switch ((string) $bill['recurrence_type']) {
        case 'monthly_day':
            return 'Monthly on day ' . (int) ($value['day'] ?? 0);
        case 'every_n_paychecks':
            $n = (int) ($value['n'] ?? 0);
            $ordinal = match ($n) {
                1 => 'Every paycheck',
                2 => 'Every 2nd paycheck',
                3 => 'Every 3rd paycheck',
                default => "Every {$n}th paycheck",
            };

            return $ordinal . ' from ' . short_date((string) ($value['anchor'] ?? ''));
        case 'one_time':
            return 'One time on ' . short_date((string) ($value['date'] ?? ''));
    }

    return '—';
}

const PASSWORD_MAX_BYTES = 1024;

function password_length_valid(string $password): bool
{
    return strlen($password) <= PASSWORD_MAX_BYTES;
}

function parse_form_date(string $value): ?DateTimeImmutable
{
    return $value >= '1970-01-01' && $value <= '2100-12-31' ? parse_date($value) : null;
}

function money_cents(string $amount): int
{
    [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    return str_starts_with($amount, '-') ? -$cents : $cents;
}

/** Server-side password policy, mirrored client-side in password.js. */
function password_meets_policy(string $password): bool
{
    return password_length_valid($password) && (bool) preg_match(
        '/\A(?=.*\p{Ll})(?=.*\p{Lu})(?=.*[0-9])(?=.*[^\p{L}\p{N}]).{8,}\z/us',
        $password
    );
}

function secure_session_request(array $server): bool
{
    $configured = parse_url((string) App::config('base_url', '/'), PHP_URL_SCHEME);
    $https = strtolower((string) ($server['HTTPS'] ?? ''));
    $trusted = App::config('trusted_proxies', []);
    $remote = $server['REMOTE_ADDR'] ?? '';

    return strtolower((string) $configured) === 'https'
        || in_array($https, ['on', '1'], true)
        || ($remote !== '' && is_array($trusted) && in_array($remote, $trusted, true)
            && strcasecmp(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')), 'https') === 0);
}
