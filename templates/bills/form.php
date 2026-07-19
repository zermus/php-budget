<?php
use App\Csrf;

// Precedence: submitted values (validation error), then the bill row, then blanks.
$value = null;
if ($bill !== null) {
    $value = json_decode((string) $bill['recurrence_value'], true) ?: [];
}
$fld = static function (string $postKey, ?string $billValue) use ($old): string {
    return (string) ($old[$postKey] ?? $billValue ?? '');
};
$type = $old['recurrenceType'] ?? ($bill['recurrence_type'] ?? 'every_n_paychecks');
?>
<div class="container narrow">
    <h2><?= $bill === null ? 'Add Bill' : 'Edit Bill' ?></h2>
    <form method="post" action="<?= e(url($bill === null ? '/bills/create' : '/bills/edit')) ?>">
        <?= Csrf::field() ?>
        <?php if ($bill !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $bill['id'] ?>">
        <?php endif; ?>

        <div class="field">
            <label for="name">Name:</label>
            <input type="text" id="name" name="name" required
                   value="<?= e($fld('name', $bill['name'] ?? null)) ?>">
        </div>

        <div class="field">
            <label for="amount">Amount ($):</label>
            <input type="text" id="amount" name="amount" required inputmode="decimal"
                   value="<?= e($fld('amount', $bill['default_amount'] ?? null)) ?>">
        </div>

        <div class="field">
            <label for="recurrenceType">Recurrence:</label>
            <select id="recurrenceType" name="recurrenceType">
                <option value="every_n_paychecks" <?= $type === 'every_n_paychecks' ? 'selected' : '' ?>>Every N paychecks</option>
                <option value="monthly_day" <?= $type === 'monthly_day' ? 'selected' : '' ?>>Monthly on a day</option>
                <option value="one_time" <?= $type === 'one_time' ? 'selected' : '' ?>>One time</option>
            </select>
        </div>

        <div class="field recurrence-fields" data-type="every_n_paychecks">
            <label for="everyN">Every how many paychecks?</label>
            <input type="number" id="everyN" name="everyN" min="1" max="12"
                   value="<?= e($fld('everyN', isset($value['n']) ? (string) $value['n'] : '2')) ?>">
            <label for="anchorPaycheck">First paycheck it applies to (sets the phase):</label>
            <input type="date" id="anchorPaycheck" name="anchorPaycheck"
                   value="<?= e($fld('anchorPaycheck', $value['anchor'] ?? null)) ?>">
        </div>

        <div class="field recurrence-fields" data-type="monthly_day">
            <label for="monthDay">Due on day of month (clamped to shorter months):</label>
            <input type="number" id="monthDay" name="monthDay" min="1" max="31"
                   value="<?= e($fld('monthDay', isset($value['day']) ? (string) $value['day'] : '')) ?>">
        </div>

        <div class="field recurrence-fields" data-type="one_time">
            <label for="oneTimeDate">Due date:</label>
            <input type="date" id="oneTimeDate" name="oneTimeDate"
                   value="<?= e($fld('oneTimeDate', $value['date'] ?? null)) ?>">
        </div>

        <div class="field">
            <label for="notes">Notes:</label>
            <textarea id="notes" name="notes" rows="3"><?= e($fld('notes', $bill['notes'] ?? null)) ?></textarea>
        </div>

        <?php if ($bill !== null): ?>
            <p class="empty-note">Changing the amount or recurrence rebuilds this bill's upcoming
                unpaid occurrences; paid history is kept.</p>
        <?php endif; ?>

        <button type="submit" class="btn primary"><?= $bill === null ? 'Add Bill' : 'Save' ?></button>
        <a href="<?= e(url('/bills')) ?>" class="btn">Cancel</a>
    </form>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
<script>
(function () {
    var select = document.getElementById('recurrenceType');
    function toggle() {
        document.querySelectorAll('.recurrence-fields').forEach(function (el) {
            el.style.display = el.dataset.type === select.value ? '' : 'none';
        });
    }
    select.addEventListener('change', toggle);
    toggle();
})();
</script>
