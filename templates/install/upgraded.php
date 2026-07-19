<div class="container narrow">
    <h2>Upgrade Complete</h2>
    <?php if (!empty($applied)): ?>
        <p>Applied migrations:</p>
        <ul>
            <?php foreach ($applied as $item): ?>
                <li><?= e($item) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>The database was already up to date.</p>
    <?php endif; ?>
    <a href="<?= e(url('/login')) ?>" class="btn primary">Go to Login</a>
</div>
