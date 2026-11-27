<?php
use App\Auth;

// Bills counts what this check still owes; remaining nets out everything
// allocated to it, paid or not.
$allocatedTotal = 0;
$paidTotal = 0;
foreach ($rows as $row) {
    $allocatedTotal += money_cents((string) $row['alloc_amount']);
    if (!empty($row['paid'])) {
        $paidTotal += money_cents((string) $row['alloc_amount']);
    }
}
$billsTotal = $allocatedTotal - $paidTotal;
$remaining = money_cents((string) $paycheck['amount']) - $allocatedTotal;
$isWave = !empty($paycheck['is_wave']);
$canPay = Auth::canPay();
$isAdmin = Auth::canManageBills();
?>
<div class="paycheck-card<?= $isCurrent ? ' current' : '' ?>" data-paycheck-id="<?= (int) $paycheck['id'] ?>">
    <div class="paycheck-head">
        <span class="paycheck-date">
            <?= e(short_date((string) $paycheck['pay_date'])) ?>
            <?php if ($isWave): ?><span class="badge wave">Wave</span><?php endif; ?>
        </span>
        <span class="paycheck-income">
            Income: $<span class="amount pay-amount<?= $isAdmin ? ' editable' : '' ?>"<?= $isAdmin ? ' title="Click to edit"' : '' ?>><?= e(money((string) $paycheck['amount'])) ?></span>
        </span>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-note">No bills allocated<?= $isWave ? ' — extra check' : '' ?>.</div>
    <?php endif; ?>

    <div class="occ-list">
    <?php foreach ($rows as $row): ?>
        <?php
        $classes = ['occ-row'];
        if (!empty($row['paid'])) {
            $classes[] = 'paid';
        }
        $overdue = $row['due_date'] < $today || !empty($row['carried_from_date']);
        if ($overdue && empty($row['paid'])) {
            $classes[] = 'overdue';
        }
        $isSplit = (int) $row['alloc_count'] > 1;
        // Paying from a check that lands after the due date = paying late.
        $late = $row['due_date'] < (string) $paycheck['pay_date'];
        $isLate = empty($row['paid']) && $late;
        ?>
        <div class="<?= implode(' ', $classes) ?>" data-overdue="<?= (int) $overdue ?>" data-occurrence-id="<?= (int) $row['occurrence_id'] ?>">
            <input type="checkbox" class="occ-paid" <?= !empty($row['paid']) ? 'checked' : '' ?>
                   <?= $canPay ? '' : 'disabled' ?>
                   title="<?= $canPay ? 'Mark paid / unpaid' : 'Your account is read-only' ?>">
            <span class="occ-name">
                <?php if ($isAdmin): ?>
                    <a href="<?= e(url('/allocations/edit?occurrence_id=' . (int) $row['occurrence_id'])) ?>"
                       title="Reassign or split"><?= e($row['bill_name']) ?></a>
                <?php else: ?>
                    <?= e($row['bill_name']) ?>
                <?php endif; ?>
                <small data-late="<?= (int) $late ?>" class="due-note<?= $isLate ? ' late' : '' ?>"<?= $isLate ? ' title="This paycheck lands after the due date"' : '' ?>>due <?= e(short_date((string) $row['due_date'])) ?></small>
                <?php if (!empty($row['carried_from_date'])): ?>
                    <small data-late="1" class="due-note<?= empty($row['paid']) ? ' late' : '' ?>">carried from <?= e(short_date((string) $row['carried_from_date'])) ?></small>
                <?php endif; ?>
                <?php if ($isSplit): ?>
                    <span class="split-tag" title="Split across paychecks — this check pays $<?= e(money((string) $row['alloc_amount'])) ?> of $<?= e(money((string) $row['occ_amount'])) ?>">split</span>
                <?php endif; ?>
            </span>
            <?php $editable = $isAdmin && !$isSplit; ?>
            <span class="occ-amount<?= $editable ? ' editable' : '' ?>"<?= $editable ? ' title="Click to edit"' : '' ?>>$<span class="amount"><?= e(money((string) $row['alloc_amount'])) ?></span></span>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="paycheck-totals">
        <div>
            <span>Bills <span class="paid-note"<?= $paidTotal > 0 ? '' : ' hidden' ?>>· $<span class="paid-amount"><?= e(money($paidTotal / 100)) ?></span> paid</span></span>
            <span class="amount bills-total">$<?= e(money($billsTotal / 100)) ?></span>
        </div>
        <div>
            <span>Remaining</span>
            <span class="amount remaining <?= $remaining < 0 ? 'remaining-neg' : 'remaining-pos' ?>">$<?= e(money($remaining / 100)) ?></span>
        </div>
    </div>
</div>
