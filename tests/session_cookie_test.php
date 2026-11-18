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
    $path = parse_url((string) App\App::config('base_url', '/'), PHP_URL_PATH) ?: '/';
    check(preg_match('~; path=' . preg_quote($path, '~') . '(?:;|$)~i', $cookies[0]) === 1, 'exact configured cookie path');
    foreach (['HttpOnly', 'SameSite=Lax'] as $attribute) {
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
    $forged = ['PHPBUDGETSESSID' => 'unissued-session-identifier'];
    request('/login', $forged);
    check($forged['PHPBUDGETSESSID'] !== 'unissued-session-identifier', 'strict sessions replace an unissued identifier');
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
    $oldToken = token($body);
    check(login($cookies, $email, 'dummy-password')[0] === 302, 'normal login succeeds');
    check($cookies['PHPBUDGETSESSID'] !== $anonymousId, 'login rotates application session');
    [$status, $body] = request('/dashboard', $cookies);
    check($status === 200 && str_contains($body, 'csrf_token'), 'authenticated dashboard is available');
    check(token($body) !== $oldToken, 'login renews CSRF token');
    check(request('/logout', $cookies, ['csrf_token' => $oldToken])[0] === 403, 'pre-login CSRF is rejected');
    $csrf = token($body);
    $settings = ['csrf_token'=>$csrf, 'action'=>'settings', 'scheduleType'=>'monthly', 'monthDay'=>'28',
        'defaultIncome'=>'3000', 'windowDays'=>'90', 'reminderLeadDays'=>'7', 'mailTransport'=>'log',
        'mailFrom'=>'', 'mailFromName'=>'', 'smtpHost'=>'', 'smtpPort'=>'', 'smtpEncryption'=>'tls', 'smtpUsername'=>''];
    check(request('/settings', $cookies, $settings)[0] === 302, 'monthly settings save succeeds');
    request('/dashboard', $cookies);
    $pdo->prepare('UPDATE paychecks SET amount=2222,amount_overridden=1 WHERE user_id=? ORDER BY pay_date DESC LIMIT 1')->execute([$id]);
    $settings = array_replace($settings, ['scheduleType'=>'semimonthly', 'semiDay1'=>'14', 'semiDay2'=>'28', 'defaultIncome'=>'1500']);
    check(request('/settings', $cookies, $settings)[0] === 302, 'combined schedule and income save succeeds');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM paychecks WHERE user_id=? AND pay_date >= CURRENT_DATE AND amount_overridden=0 AND amount <> 1500');
    $stmt->execute([$id]);
    check((int)$stmt->fetchColumn() === 0, 'combined save updates exact-date and new non-overridden incomes');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM paychecks WHERE user_id=? AND amount_overridden=1 AND amount=2222');
    $stmt->execute([$id]);
    check((int)$stmt->fetchColumn() === 1, 'combined save preserves overridden income');
    $settings['defaultIncome'] = '1600';
    check(request('/settings', $cookies, $settings)[0] === 302, 'income-only save succeeds');
    $stmt = $pdo->prepare('SELECT * FROM user_settings WHERE user_id=?');
    $stmt->execute([$id]); $beforeSettings = $stmt->fetch();
    foreach (['mailFrom'=>190,'mailFromName'=>190,'smtpUsername'=>190,'smtpHost'=>255] as $field=>$limit) {
        request('/settings', $cookies, array_replace($settings, [$field=>str_repeat('é',$limit+1)]));
        $stmt->execute([$id]);
        check($stmt->fetch() === $beforeSettings, "$field oversized save rolls back all settings");
    }
    $bill = ['csrf_token'=>$csrf, 'name'=>str_repeat('é',190), 'amount'=>'99999999.99', 'recurrenceType'=>'monthly_day', 'monthDay'=>'1'];
    check(request('/bills/create', $cookies, $bill)[0] === 302, 'bill name and amount at limit save');
    $stmt = $pdo->prepare('SELECT * FROM bills WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]); $created = $stmt->fetch();
    check($created['starts_on'] === date('Y-m-d'), 'new bill starts today');
    foreach ([['name'=>str_repeat('é',191)],['amount'=>'100000000.00']] as $invalid) {
        [$status,$errorBody] = request('/bills/edit', $cookies, array_replace($bill, ['id'=>$created['id']], $invalid));
        $stmt->execute([$id]);
        check($status === 200 && $stmt->fetch() === $created && !str_contains($errorBody,'Something went wrong'), 'oversized bill edit rejected without mutation');
    }
    $pdo->prepare("UPDATE bills SET starts_on='2000-01-01' WHERE id=?")->execute([$created['id']]);
    check(request('/bills/edit', $cookies, array_replace($bill,['id'=>$created['id'],'name'=>'Edited']))[0] === 302, 'ordinary bill edit succeeds');
    $stmt->execute([$id]); check($stmt->fetch()['starts_on'] === '2000-01-01', 'ordinary edit preserves starts_on');
    request('/bills/toggle', $cookies, ['csrf_token'=>$csrf,'id'=>$created['id']]);
    request('/bills/toggle', $cookies, ['csrf_token'=>$csrf,'id'=>$created['id']]);
    $stmt->execute([$id]); check($stmt->fetch()['starts_on'] === date('Y-m-d'), 'reactivation resets starts_on');
    $memberEmail = str_repeat('a',64) . '@' . str_repeat('b',60) . '.' . str_repeat('c',59) . '.test';
    $member = ['csrf_token'=>$csrf,'email'=>$memberEmail,'password'=>'éAbcdef1#','verifyPassword'=>'éAbcdef1#','role'=>'viewer'];
    [$status,$errorBody] = request('/users/create', $cookies, array_replace($member,['email'=>$memberEmail.'x']));
    check($status === 200 && str_contains($errorBody,'190 characters'), 'user email limit plus one gives validation message');
    check(request('/users/create', $cookies, $member)[0] === 302, 'user email at 190 characters saves');
    request('/dashboard', $cookies);
    $occ = $pdo->prepare('SELECT id FROM bill_occurrences WHERE user_id=? LIMIT 1');
    $occ->execute([$id]); $occId = (int)$occ->fetchColumn();
    check($occId > 0, 'dashboard generates bounded recurring occurrence');
    [$status,$editor] = request('/allocations/edit?occurrence_id='.$occId, $cookies);
    check($status === 200 && str_contains($editor, 'selected'), 'allocation editor preselects current allocation');
    foreach (['1','0'] as $paid) {
        [$status,$json] = request('/occurrences/paid', $cookies, ['csrf_token'=>$csrf,'id'=>$occId,'paid'=>$paid]);
        check($status === 200 && json_decode($json,true)['success'], 'paid AJAX works with renewed CSRF');
    }
    [$status,$json] = request('/occurrences/amount', $cookies, ['csrf_token'=>$csrf,'id'=>$occId,'amount'=>'100000000']);
    check($status === 422 && !json_decode($json,true)['success'], 'inline amount ceiling returns validation JSON');
    [$status,$json] = request('/occurrences/amount', $cookies, ['csrf_token'=>$csrf,'id'=>$occId,'amount'=>'120']);
    check($status === 200 && json_decode($json,true)['success'], 'inline amount edit succeeds');
    check(request('/occurrences/skip', $cookies, ['csrf_token'=>$csrf,'id'=>$occId])[0] === 302, 'skip form works with renewed CSRF');
    check(request('/bills/delete', $cookies, ['csrf_token'=>$csrf,'id'=>$created['id']])[0] === 302, 'bill deletion succeeds');
    request('/dashboard', $cookies);
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
