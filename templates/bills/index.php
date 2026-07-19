<?php use App\Csrf; ?>
<div class="container">
    <div class="page-head">
        <h1>Bills</h1>
        <a href="<?= e(url('/bills/create')) ?>" class="btn primary">Add Bill</a>
    </div>

    <?php if (empty($bills)): ?>
        <p class="empty-note">No bills yet. Add one to start budgeting.</p>
    <?php else: ?>
        <table class="list">
            <tr>
                <th>Name</th>
                <th class="num">Amount</th>
                <th>Recurrence</th>
                <th>Notes</th>
                <th class="actions"></th>
            </tr>
            <?php foreach ($bills as $bill): ?>
                <tr class="<?= empty($bill['active']) ? 'inactive' : '' ?>">
                    <td>
                        <?= e($bill['name']) ?>
                        <?php if (empty($bill['active'])): ?><small>(inactive)</small><?php endif; ?>
                    </td>
                    <td class="num">$<?= e(money((string) $bill['default_amount'])) ?></td>
                    <td><?= e(describe_recurrence($bill)) ?></td>
                    <td><?= e((string) ($bill['notes'] ?? '')) ?></td>
                    <td class="actions">
                        <a href="<?= e(url('/bills/edit?id=' . (int) $bill['id'])) ?>" class="btn small">Edit</a>
                        <form action="<?= e(url('/bills/toggle')) ?>" method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $bill['id'] ?>">
                            <button type="submit" class="btn small"><?= empty($bill['active']) ? 'Activate' : 'Deactivate' ?></button>
                        </form>
                        <form action="<?= e(url('/bills/delete')) ?>" method="post" class="inline"
                              onsubmit="return confirm('Delete this bill AND its entire paid history? Deactivate instead to keep history.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $bill['id'] ?>">
                            <button type="submit" class="btn small danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
