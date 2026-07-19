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
 */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
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
    $source ??= $_POST;
    $value = $source[$key] ?? '';

    return is_string($value) ? trim($value) : '';
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

    return is_numeric($value) ? (int) $value : 0;
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

    if ($raw === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
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

/**
 * Server-side password policy, mirrored client-side in password.js.
 */
function password_meets_policy(string $password): bool
{
    return (bool) preg_match(
        '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/',
        $password
    );
}
