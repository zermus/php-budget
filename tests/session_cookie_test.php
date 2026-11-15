<?php

declare(strict_types=1);

use App\Database;
use App\Installer\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
ob_start();
require dirname(__DIR__) . '/src/bootstrap.php';

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "  ok: $label\n";
}

function request(string $path, array &$cookies, ?array $post = null): array
{
    $url = rtrim(getenv('PHP_BUDGET_TEST_URL') ?: '', '/') . $path;
    if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?/~', $url)) {
        throw new RuntimeException('Not verified: PHP_BUDGET_TEST_URL must be a local HTTP server running this checkout.');
    }
    $headers = ['Cookie: ' . implode('; ', array_map(
        static fn ($key, $value) => "$key=$value", array_keys($cookies), $cookies
    ))];
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $body = file_get_contents($url, false, stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
    ]]));
    if ($body === false) {
        throw new RuntimeException('HTTP request failed');
    }
    $response = $http_response_header;
    preg_match('~\s(\d{3})\s~', $response[0], $status);
    foreach ($response as $header) {
        if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return [(int) $status[1], $body, $response];
}

function token(string $body): string
{
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $body, $match)) {
        throw new RuntimeException('Missing real CSRF token');
    }
    return $match[1];
}

function login(array &$cookies, string $email, string $password): array
{
    [, $body] = request('/login', $cookies);
    return request('/login', $cookies, ['email' => $email, 'password' => $password, 'csrf_token' => token($body)]);
}

function fixture(): array
{
    if (getenv('PHP_BUDGET_TEST_DISPOSABLE') !== '1') {
        throw new RuntimeException('Not verified: explicitly set PHP_BUDGET_TEST_DISPOSABLE=1 for a disposable database.');
    }
    $pdo = Database::pdo();
    (new Migrator($pdo))->migrate();
    $email = 'test@example.test';
    check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'database has no existing users');
    $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)')
        ->execute([$email, password_hash('dummy-password', PASSWORD_ARGON2ID), 'admin']);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (?)')->execute([$id]);
    return [$pdo, $id, $email];
}

function cookieChecks(array $headers): void
{
    $cookies = array_values(array_filter($headers, static fn ($h) => stripos($h, 'Set-Cookie:') === 0));
    check(count($cookies) === 1 && str_starts_with($cookies[0], 'Set-Cookie: PHPBUDGETSESSID='), 'only application cookie is issued');
    foreach (['path=/', 'HttpOnly', 'SameSite=Lax'] as $attribute) {
        check(stripos($cookies[0], $attribute) !== false, "cookie retains $attribute");
    }
    $secure = str_starts_with(getenv('PHP_BUDGET_TEST_URL') ?: '', 'https://');
    check((stripos($cookies[0], '; secure') !== false) === $secure, 'Secure matches real HTTP transport');
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

[$pdo, $id, $email] = fixture();
try {
    // A sibling's authenticated-looking state must never become budget state.
    session_name('PHPSESSID');
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $siblingId = session_id();
    $_SESSION = ['sibling_marker' => 'unchanged', 'user_id' => $id,
        'password_fp' => hash('sha256', $pdo->query('SELECT password_hash FROM users')->fetchColumn())];
    session_write_close();
    $cookies = ['PHPSESSID' => $siblingId];
    [$status, $body, $headers] = request('/login', $cookies);
    check($status === 200 && str_contains($body, 'name="password"'), 'sibling authentication is not adopted');
    cookieChecks($headers);
    check($cookies['PHPSESSID'] === $siblingId && $cookies['PHPBUDGETSESSID'] !== $siblingId, 'independent session identifiers');
    $installerCookies = ['PHPSESSID' => $siblingId];
    [$status, , $headers] = request('/install.php', $installerCookies);
    check($status === 200, 'installer responds');
    cookieChecks($headers);
    $anonymousId = $cookies['PHPBUDGETSESSID'];
    check(login($cookies, $email, 'dummy-password')[0] === 302, 'normal login succeeds');
    check($cookies['PHPBUDGETSESSID'] !== $anonymousId, 'login rotates application session');
    [$status, $body] = request('/dashboard', $cookies);
    check($status === 200 && str_contains($body, 'csrf_token'), 'authenticated dashboard is available');
    check(request('/logout', $cookies, ['csrf_token' => token($body)])[0] === 302, 'CSRF-protected logout succeeds');
    check(request('/dashboard', $cookies)[0] === 302, 'logout removes authentication');
    session_id($siblingId);
    session_start();
    check(($_SESSION['sibling_marker'] ?? '') === 'unchanged' && ($_SESSION['user_id'] ?? null) === $id, 'sibling session remains intact after login and logout');
    session_destroy();
    echo "Session cookie checks passed.\n";
    if (!str_starts_with(getenv('PHP_BUDGET_TEST_URL') ?: '', 'https://')) {
        echo "Not verified: Secure cookies over actual HTTPS (HTTP server supplied).\n";
    }
} finally {
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}
