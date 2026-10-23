# php-budget 0.8-beta

This is a **beta pre-release**. Back up the database and configuration before
installing or upgrading. The GitHub release must use tag `v0.8-beta`, attach
`php-budget-0.8-beta.tar.gz`, and be published with `prerelease=true`.
Release notes authorize no publication or deployment: only the authorized
pipeline may merge, rebuild, tag, and publish after approval. No deployment is
part of this release preparation.

## Highlights

- **Isolated SMTP profiles.** SMTP connection details and credentials now come
  entirely from either the account profile or the server configuration, never
  a mixture. Existing partial account profiles may need reconfiguration.
  Review Settings → Email and verify delivery after upgrading.
- **Safer SMTP password changes.** Changes to the host, port, encryption, or
  username require a replacement password instead of reusing the old one.
  For an explicitly unauthenticated relay, clear both username and password.
  New password writes require supported encryption; failures reject the save
  rather than storing plaintext. An unreadable encrypted password must be
  replaced or explicitly cleared. Preserve the existing `app_key`.
- **Unpaid-bill carryovers.** Unpaid, non-skipped stored allocations on older
  paychecks move and merge once into the latest paycheck on or before today.
  Paid allocations and future split portions stay put; original due dates
  remain unchanged. Ungenerated occurrences are not backfilled.

Red carryover labels show the earliest source paycheck date. Carryovers change
historical paycheck totals, and there is no transfer ledger. Manual splits or
reassignments clear carryover provenance. Payment still applies to the whole
occurrence, including split portions: Bills decreases while Remaining does not
increase because the money stays allocated.

Schema 8 is required. Migration 008 adds nullable
`allocations.carried_from_date` to record carryover provenance.

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

1. Extract `php-budget-0.8-beta.tar.gz` on the server.
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

## Upgrade from 0.7-beta

1. Securely back up the database and `config.php`, including its existing
   `app_key`. Do not replace that key: stored SMTP passwords depend on it.
2. Extract the new tarball over the existing installation, excluding
   `config.php`. The release archive does not contain that file; retain the
   existing configuration.
3. Sign in as an administrator and open `install.php` (restore the installer
   from the archive if it was previously removed).
4. Run the schema upgrade from version 7 to 8.
5. Review SMTP settings and verify delivery, especially for partial account
   profiles. Replace passwords when changing connection details or explicitly
   clear both username and password for an unauthenticated relay.
6. Review carryovers and historical paycheck totals with the behavior above
   in mind before relying on the updated budget.

Existing installations do not need a `setup_token` to upgrade.

## Validation limitations

### Not verified

- MySQL compatibility (database integration is exercised with disposable
  MariaDB, not MySQL).
- PHP versions other than 8.4, including the supported PHP 8.3 minimum.
- Interactive browser/JavaScript behavior and browser-driven upgrade
  interactions.
- Real SMTP delivery. `tests/smtp_security_test.php` unconditionally prints a
  local SMTP skip message; passing assertions do not prove delivery.
- Deployment environments; no deployment is performed.

These accepted gaps must not be presented as end-to-end mail, browser, or
production-environment validation. Investigation and candidate-build results
are not tests of the committed final release revision; the pipeline must run
its own release checks before publishing.
