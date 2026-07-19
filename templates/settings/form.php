<?php
use App\Csrf;

$type = (string) ($settings['schedule_type'] ?? 'biweekly');
$days = json_decode((string) ($settings['days_of_month'] ?? '[]'), true) ?: [];
?>
<div class="container narrow">
    <h2>Settings</h2>

    <form method="post" action="<?= e(url('/settings')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="settings">

        <h3>Pay Schedule</h3>
        <div class="field">
            <label for="scheduleType">Schedule:</label>
            <select id="scheduleType" name="scheduleType">
                <option value="weekly" <?= $type === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="biweekly" <?= $type === 'biweekly' ? 'selected' : '' ?>>Biweekly (every 2 weeks)</option>
                <option value="semimonthly" <?= $type === 'semimonthly' ? 'selected' : '' ?>>Semimonthly (two fixed days)</option>
                <option value="monthly" <?= $type === 'monthly' ? 'selected' : '' ?>>Monthly (one fixed day)</option>
            </select>
        </div>

        <div class="field schedule-fields" data-type="weekly biweekly">
            <label for="anchorDate">Anchor pay date (any real payday; the schedule repeats from it):</label>
            <input type="date" id="anchorDate" name="anchorDate"
                   value="<?= e((string) ($settings['anchor_date'] ?? '')) ?>">
        </div>

        <div class="field schedule-fields" data-type="semimonthly">
            <label>Days of the month (e.g. 1 and 15; 31 clamps to shorter months):</label>
            <input type="number" name="semiDay1" min="1" max="31" value="<?= e((string) ($days[0] ?? 1)) ?>">
            <input type="number" name="semiDay2" min="1" max="31" value="<?= e((string) ($days[1] ?? 15)) ?>">
        </div>

        <div class="field schedule-fields" data-type="monthly">
            <label for="monthDay">Day of the month (31 clamps to shorter months):</label>
            <input type="number" id="monthDay" name="monthDay" min="1" max="31"
                   value="<?= e((string) ($settings['day_of_month'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="defaultIncome">Default paycheck amount ($):</label>
            <input type="text" id="defaultIncome" name="defaultIncome" inputmode="decimal" required
                   value="<?= e((string) ($settings['default_income'] ?? '0.00')) ?>">
        </div>

        <h3>Dashboard</h3>
        <div class="field">
            <label for="windowDays">Look ahead this many days (14&ndash;365; 9 paychecks per page):</label>
            <input type="number" id="windowDays" name="windowDays" min="14" max="365"
                   value="<?= e((string) ($settings['window_days'] ?? 90)) ?>">
        </div>

        <h3>Reminders</h3>
        <div class="field">
            <label for="reminderLeadDays">Email me this many days before a bill is due:</label>
            <input type="number" id="reminderLeadDays" name="reminderLeadDays" min="0" max="30"
                   value="<?= e((string) ($settings['reminder_lead_days'] ?? 1)) ?>">
        </div>
        <div class="field">
            <label for="smtpHost">SMTP relay for my reminders (host or host:port; blank = server default):</label>
            <input type="text" id="smtpHost" name="smtpHost" placeholder="127.0.0.1:25"
                   value="<?= e((string) ($settings['smtp_host'] ?? '')) ?>">
        </div>

        <p class="empty-note">Changing the schedule rebuilds upcoming paychecks and unpaid bill
            occurrences. Paid history is kept.</p>

        <button type="submit" class="btn primary">Save Settings</button>
    </form>

    <hr>

    <form method="post" action="<?= e(url('/settings')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="password">

        <h3>Change Password</h3>
        <div class="field">
            <label for="currentPassword">Current password:</label>
            <input type="password" id="currentPassword" name="currentPassword" required>
        </div>
        <div class="field">
            <label for="password">New password:</label>
            <input type="password" id="password" name="password" required>
            <div id="passwordMessage" class="password-requirements">At least 8 characters with an uppercase letter, a lowercase letter, a number, and a special character.</div>
        </div>
        <div class="field">
            <label for="verifyPassword">Verify new password:</label>
            <input type="password" id="verifyPassword" name="verifyPassword" required>
        </div>
        <button type="submit" class="btn">Change Password</button>
    </form>
</div>
<script src="<?= e(asset('js/password.js')) ?>"></script>
<script>
(function () {
    var select = document.getElementById('scheduleType');
    function toggle() {
        document.querySelectorAll('.schedule-fields').forEach(function (el) {
            el.style.display = el.dataset.type.split(' ').indexOf(select.value) !== -1 ? '' : 'none';
        });
    }
    select.addEventListener('change', toggle);
    toggle();
})();
</script>
