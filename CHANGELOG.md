# Changelog

## 0.5-beta

Two more roles. Requires a database upgrade (open install.php).

### Functionality

- **Administrator** can now be granted to another user, giving the same
  access as the account owner — including settings and user management.
  The owner's account stays protected: it can't be edited or removed from
  the Users page, and no administrator can change or delete their own
  account there.
- **Budgeter**: a user who runs the budget but not the account. They can
  add, edit, and remove bills, reassign and split bills across paychecks,
  edit occurrence amounts and paycheck income, and tick bills paid — but
  not touch the pay schedule, email settings, or users.
- Permissions are now capability-based internally (manage account / manage
  bills / mark paid) rather than a single admin flag, so each route and
  every control on the dashboard follows the same rules.

## 0.4-beta

Email moves into the app. Requires a database upgrade (open install.php).

### Fixed

- **SMTP "None" now means none.** PHPMailer opportunistically upgraded to
  STARTTLS whenever a relay advertised it, so a local relay with broken or
  self-signed TLS failed with "STARTTLS command failed" even though
  encryption was set to none. Encryption "None" now disables the automatic
  upgrade outright.
- **Stale CSS/JS after an upgrade.** Asset URLs carry the app version, so
  browsers pick up new styles and scripts immediately instead of serving a
  cached copy. (If the dashboard totals did not update live when ticking a
  bill paid in 0.3, this was why.)
- Save Settings and Send test email no longer crowd each other.

### Functionality

- **Email is configured in Settings**, not config.php: transport (SMTP relay
  / PHP mail() / log to file), from address and name, and SMTP host, port,
  encryption, username, and password. Send test email uses whatever is in
  the form, saved or not. Leaving the SMTP password blank keeps the stored
  one.
- config.php's `mail` block is now an optional fallback for fields left
  blank, so existing installs keep working untouched; new installs can drop
  it entirely except for `log_path`.
- An SMTP host previously saved as `host:port` is split into the new host
  and port fields automatically during the upgrade.

## 0.3-beta

Shared households, SMTP testing, and paid-aware totals. Requires a database
upgrade (open install.php).

### Functionality

- **Users:** the administrator can add household users who share the same
  budget — a **bill payer** who can tick bills paid, and a **read-only**
  user who can only look. Bills, the pay schedule, allocations, amounts,
  and user management stay with the administrator. Each user has their own
  login and can change their own password; the admin can reset it.
- **Send test email** button in Settings, which uses the SMTP relay
  currently typed in the form (saved or not) and reports the actual failure
  reason (e.g. "Could not connect to SMTP host") instead of failing
  silently. SMTP now times out after 10 seconds rather than hanging.
- Reminder emails have a per-user on/off switch, so a payer can receive the
  nightly summary while a read-only user does not.
- Ticking a bill paid now deducts it from that paycheck's **Bills** total,
  which counts down to $0 as the check is paid out, with a "· $X paid"
  note. **Remaining** is unchanged — it still nets out every allocated
  bill, paid or not, so it keeps showing real leftover money.

## 0.2-beta

Dashboard usability release. Requires a database upgrade (open install.php).

### Functionality

- Configurable dashboard window: look ahead 14–365 days (Settings; default
  90). The dashboard paginates at 9 paycheck cards per page, and long bill
  lists scroll inside their card.
- Bills within each paycheck card can be ordered largest-first (default),
  smallest-first, or by due date; the choice is remembered.
- A bill allocated to a paycheck that lands after its due date shows its
  due date in red.
- Amounts are edited with a single click (was double-click).
- Dollar-sign favicon and a version footer on the dashboard.

## 0.1

Initial release.

### Features

- Rolling ~90-day dashboard of upcoming paychecks, each showing its allocated
  bills, a paid checkbox per bill, the bills total, and the remaining amount.
- Configurable per-user pay schedule: weekly, biweekly, semimonthly (two fixed
  days), or monthly (one fixed day), with month-length clamping (a day of 31
  falls on Feb 28/29).
- Wave-check detection: a third biweekly paycheck landing in one calendar
  month is flagged with a "Wave" badge.
- Bills with three recurrence types: monthly on a day, every N paychecks
  (anchored to a first paycheck, so two bill sets can alternate), and one-time.
- Automatic allocation of each bill occurrence to the latest paycheck on or
  before its due date, with manual reassignment and splitting across
  paychecks.
- Per-occurrence amount edits, paycheck income overrides, and skipping a
  single occurrence.
- Nightly email reminders of unpaid bills due soon or overdue, with a
  per-user SMTP relay override (default 127.0.0.1:25).
- Multi-user data model with argon2id password hashing, CSRF protection,
  hardened session cookies, and prepared statements throughout.
- Web installer with schema migrations and an optional starter budget seed.
