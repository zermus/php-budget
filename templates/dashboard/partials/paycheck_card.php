<?php
$billsTotal = 0.0;
foreach ($rows as $row) {
    $billsTotal += (float) $row['alloc_amount'];
}
$remaining = (float) $paycheck['amount'] - $billsTotal;
$isWave = !empty($paycheck['is_wave']);
?>
<div class="paycheck-card<?= $isCurrent ? ' current' : '' ?>" data-paycheck-id="<?= (int) $paycheck['id'] ?>">
    <div class="paycheck-head">
        <span class="paycheck-date">
            <?= e(short_date((string) $paycheck['pay_date'])) ?>
            <?php if ($isWave): ?><span class="badge wave">Wave</span><?php endif; ?>
        </span>
        <span class="paycheck-income">
            Income: $<span class="amount editable pay-amount" title="Double-click to edit"><?= e(money((string) $paycheck['amount'])) ?></span>
        </span>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-note">No bills allocated<?= $isWave ? ' — extra check' : '' ?>.</div>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
        <?php
        $classes = ['occ-row'];
        if (!empty($row['paid'])) {
            $classes[] = 'paid';
        }
        if ($row['due_date'] < $today && empty($row['paid'])) {
            $classes[] = 'overdue';
        }
        $isSplit = (int) $row['alloc_count'] > 1;
        ?>
        <div class="<?= implode(' ', $classes) ?>" data-occurrence-id="<?= (int) $row['occurrence_id'] ?>">
            <input type="checkbox" class="occ-paid" <?= !empty($row['paid']) ? 'checked' : '' ?>
                   title="Mark paid / unpaid">
            <span class="occ-name">
                <a href="<?= e(url('/allocations/edit?occurrence_id=' . (int) $row['occurrence_id'])) ?>"
                   title="Reassign or split"><?= e($row['bill_name']) ?></a>
                <small class="empty-note">due <?= e(short_date((string) $row['due_date'])) ?></small>
                <?php if ($isSplit): ?>
                    <span class="split-tag" title="Split across paychecks — this check pays $<?= e(money((string) $row['alloc_amount'])) ?> of $<?= e(money((string) $row['occ_amount'])) ?>">split</span>
                <?php endif; ?>
            </span>
            <span class="occ-amount<?= $isSplit ? '' : ' editable' ?>"<?= $isSplit ? '' : ' title="Double-click to edit"' ?>>$<span class="amount"><?= e(money((string) $row['alloc_amount'])) ?></span></span>
        </div>
    <?php endforeach; ?>

    <div class="paycheck-totals">
        <div><span>Bills</span> <span class="amount bills-total">$<?= e(money((string) $billsTotal)) ?></span></div>
        <div>
            <span>Remaining</span>
            <span class="amount remaining <?= $remaining < 0 ? 'remaining-neg' : 'remaining-pos' ?>">$<?= e(money((string) $remaining)) ?></span>
        </div>
    </div>
</div>
