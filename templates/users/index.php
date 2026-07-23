<?php use App\Csrf; ?>
<div class="container">
    <div class="page-head">
        <h1>Users</h1>
        <a href="<?= e(url('/users/create')) ?>" class="btn primary">Add User</a>
    </div>

    <p class="empty-note">
        Everyone here shares your budget. <strong>Bill payers</strong> can tick bills paid;
        <strong>read-only</strong> users can only look. Bills, the pay schedule, and this page
        stay with you, the administrator.
    </p>

    <table class="list">
        <tr>
            <th>Email</th>
            <th>Role</th>
            <th>Reminders</th>
            <th class="actions"></th>
        </tr>
        <tr>
            <td><?= e($admin['email']) ?> <small>(you)</small></td>
            <td><span class="badge role-admin">Administrator</span></td>
            <td><?= !empty($admin['receive_reminders']) ? 'Yes' : 'No' ?></td>
            <td class="actions"><a href="<?= e(url('/settings')) ?>" class="btn small">Settings</a></td>
        </tr>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= e($user['email']) ?></td>
                <td colspan="2">
                    <form action="<?= e(url('/users/update')) ?>" method="post" class="inline-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <select name="role">
                            <option value="payer" <?= $user['role'] === 'payer' ? 'selected' : '' ?>>Bill payer</option>
                            <option value="viewer" <?= $user['role'] === 'viewer' ? 'selected' : '' ?>>Read only</option>
                        </select>
                        <label class="inline-check">
                            <input type="checkbox" name="receiveReminders" value="1"
                                   <?= !empty($user['receive_reminders']) ? 'checked' : '' ?>>
                            Reminders
                        </label>
                        <button type="submit" class="btn small">Save</button>
                    </form>
                </td>
                <td class="actions">
                    <form action="<?= e(url('/users/password')) ?>" method="post" class="inline-form"
                          onsubmit="return this.password.value !== '';">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <input type="password" name="password" placeholder="New password" class="narrow-input">
                        <button type="submit" class="btn small">Reset</button>
                    </form>
                    <form action="<?= e(url('/users/delete')) ?>" method="post" class="inline"
                          onsubmit="return confirm('Remove <?= e($user['email']) ?>? Your budget data is not affected.');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button type="submit" class="btn small danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if (empty($users)): ?>
        <p class="empty-note">No other users yet.</p>
    <?php endif; ?>
</div>
