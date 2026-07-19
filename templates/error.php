<div class="container narrow">
    <h2><?= e($heading ?? 'Error') ?></h2>
    <p><?= e($message ?? 'An error occurred.') ?></p>
    <a href="<?= e(url('/')) ?>" class="btn">Home</a>
</div>
