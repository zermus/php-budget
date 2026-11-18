<?php

declare(strict_types=1);

use App\App;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/App.php';
require $root . '/src/helpers.php';
require $root . '/src/Csrf.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? '  ok: ' : 'FAIL: ') . $label . "\n";
    $failures += (int) !$ok;
}

check(App::SCHEMA_VERSION === 9 && App::VERSION === '0.10-beta', 'release 0.10-beta uses schema 9');
$version = App::VERSION;
check(preg_match('/^\d+\.\d+-beta$/D', $version) === 1, 'runtime release version format');
$files = [
    'build-release.sh' => '/^VERSION="([^"]+)"$/m',
    'README.md' => '/^A simple self-hosted paycheck budgeting app\. Version ([^\s]+)\.$/m',
    'CHANGELOG.md' => '/\A# Changelog\s+(?:## Unreleased\s+[\s\S]*?)?## ([^\s]+)(?: - \d{4}-\d{2}-\d{2})?\R/',
];
foreach ($files as $file => $pattern) {
    preg_match($pattern, file_get_contents($root . '/' . $file), $match);
    check(($match[1] ?? null) === $version, "$file current version matches runtime");
}
$notes = $root . '/releases/release-notes-' . $version . '.md';
check(is_file($notes), 'matching release-note path exists');
$contents = is_file($notes) ? file_get_contents($notes) : '';
check(str_starts_with($contents, '# php-budget ' . $version . "\n"), 'release-note header matches runtime');
check(str_contains($contents, '`v' . $version . '`'), 'release notes name the tag');
check(str_contains($contents, '`php-budget-' . $version . '.tar.gz`'), 'release notes name the archive');

App::init(['base_url' => '/']);
$_SESSION = ['csrf_token' => 'release-version-test'];
$paychecks = [];
$sort = 'amount_desc';
ob_start();
require $root . '/templates/dashboard/index.php';
$html = ob_get_clean();
check(preg_match('/<div class="version-note">([^<]+)<\/div>/', $html, $footer) === 1
    && html_entity_decode($footer[1], ENT_QUOTES, 'UTF-8') === 'php-budget v' . $version,
    'visible empty-dashboard footer matches release version');

exit($failures > 0 ? 1 : 0);
