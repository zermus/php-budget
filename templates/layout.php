<?php use App\Auth; use App\Csrf; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Budget App') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
    <?php if (Auth::user() !== null): ?>
    <nav class="topnav">
        <span class="brand">Budget</span>
        <a href="<?= e(url('/dashboard')) ?>">Dashboard</a>
        <a href="<?= e(url('/bills')) ?>">Bills</a>
        <a href="<?= e(url('/settings')) ?>">Settings</a>
        <form action="<?= e(url('/logout')) ?>" method="post" class="inline logout">
            <?= Csrf::field() ?>
            <button type="submit" class="btn link">Logout</button>
        </form>
    </nav>
    <?php endif; ?>

    <?php foreach (flash_pull() as $msg): ?>
    <div class="flash message <?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>

    <?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= e($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
