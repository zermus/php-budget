<?php

// Router for `php -S localhost:8000 dev-router.php` — mimics the .htaccess
// rewrites, which PHP's built-in server does not read. Dev use only; not
// shipped in releases.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/install.php') {
    require __DIR__ . '/public/install.php';
    return true;
}

$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve it (chdir'd into public/)
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/public/index.php';
