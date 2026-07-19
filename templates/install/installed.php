<div class="container narrow">
    <h2>Installation Complete</h2>
    <p>Your account is ready<?= !empty($seeded) ? ' and your starter budget has been seeded' : '' ?>.</p>
    <p>To get bill reminder emails, add this cron entry on the server (runs nightly at 6 AM):</p>
    <pre>0 6 * * * php <?= e($cronPath) ?></pre>
    <p>For security you may now delete install.php from the public/ directory.</p>
    <a href="<?= e(url('/login')) ?>" class="btn primary">Go to Login</a>
</div>
