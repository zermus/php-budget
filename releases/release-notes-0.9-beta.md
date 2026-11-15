# php-budget 0.9-beta

This is a **beta pre-release**. Back up the database and configuration before
installing or upgrading. The GitHub release must use tag `v0.9-beta`, attach
`php-budget-0.9-beta.tar.gz`, and be published with `prerelease=true`.
Release notes authorize no publication or deployment: only the authorized
pipeline may merge, rebuild, tag, and publish after approval. No deployment is
part of this release preparation.

## Highlights

- **Application-specific sessions.** php-budget uses its own session cookie.
  Existing users must sign in again after upgrading.
- **Consistent sign-in accounting.** Failed sign-ins count consistently for
  equivalent spellings of an account email address.

Multiple php-budget installations on one host still share the cookie name and
root path; their sessions are not isolated from each other.

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

1. Extract `php-budget-0.9-beta.tar.gz` on the server.
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

## Upgrading from 0.8-beta

1. Securely back up the database and `config.php`, including its existing
   `app_key`. Do not replace that key: stored SMTP passwords depend on it.
2. Extract the new tarball over the existing installation while preserving
   `config.php`. The archive does not contain that file; retain the existing
   configuration and key.
3. Sign in again. Existing sessions are not carried over to the new cookie.

There is no schema migration: schema version remains 8. No new configuration
keys or requirement changes are needed. Existing installations do not need a
`setup_token` to upgrade.

## Validation limitations

Candidate checks passed on PHP 8.4 with disposable MariaDB: the five existing
suites (179 checks), the version-consistency test (9 checks), its temporary
mismatch negative control, PHP lint, and `git diff --check`. The release build
produced `php-budget-0.9-beta.tar.gz`; extraction verified the runtime version,
README, changelog, bundled production dependencies, and absence of `config.php`.

Headless Chromium passed login and installer checks (two clean pages), plus
authenticated dashboard sign-in and footer checks (six steps, no findings).
Extracting the candidate over an actual v0.8-beta checkout preserved `config.php`
and its `app_key`, kept schema 8 without migrations, and required fresh sign-in
with the old authenticated cookie. Fresh sign-in after extraction also passed.

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
