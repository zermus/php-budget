# php-budget 0.7-beta

This is a **beta pre-release**. Back up the database before installing or
upgrading. The GitHub release must use tag `v0.7-beta`, attach
`php-budget-0.7-beta.tar.gz`, and be published with `prerelease=true`.

## Highlights

- **Protected first-time setup.** New installations require the private
  `setup_token` configured in `config.php` before the first administrator can
  be created. Setup is serialized and creates the schema, owner, and settings
  atomically, preventing a public visitor from claiming an unfinished install.
- **Non-destructive pay-schedule changes.** Changing weekly, biweekly,
  semimonthly, or monthly schedules now reconciles generated dates in place.
  Paid history, allocations, split or reassigned bills, skipped occurrences,
  edited amounts, paycheck overrides, and every-N-paycheck phase are retained.
- **Explicit paycheck overrides.** New overrides are tracked directly. The
  upgrade identifies legacy overrides whose amounts differ from the current
  default; a legacy override equal to that default cannot be distinguished
  from a generated value.

Protected paychecks that do not occur on the replacement schedule remain
visible rather than being deleted, so associated financial data is not lost.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, and Argon2 support
- MySQL 5.7+ or MariaDB 10.2+
- Apache with `mod_rewrite`, or Nginx with PHP-FPM and `public/` as the document
  root
- An SMTP relay for reminder emails

Composer is not required when using the release tarball because production
dependencies are bundled.

## New installation

1. Extract `php-budget-0.7-beta.tar.gz` on the server.
2. Copy `config.sample.php` to `config.php` and configure the database,
   `base_url`, timezone, and a random `setup_token`. Generate one with:
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
3. Point the web server at `public/` (recommended), or use the documented
   Apache flat-deploy mode.
4. Open `install.php`, enter the setup token, and create the first account.
5. Remove `setup_token` from `config.php`, configure the reminder cron job, and
   optionally delete `public/install.php`.

## Upgrade from 0.6-beta

1. Back up the database and `config.php`.
2. Extract the new tarball over the existing installation. The release archive
   does not contain `config.php`.
3. Sign in as an administrator and open `install.php`.
4. Run the schema upgrade from version 6 to 7.
5. Review upcoming paychecks and allocations before changing the pay schedule.

Existing installations do not need a setup token to upgrade.

## Validation limitations

The schedule and authorization regression suite and PHP syntax checks cover the
non-database paths. The database integration suite requires a disposable
MySQL/MariaDB server plus `pdo_mysql`; when those prerequisites are unavailable,
concurrent setup locking, transaction rollback, migration 007, and preservation
against real database constraints remain unverified. Release publication should
not imply that these database paths were tested unless the integration section
actually ran rather than reporting `SKIPPED`.
