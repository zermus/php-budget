# php-budget 0.11-beta

This is a **beta pre-release**. Back up the database and configuration before
installing or upgrading. The GitHub release must use tag `v0.11-beta`, attach
`php-budget-0.11-beta.tar.gz`, and be published with `prerelease=true`.
The approved pipeline must publish only from its checked merge commit and attach
the rebuilt tarball. Deployment is excluded from this release preparation.

## Highlights

- Includes the already-merged fix for paying, skipping and editing bills while
  someone else in the household loads the dashboard at the same moment. These
  simultaneous operations no longer fail with the server error addressed by
  that fix; this release preparation adds no separate application behavior.

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

1. Extract `php-budget-0.11-beta.tar.gz` on the server.
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

## Upgrading from 0.10-beta

1. Securely back up the database and `config.php`, including its existing
   `app_key`. Do not replace that key: stored SMTP passwords depend on it.
2. Extract `php-budget-0.11-beta.tar.gz` over the existing installation while
   preserving `config.php` and all existing configuration. The archive excludes
   that file; do not replace it with the sample.
3. There are no special upgrade steps. Schema version stays 9: no migrations,
   new configuration keys or new requirements are introduced. This release
   introduces no forced sign-in or session/token invalidation.

For older installations, follow the earlier upgrade instructions in the README.

## Validation limitations

Reported baseline evidence (before these release changes): six suites with 254
assertions and PHP 8.4 lint of 60 files, shell syntax, whitespace and baseline
archive checks. Baseline browser checks displayed the old 0.10-beta footer;
they are not candidate evidence.

Candidate checks passed on PHP 8.4 with disposable MariaDB and local Apache:
release-version 10, schedule 67, carry-forward/concurrency 57 (including the
eight-process/five-second stress check), session-cookie HTTP 49, login-throttle
HTTP 20 and SMTP 72 assertions (275 total). HTTP suites were rerun successfully
on an empty database after initial leftover-fixture failures. PHP lint passed
60 files; shell syntax and `git diff --check` passed.

The build produced `php-budget-0.11-beta.tar.gz`. Extraction verified runtime
and README versions, bundled autoloading/PHPMailer, exclusion of `config.php`,
and usable directory 0755/file 0644 permissions. No zip is produced or required.
The archive is ignored and these release notes are trackable. Remaining
0.10-beta references describe history, baseline evidence or the upgrade source.

Headless Chromium reported `PASS, 2 page(s) clean` for candidate login/installer
and `PASS, 11 steps, no findings` for fresh installation, login and the rendered
`php-budget v0.11-beta` dashboard footer. A built v0.10-beta tree was installed
with eight seeded bills, then overlaid with the candidate tarball. The upgrade
browser flow reported `PASS, 6 steps, no findings`, including login, the updated
footer and installer access. Configuration/key stayed byte-identical, existing
data remained unchanged, schema stayed 9 and a migration rerun was a no-op.
The initial overlay browser check saw cached old PHP code; restarting local
Apache before the successful rerun refreshed its opcode cache.

### Not verified

- PHP versions other than 8.4, including PHP 8.3: not installed here.
- MySQL compatibility: database checks used disposable MariaDB.
- Secure cookie behavior over real HTTPS: local checks used HTTP.
- Real SMTP delivery: log transport was tested, not an external relay.
- Nginx and Apache flat deployment: checks used Apache with `public/` docroot.
- Production deployment: excluded from this release preparation.
- Future checked merge commit and published release: the pipeline must rebuild
  and publish the beta tarball from that commit, not this working-tree archive.

These are candidate results, separate from the reported baseline evidence.
