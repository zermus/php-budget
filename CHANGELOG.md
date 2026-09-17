# Changelog

## Unreleased

Security hardening. Requires a database upgrade (sign in as an
administrator, then open install.php).

### Security

- **Script injection through a user's email address.** The Users page put
  each email into an inline `confirm()` on the Remove button. HTML escaping
  doesn't protect a JavaScript string there (the browser decodes entities
  before running the handler), and email validation allows `'` and
  backticks, so an administrator-created address such as
  ``a'-alert`1`-'@example.com`` ran script for any administrator viewing
  the page. Legitimate addresses like `o'brien@…` also broke the
  confirmation, deleting the user without asking. The message is now a
  properly escaped JavaScript literal.
- **Sign-in throttling.** Failed logins are counted per email + IP, per IP,
  and per email over 15 minutes (new `login_attempts` table); past the
  limit the password isn't even checked. Unknown emails now take the same
  Argon2id work as a wrong password, so timing no longer reveals which
  addresses have accounts.
- **Password changes end other sessions.** Sessions remember a fingerprint
  of the password hash they signed in with; changing your password signs
  out your other devices, and an administrator's password reset signs that
  user out everywhere. (Everyone is signed out once after upgrading.)
- **Database upgrades require a signed-in administrator.** Previously
  anyone could run pending migrations from install.php as soon as new files
  were extracted, before the owner had taken a backup.
- **Security headers:** framing is forbidden (`X-Frame-Options`, CSP
  `frame-ancestors`), plus `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: same-origin`.
- **Flat deploys:** `.htaccess` now also denies `*.log` files (`mail.log`
  holds reminder emails with the log transport) and blocks internal
  directories even when mod_rewrite is unavailable.

### Fixed

- **config.php mail settings were silently ignored after upgrading to
  0.4.** The new transport and encryption columns defaulted to `smtp` and
  `none`, which always beat the config.php fallback, so an install that
  used STARTTLS/SSL or the `mail`/`log` transport in config.php quietly
  switched to plain, unencrypted SMTP. The columns now default to NULL,
  accounts that never set email in the app follow config.php again, the
  Settings form shows the values actually in effect, and saving the form
  no longer pins config.php's values into the database.
- With no From address set anywhere, every email failed with "Invalid
  address: budget@localhost" (PHPMailer rejects a domain without a dot).
  The default is now `budget@` + the host in `base_url`, or
  `budget@localhost.localdomain` when that host is an IP or has no dot.
- A saved SMTP password can now be removed (Settings → Email).
- Editing a paycheck amount typed with a thousands separator (`1,250`)
  showed `NaN` until the page was reloaded.
- A bill occurrence could be set to $0.00 inline, after which it could
  never be reassigned or split (splits must be positive). Zero is refused
  with a pointer to Skip, as it is when adding a bill.
- Inline amount edits for an occurrence or paycheck outside your budget
  reported success; they now return "not found".

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
