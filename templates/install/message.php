<div class="container narrow">
    <h2><?= e($heading) ?></h2>
    <p><?= e($body) ?></p>
    <?php if (!empty($homeLink)): ?>
        <a href="<?= e(url('/login')) ?>" class="btn">Go to Login</a>
    <?php endif; ?>
</div>
