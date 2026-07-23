<?php use App\Csrf; ?>
<div class="container narrow">
    <h2>Add User</h2>
    <p class="empty-note">The new user signs in with their own email and password, and sees your budget.</p>

    <form method="post" action="<?= e(url('/users/create')) ?>">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="role">Role:</label>
            <?php $role = $old['role'] ?? 'budgeter'; ?>
            <select id="role" name="role">
                <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrator — everything, including settings and users</option>
                <option value="budgeter" <?= $role === 'budgeter' ? 'selected' : '' ?>>Budgeter — bills, allocations, amounts, mark paid</option>
                <option value="payer" <?= $role === 'payer' ? 'selected' : '' ?>>Bill payer — mark bills paid</option>
                <option value="viewer" <?= $role === 'viewer' ? 'selected' : '' ?>>Read only — view only</option>
            </select>
        </div>

        <div class="field">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
            <div id="passwordMessage" class="password-requirements">At least 8 characters with an uppercase letter, a lowercase letter, a number, and a special character.</div>
        </div>

        <div class="field">
            <label for="verifyPassword">Verify Password:</label>
            <input type="password" id="verifyPassword" name="verifyPassword" required>
        </div>

        <div class="field checkbox-field">
            <input type="checkbox" id="receiveReminders" name="receiveReminders" value="1"
                   <?= isset($old['email']) && empty($old['receiveReminders']) ? '' : 'checked' ?>>
            <label for="receiveReminders">Send them the nightly unpaid-bill reminder email</label>
        </div>

        <button type="submit" class="btn primary">Add User</button>
        <a href="<?= e(url('/users')) ?>" class="btn">Cancel</a>
    </form>

    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
<script src="<?= e(asset('js/password.js')) ?>"></script>
