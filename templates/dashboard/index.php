<?php use App\App; use App\Csrf; use App\View; ?>
<div class="container" id="dashboard" data-csrf="<?= e(Csrf::token()) ?>"
     data-paid-url="<?= e(url('/occurrences/paid')) ?>"
     data-occ-amount-url="<?= e(url('/occurrences/amount')) ?>"
     data-pay-amount-url="<?= e(url('/paychecks/amount')) ?>">
    <div class="page-head">
        <h1>Upcoming Paychecks</h1>
        <div class="dash-tools">
            <span class="empty-note">Click an amount to edit it.</span>
            <label for="sortSelect" class="empty-note">Order bills:</label>
            <select id="sortSelect">
                <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Largest first</option>
                <option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Smallest first</option>
                <option value="due_date" <?= $sort === 'due_date' ? 'selected' : '' ?>>By due date</option>
            </select>
        </div>
    </div>

    <?php if (empty($paychecks)): ?>
        <p class="empty-note">
            No paychecks in your dashboard window.
            Set up your pay schedule under <a href="<?= e(url('/settings')) ?>">Settings</a>.
        </p>
    <?php else: ?>
        <div class="paycheck-grid">
            <?php foreach ($paychecks as $paycheck): ?>
                <?= View::partial('dashboard/partials/paycheck_card', [
                    'paycheck'  => $paycheck,
                    'rows'      => $rowsByPaycheck[(int) $paycheck['id']] ?? [],
                    'isCurrent' => $currentId === (int) $paycheck['id'],
                    'today'     => $today,
                ]) ?>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a class="btn small" href="<?= e(url('/dashboard?page=' . ($page - 1))) ?>">&larr; Sooner</a>
            <?php endif; ?>
            <span class="empty-note">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="btn small" href="<?= e(url('/dashboard?page=' . ($page + 1))) ?>">Later &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="version-note">php-budget v<?= e(App::VERSION) ?></div>
</div>
