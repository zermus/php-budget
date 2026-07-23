<?php use App\Auth; use App\Csrf; ?>
<div class="container">
    <div class="page-head">
        <h1>Users</h1>
        <a href="<?= e(url('/users/create')) ?>" class="btn primary">Add User</a>
    </div>

    <p class="empty-note">
        Everyone here shares one budget. <strong>Administrators</strong> can do everything, including
        settings and this page. <strong>Budgeters</strong> manage bills and how they are paid.
        <strong>Bill payers</strong> only tick bills paid. <strong>Read only</strong> users just look.
    </p>

    <table class="list">
        <tr>
            <th>Email</th>
            <th>Role</th>
            <th class="actions"></th>
        </tr>

        <tr>
            <td>
                <?= e((string) ($owner['email'] ?? '')) ?>
                <small><?= (int) ($owner['id'] ?? 0) === (int) $me['id'] ? '(you — account owner)' : '(account owner)' ?></small>
            </td>
            <td><span class="badge role-admin">Administrator</span></td>
            <td class="actions">
                <?php if ((int) ($owner['id'] ?? 0) === (int) $me['id']): ?>
                    <a href="<?= e(url('/settings')) ?>" class="btn small">Settings</a>
                <?php endif; ?>
            </td>
        </tr>

        <?php foreach ($users as $user): ?>
            <?php $isMe = (int) $user['id'] === (int) $me['id']; ?>
            <tr>
                <td>
                    <?= e($user['email']) ?>
                    <?php if ($isMe): ?><small>(you)</small><?php endif; ?>
                </td>
                <td>
                    <?php if ($isMe): ?>
                        <span class="badge role-<?= e($user['role']) ?>"><?= e(Auth::roleLabel($user['role'])) ?></span>
                        <small class="empty-note">— another administrator can change this</small>
                    <?php else: ?>
                        <form action="<?= e(url('/users/update')) ?>" method="post" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                            <select name="role">
                                <?php foreach (Auth::ROLE_LABELS as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="inline-check">
                                <input type="checkbox" name="receiveReminders" value="1"
                                       <?= !empty($user['receive_reminders']) ? 'checked' : '' ?>>
                                Reminders
                            </label>
                            <button type="submit" class="btn small">Save</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <?php if (!$isMe): ?>
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
                    <?php else: ?>
                        <a href="<?= e(url('/settings')) ?>" class="btn small">Change my password</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if (empty($users)): ?>
        <p class="empty-note">No other users yet.</p>
    <?php endif; ?>
</div>
