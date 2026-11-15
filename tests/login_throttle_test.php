<?php

declare(strict_types=1);

require __DIR__ . '/session_cookie_test.php';

[$pdo, $id, $email] = fixture();
$alternate = 'tést@example.test';
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    foreach ([$email, $alternate] as $spelling) {
        $stmt->execute([$spelling]);
        check((int) $stmt->fetchColumn() === $id, 'database resolves spelling to fixture account');
    }
    $insert = $pdo->prepare('INSERT INTO login_attempts (email_hash, ip, attempted_at) VALUES (?, ?, NOW())');
    $seed = static function (string $address, string $ip, int $count) use ($insert): void {
        for ($i = 0; $i < $count; $i++) {
            $insert->execute([hash('sha256', strtolower(trim($address))), $ip]);
        }
    };
    $reset = static fn () => $pdo->exec('DELETE FROM login_attempts');
    $count = static fn () => (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    $reset();
    foreach ([17, 17, 16] as $i => $n) {
        $seed($email, '192.0.2.' . ($i + 1), $n);
    }
    foreach ([$email, $alternate] as $spelling) {
        $cookies = [];
        check(login($cookies, $spelling, 'dummy-password')[0] === 429, 'account-wide limit applies to resolved identity');
    }
    check($count() === 50, 'blocked requests do not add failures');
    $reset();
    $cookies = [];
    for ($i = 0; $i < 5; $i++) {
        [$status, $body] = login($cookies, $i % 2 ? $alternate : $email, 'wrong');
        check($status === 200 && str_contains($body, 'Invalid email or password.'), 'mixed spelling failure is generic');
    }
    check($count() === 5 && (int) $pdo->query('SELECT COUNT(DISTINCT email_hash) FROM login_attempts')->fetchColumn() === 1, 'mixed failures accumulate under one identity');
    check(login($cookies, $alternate, 'dummy-password')[0] === 429, 'email/IP limit applies across spellings');
    $reset();
    $seed($email, '127.0.0.1', 1);
    $seed($email, '192.0.2.10', 1);
    $seed('unknown@example.test', '127.0.0.1', 1);
    check(login($cookies, $alternate, 'dummy-password')[0] === 302, 'alternate spelling can sign in below limit');
    check($count() === 2, 'successful login clears only resolved email and current IP');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND ip = ?');
    $stmt->execute([hash('sha256', $email), '127.0.0.1']);
    check((int) $stmt->fetchColumn() === 0, 'canonical current-IP counter is cleared');
    $reset();
    $cookies = [];
    check(request('/login', $cookies, ['email' => $email, 'password' => 'wrong', 'csrf_token' => 'invalid'])[0] === 403 && $count() === 0, 'invalid CSRF does not count');
    [$status, $body] = login($cookies, 'unknown@example.test', 'dummy-password');
    check($status === 200 && str_contains($body, 'Invalid email or password.') && $count() === 1, 'unknown account follows generic failure path');
    $reset();
    for ($i = 0; $i < 20; $i++) {
        $seed("unknown$i@example.test", '127.0.0.1', 1);
    }
    check(login($cookies, $email, 'dummy-password')[0] === 429, 'independent IP-wide limit remains enforced');
    $reset();
    $seed($email, '127.0.0.1', 5);
    $pdo->exec('UPDATE login_attempts SET attempted_at = NOW() - INTERVAL 16 MINUTE');
    check(login($cookies, $email, 'dummy-password')[0] === 302, 'expired counters permit normal successful login');
    echo "Login throttle checks passed.\n";
} finally {
    $pdo->exec('DELETE FROM login_attempts');
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}
