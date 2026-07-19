<?php use App\Csrf; ?>
<div class="container narrow">
    <h2>Allocate: <?= e($occurrence['bill_name']) ?></h2>
    <p>
        $<?= e(money((string) $occurrence['amount'])) ?> due <?= e(short_date((string) $occurrence['due_date'])) ?>.
        Assign it to one paycheck, or split it across several — the split must add up to the full amount.
    </p>

    <form method="post" action="<?= e(url('/allocations/edit')) ?>" id="alloc-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="occurrence_id" value="<?= (int) $occurrence['id'] ?>">

        <div id="alloc-rows">
            <?php
            $rows = $allocations !== [] ? $allocations : [['paycheck_id' => null, 'amount' => $occurrence['amount']]];
            foreach ($rows as $allocation):
            ?>
            <div class="alloc-row">
                <select name="paycheck_id[]">
                    <?php foreach ($paychecks as $paycheck): ?>
                        <option value="<?= (int) $paycheck['id'] ?>"
                            <?= (int) ($allocation['paycheck_id'] ?? 0) === (int) $paycheck['id'] ? 'selected' : '' ?>>
                            <?= e(short_date((string) $paycheck['pay_date'])) ?>
                            ($<?= e(money((string) $paycheck['amount'])) ?><?= !empty($paycheck['is_wave']) ? ', wave check' : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="amount[]" inputmode="decimal"
                       value="<?= e(money((string) $allocation['amount'])) ?>">
                <button type="button" class="btn small remove-row" title="Remove row">✕</button>
            </div>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="button" class="btn small" id="add-row">+ Add split row</button>
        </p>

        <button type="submit" class="btn primary">Save</button>
        <a href="<?= e(url('/dashboard')) ?>" class="btn">Cancel</a>
    </form>

    <?php if (empty($occurrence['paid'])): ?>
    <hr>
    <form method="post" action="<?= e(url('/occurrences/skip')) ?>"
          onsubmit="return confirm('Skip this occurrence? It will disappear from the dashboard and reminders. The bill\'s other occurrences are unaffected.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $occurrence['id'] ?>">
        <button type="submit" class="btn danger">Skip this occurrence</button>
    </form>
    <?php endif; ?>
</div>
<script>
(function () {
    var rows = document.getElementById('alloc-rows');

    document.getElementById('add-row').addEventListener('click', function () {
        var row = rows.querySelector('.alloc-row').cloneNode(true);
        row.querySelector('input').value = '';
        rows.appendChild(row);
    });

    rows.addEventListener('click', function (event) {
        if (event.target.classList.contains('remove-row') && rows.querySelectorAll('.alloc-row').length > 1) {
            event.target.closest('.alloc-row').remove();
        }
    });
})();
</script>
