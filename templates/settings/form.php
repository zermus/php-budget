<?php
use App\Auth;
use App\Csrf;
use App\Secrets;

$type = $old['scheduleType'] ?? (string) ($settings['schedule_type'] ?? 'biweekly');
$passwordUnreadable = $settings['smtp_password_unreadable'];
$days = json_decode((string) ($settings['days_of_month'] ?? '[]'), true) ?: [];
$value = static fn (string $key, mixed $fallback): string => $old[$key] ?? (string) $fallback;
?>
<div class="container narrow">
    <h2>Settings</h2>

    <?php if (!Auth::canManageAccount()): ?>
        <p class="empty-note">
            You are signed in as a <?= e(strtolower(Auth::roleLabel())) ?> on this budget.
            The pay schedule, dashboard window, and email settings are managed by an account
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
                   value="<?= e($value('anchorDate', $settings['anchor_date'] ?? '')) ?>">
        </div>

        <div class="field schedule-fields" data-type="semimonthly">
            <label>Days of the month (e.g. 1 and 15; 31 clamps to shorter months):</label>
            <input type="number" name="semiDay1" min="1" max="31" value="<?= e($value('semiDay1', $days[0] ?? 1)) ?>">
            <input type="number" name="semiDay2" min="1" max="31" value="<?= e($value('semiDay2', $days[1] ?? 15)) ?>">
        </div>

        <div class="field schedule-fields" data-type="monthly">
            <label for="monthDay">Day of the month (31 clamps to shorter months):</label>
            <input type="number" id="monthDay" name="monthDay" min="1" max="31"
                   value="<?= e($value('monthDay', $settings['day_of_month'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="defaultIncome">Default paycheck amount ($):</label>
            <input type="text" id="defaultIncome" name="defaultIncome" inputmode="decimal" required
                   value="<?= e($value('defaultIncome', $settings['default_income'] ?? '0.00')) ?>">
        </div>

        <h3>Dashboard</h3>
        <div class="field">
            <label for="windowDays">Look ahead this many days (14&ndash;365; 9 paychecks per page):</label>
            <input type="number" id="windowDays" name="windowDays" min="14" max="365"
                   value="<?= e($value('windowDays', $settings['window_days'] ?? 90)) ?>">
        </div>

        <h3>Reminders</h3>
        <div class="field">
            <label for="reminderLeadDays">Email me this many days before a bill is due:</label>
            <input type="number" id="reminderLeadDays" name="reminderLeadDays" min="0" max="30"
                   value="<?= e($value('reminderLeadDays', $settings['reminder_lead_days'] ?? 1)) ?>">
        </div>

        <h3>Email</h3>
        <?php if (Auth::isOwner()): ?>
        <?php $transport = (string) $value('mailTransport', $settings['effective_transport']); ?>
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
                   value="<?= e($value('mailFrom', $settings['mail_from'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="mailFromName">From name:</label>
            <input type="text" id="mailFromName" name="mailFromName" placeholder="Budget App"
                   value="<?= e($value('mailFromName', $settings['mail_from_name'] ?? '')) ?>">
        </div>

        <p class="empty-note">SMTP settings are one complete profile: any account SMTP override disables
            all server SMTP fallbacks. Supply the host, port, encryption and any authentication together.
            Existing mixed account/server configurations may need reconfiguration.</p>
        <div class="mail-fields">
            <div class="field two-up">
                <div>
                    <label for="smtpHost">SMTP host:</label>
                    <input type="text" id="smtpHost" name="smtpHost" placeholder="127.0.0.1"
                           value="<?= e($value('smtpHost', $settings['smtp_host'] ?? '')) ?>">
                </div>
                <div class="port-col">
                    <label for="smtpPort">Port:</label>
                    <input type="number" id="smtpPort" name="smtpPort" min="1" max="65535" placeholder="25"
                           value="<?= e($value('smtpPort', $settings['smtp_port'] ?? '')) ?>">
                </div>
            </div>

            <div class="field">
                <label for="smtpEncryption">Encryption:</label>
                <?php $enc = $value('smtpEncryption', $settings['effective_encryption']); ?>
                <select id="smtpEncryption" name="smtpEncryption">
                    <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None — plain, never upgrades to TLS</option>
                    <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>STARTTLS (usually port 587)</option>
                    <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL/TLS (usually port 465)</option>
                </select>
            </div>

            <div class="field">
                <label for="smtpUsername">SMTP username (blank for an open local relay):</label>
                <input type="text" id="smtpUsername" name="smtpUsername" autocomplete="off"
                       value="<?= e($value('smtpUsername', $settings['smtp_username'] ?? '')) ?>">
            </div>
            <div class="field">
                <p class="empty-note">Blank retains only the saved account password, and only when host,
                    port, encryption and username are unchanged. Changing any of these requires a replacement
                    password. To remove authentication, blank the username and check Remove the saved password.
                    Server passwords are never copied into account settings.</p>
                <label for="smtpPassword">SMTP password:</label>
                <input type="password" id="smtpPassword" name="smtpPassword" autocomplete="new-password"
                       placeholder="<?= $settings['smtp_password_saved'] ? 'unchanged — type to replace' : '' ?>">

            </div>
        </div>

        <?php if (!Secrets::available()): ?>
        <div class="message warning">
            Saving passwords requires a valid <code>app_key</code> in config.php and OpenSSL
            with AES-256-GCM support. See config.sample.php for key generation instructions.
            Back up the key securely with your database backups. Existing legacy passwords
            are migrated when encryption becomes available.
        </div>
        <?php endif; ?>
        <?php if ($passwordUnreadable): ?>
        <div class="message error">
            The saved SMTP password can't be decrypted — <code>app_key</code> in config.php has
            changed or is unavailable. Restore the original key, replace the password after fixing
            encryption, or clear both username and password for an unauthenticated relay.
        </div>
        <?php endif; ?>
            <?php if ($settings['smtp_password_saved']): ?>
            <label class="inline-check">
                <input type="checkbox" id="smtpPasswordClear" name="smtpPasswordClear" value="1">
                Remove the saved password
            </label>
            <?php endif; ?>
        <?php else: ?>
        <p class="empty-note">Only the owner can edit the mail profile. Test messages use the saved profile.</p>
        <?php endif; ?>

        <p class="empty-note">Changing the schedule moves upcoming generated dates in place where
            possible. Paid history, allocations, skips, and amount overrides are kept.</p>

        <button type="submit" class="btn primary">Save Settings</button>

        <button type="submit" class="btn" formaction="<?= e(url('/settings/test-email')) ?>">Send test email</button>
        <span class="empty-note">Sends to <?= e($user['email']) ?>.</span>
    </form>

    <form method="post" action="<?= e(url('/settings')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="reminders">
        <label class="inline-check">
            <input type="checkbox" name="receiveReminders" value="1" <?= $user['receive_reminders'] ? 'checked' : '' ?>>
            Send bill reminders to me
        </label>
        <button type="submit" class="btn">Save Reminder Preference</button>
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
<script src="<?= e(asset('js/settings-form.js')) ?>"></script>
