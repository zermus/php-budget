<?php use App\Csrf; ?>
<div class="login-form">
    <h2>Budget Login</h2>
    <form action="<?= e(url('/login')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="email" name="email" placeholder="Email" required autofocus>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" class="btn primary">Login</button>
    </form>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
