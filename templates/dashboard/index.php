<?php use App\Csrf; use App\View; ?>
<div class="container" id="dashboard" data-csrf="<?= e(Csrf::token()) ?>"
     data-paid-url="<?= e(url('/occurrences/paid')) ?>"
     data-occ-amount-url="<?= e(url('/occurrences/amount')) ?>"
     data-pay-amount-url="<?= e(url('/paychecks/amount')) ?>">
    <div class="page-head">
        <h1>Upcoming Paychecks</h1>
        <span class="empty-note">Double-click an amount to edit it.</span>
    </div>

    <?php if (empty($paychecks)): ?>
        <p class="empty-note">
            No paychecks in the next <?= e((string) \App\Services\ScheduleService::WINDOW_DAYS) ?> days.
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
    <?php endif; ?>
</div>
