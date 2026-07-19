<?php use App\Csrf; ?>
<div class="container narrow">
    <h2>Upgrade Budget App</h2>
    <p>
        Your database is at schema version <?= e((string) $fromVersion) ?>, and this version of the app
        (<?= e($appVersion) ?>) uses schema version <?= e((string) $toVersion) ?>.
        Back up your database, then run the upgrade.
    </p>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upgrade">
        <button type="submit" class="btn primary">Upgrade Database</button>
    </form>
</div>
