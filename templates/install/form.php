<?php use App\Csrf; ?>
<div class="container narrow">
    <h2>Install Budget App</h2>
    <p>Welcome! This sets up the database and creates your account.</p>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="install">
        <div class="field">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
            <div id="passwordMessage" class="password-requirements">Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.</div>
        </div>
        <div class="field">
            <label for="verifyPassword">Verify Password:</label>
            <input type="password" id="verifyPassword" name="verifyPassword" required>
        </div>
        <div class="field checkbox-field">
            <input type="checkbox" id="seedBudget" name="seedBudget" value="1" <?= isset($old['action']) && empty($old['seedBudget']) ? '' : 'checked' ?>>
            <label for="seedBudget">Seed an example starter budget (biweekly $2,500 schedule with bills in two alternating paycheck phases — edit or delete them later under Bills)</label>
        </div>
        <button type="submit" class="btn primary">Install</button>
    </form>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
<script src="<?= e(asset('js/password.js')) ?>"></script>
