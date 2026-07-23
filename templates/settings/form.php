<?php
use App\Auth;
use App\Csrf;

$type = (string) ($settings['schedule_type'] ?? 'biweekly');
$days = json_decode((string) ($settings['days_of_month'] ?? '[]'), true) ?: [];
?>
<div class="container narrow">
    <h2>Settings</h2>

    <?php if (!Auth::isAdmin()): ?>
        <p class="empty-note">
            You are signed in as a <?= Auth::role() === 'payer' ? 'bill payer' : 'read-only user' ?>
            on this budget. The pay schedule, bills, and reminders are managed by the account
            administrator; you can change your own password here.
        </p>
    <?php else: ?>

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

        <h3>Email</h3>
        <?php $transport = (string) ($settings['mail_transport'] ?? 'smtp'); ?>
        <div class="field">
            <label for="mailTransport">Send mail using:</label>
            <select id="mailTransport" name="mailTransport">
                <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>>SMTP relay</option>
                <option value="mail" <?= $transport === 'mail' ? 'selected' : '' ?>>PHP mail() — the server's own sendmail</option>
                <option value="log" <?= $transport === 'log' ? 'selected' : '' ?>>Log to a file — for testing, sends nothing</option>
            </select>
        </div>

        <div class="field">
            <label for="mailFrom">From address:</label>
            <input type="email" id="mailFrom" name="mailFrom" placeholder="budget@your.website.com"
                   value="<?= e((string) ($settings['mail_from'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="mailFromName">From name:</label>
            <input type="text" id="mailFromName" name="mailFromName" placeholder="Budget App"
                   value="<?= e((string) ($settings['mail_from_name'] ?? '')) ?>">
        </div>

        <div class="mail-fields">
            <div class="field two-up">
                <div>
                    <label for="smtpHost">SMTP host:</label>
                    <input type="text" id="smtpHost" name="smtpHost" placeholder="127.0.0.1"
                           value="<?= e((string) ($settings['smtp_host'] ?? '')) ?>">
                </div>
                <div class="port-col">
                    <label for="smtpPort">Port:</label>
                    <input type="number" id="smtpPort" name="smtpPort" min="1" max="65535" placeholder="25"
                           value="<?= e((string) ($settings['smtp_port'] ?? '')) ?>">
                </div>
            </div>

            <div class="field">
                <label for="smtpEncryption">Encryption:</label>
                <?php $enc = (string) ($settings['smtp_encryption'] ?? 'none'); ?>
                <select id="smtpEncryption" name="smtpEncryption">
                    <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None — plain, never upgrades to TLS</option>
                    <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>STARTTLS (usually port 587)</option>
                    <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL/TLS (usually port 465)</option>
                </select>
            </div>

            <div class="field">
                <label for="smtpUsername">SMTP username (blank for an open local relay):</label>
                <input type="text" id="smtpUsername" name="smtpUsername" autocomplete="off"
                       value="<?= e((string) ($settings['smtp_username'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="smtpPassword">SMTP password:</label>
                <input type="password" id="smtpPassword" name="smtpPassword" autocomplete="new-password"
                       placeholder="<?= !empty($settings['smtp_password']) ? 'unchanged — type to replace' : '' ?>">
            </div>
        </div>

        <p class="empty-note">Changing the schedule rebuilds upcoming paychecks and unpaid bill
            occurrences. Paid history is kept.</p>

        <button type="submit" class="btn primary">Save Settings</button>

        <div id="test-email-form-anchor"></div>
    </form>

    <form method="post" action="<?= e(url('/settings/test-email')) ?>" id="test-email-form">
        <?= Csrf::field() ?>
        <?php foreach (['mailTransport', 'mailFrom', 'mailFromName', 'smtpHost', 'smtpPort', 'smtpUsername', 'smtpPassword', 'smtpEncryption'] as $field): ?>
            <input type="hidden" name="<?= e($field) ?>" value="">
        <?php endforeach; ?>
        <button type="submit" class="btn">Send test email</button>
        <span class="empty-note">Sends to <?= e($user['email']) ?> using the email settings above,
            even if you haven't saved them yet.</span>
    </form>

    <hr>
    <?php endif; ?>

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
    if (!select) {
        return; // sub-users see the password form only
    }
    function toggle() {
        document.querySelectorAll('.schedule-fields').forEach(function (el) {
            el.style.display = el.dataset.type.split(' ').indexOf(select.value) !== -1 ? '' : 'none';
        });
    }
    select.addEventListener('change', toggle);
    toggle();

    // SMTP-only fields follow the transport choice.
    var transport = document.getElementById('mailTransport');
    var mailFields = document.querySelector('.mail-fields');
    function toggleMail() {
        mailFields.style.display = transport.value === 'smtp' ? '' : 'none';
    }
    transport.addEventListener('change', toggleMail);
    toggleMail();

    // Test with whatever is currently typed in, saved or not.
    var testForm = document.getElementById('test-email-form');
    testForm.addEventListener('submit', function () {
        ['mailTransport', 'mailFrom', 'mailFromName', 'smtpHost',
         'smtpPort', 'smtpUsername', 'smtpPassword', 'smtpEncryption'].forEach(function (id) {
            testForm[id].value = document.getElementById(id).value;
        });
    });
})();
</script>
