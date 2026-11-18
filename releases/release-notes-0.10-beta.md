# php-budget 0.10-beta

This is a **beta pre-release**. Back up the database and configuration before
installing or upgrading. The GitHub release must use tag `v0.10-beta`, attach
`php-budget-0.10-beta.tar.gz`, and be published with `prerelease=true`.
The approved pipeline merges, rebuilds, tags and publishes from the approved
merge commit. Deployment is excluded from this release.

## Highlights

- New and reactivated recurring bills start today, without earlier overdue
  dates. Ordinary edits do not reset the start; existing bills retain their
  behavior and one-time overdue dates remain supported.
- Schedule changes remove obsolete future unpaid dates and reallocate unpaid
  bills in full. Read the warning under Upgrading before changing a schedule.
- Saving schedule and default income together updates all non-overridden future
  checks. Allocation editing includes the current paycheck and selected dates
  outside the display window.
- Reminders continue for other budgets after a failure, report failures and
  exit unsuccessfully. Oversized inputs receive validation messages without
  partial saves. Passwords accept Unicode and whitespace while retaining the
  eight-character lowercase/uppercase/digit/special policy.
- Sign-in renews CSRF tokens; unissued session IDs are replaced. Cookie paths
  follow `base_url`, so users may need to sign in again.

Root installations use `/`; non-overlapping subdirectory installations have
separate cookie paths. Paths are not a same-origin security boundary.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, and Argon2 support
- MySQL 5.7+ or MariaDB 10.2+
- Apache with `mod_rewrite`, or Nginx with PHP-FPM and `public/` as the document
  root
- An SMTP relay for reminder emails
- OpenSSL AES-256-GCM and a valid base64 `app_key` decoding to 32 bytes for
  new SMTP password writes

Composer is not required when using the release tarball because production
dependencies, including PHPMailer, are bundled.

## New installation

1. Extract `php-budget-0.10-beta.tar.gz` on the server.
2. Copy `config.sample.php` to `config.php` and configure the database,
   `base_url`, timezone, and a random `setup_token`. Generate one with:
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
   Configure a valid `app_key` before saving SMTP passwords.
3. Point the web server at `public/` (recommended), or use the documented
   Apache flat-deploy mode in the README. Do not use flat deployment on Nginx.
4. Open `install.php`, enter the private setup token, and create the first
   administrator account.
5. Remove `setup_token` from `config.php`, configure email and the reminder
   cron job (`0 6 * * * php /path/to/php-budget/bin/send_reminders.php`), and
   optionally delete `public/install.php`.

## Upgrading from 0.9-beta

1. Securely back up the database and `config.php`, including its existing
   `app_key`. Do not replace that key: stored SMTP passwords depend on it.
2. Extract the new tarball over the existing installation while preserving
   `config.php`. The archive does not contain that file; retain the existing
   configuration and key.
3. Restore `public/install.php` from the archive if you deleted it. Sign in as
   an administrator, open `install.php`, and run the schema 8 to 9 upgrade.
   Migration `009_bill_starts_on.php` adds nullable `bills.starts_on`, preserving
   existing bill behavior. The installer applies it; no separate upgrade script
   is needed. You may delete the installer afterward.
4. You may need to sign in again because cookie paths now follow `base_url`.
   No new configuration keys, setup token or requirement changes are needed.
   OpenSSL AES-256-GCM and a base64 key decoding to 32 bytes remain prerequisites
   for new SMTP password writes; preserve your existing key.

**Schedule-change warning:** splits, manual assignments, skips and edited amounts
on obsolete future unpaid dates are discarded, and unpaid bills reallocated in
full. Paid-protected dates and past history remain. Back up before upgrading
and review your schedule before changing it.

## Validation limitations

Candidate checks passed on PHP 8.4 with disposable MariaDB and Apache:
schedule 67, carry-forward 45, release-version 10, session-cookie 49,
login-throttle 20, and SMTP 72 checks (263 total). The faulty trailing-dot
hostname assertion was reproduced before correction. HTTP suites were rerun
with empty fixtures after an initial fixture-contamination failure. PHP lint
passed 59 files; shell syntax and `git diff --check` passed.

The build produced `php-budget-0.10-beta.tar.gz`. Extraction verified version
strings, bundled PHPMailer and autoloading, migration 009, and exclusion of
`config.php`. All archive directories are 0755 and files 0644; no shipped file
requires direct execution (cron invokes PHP). Keep deployed configuration and
private backups restricted. An unprivileged user read/traversed extracted files
and a permission-preserving copy. Use permission-preserving extraction when a
restrictive umask would otherwise mask the archive modes. Notes are trackable
and the archive is ignored by Git.

Headless Chromium reported `PASS, 2 page(s) clean`, plus `PASS, 12 steps, no
findings` for fresh installation, login and the 0.10-beta dashboard footer.
An actual v0.9-beta tree was installed at schema 8, then overlaid with the
candidate archive. The authenticated installer upgrade reported `PASS, 14
steps, no findings`, including the anonymous upgrade gate and updated footer.
Configuration/key remained byte-identical; all eight existing bills survived
with NULL starts_on. Schema 9, no-op migration rerun and direct migration 009
rerun passed. Initial browser fixture errors (an old-policy-incompatible test
password and a generic selector submitting logout) were corrected before the
successful upgrade run; no production change was needed.

### Not verified

- PHP versions other than 8.4, including the supported PHP 8.3 minimum.
- MySQL compatibility; database checks used disposable MariaDB, not MySQL.
- Secure cookie behavior over real HTTPS; local browser checks used HTTP.
- Real SMTP delivery; the SMTP suite reports delivery as skipped, even with
  bundled PHPMailer present. Its passing assertions do not prove delivery.
- Production deployment; this preparation does not deploy the application.

These are candidate checks, not verification of a future merge commit or
published release. The authorized pipeline must perform its release checks.
No zip is produced by the build script, so zip verification is not applicable.
