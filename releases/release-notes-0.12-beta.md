# php-budget 0.12-beta

This is a **beta pre-release**. Back up the database and configuration before
installing or upgrading. The release uses tag `v0.12-beta` and archive
`php-budget-0.12-beta.tar.gz`.

## Highlights

- More consistent sign-in limits and safer setup for new installations.
- A stricter browser content security policy and local scripts for forms and
  dashboard controls.
- Better validation of SMTP settings and clearer reminder failure reporting.
- More consistent paycheck and bill allocations when settings or bills change,
  with paid history preserved when editing one-time bills.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, and Argon2 support
- MySQL 5.7+ or MariaDB 10.2+
- Apache with `mod_rewrite`, or Nginx with PHP-FPM and `public/` as the document
  root
- An SMTP relay for reminder emails
- OpenSSL AES-256-GCM and a valid base64 `app_key` decoding to 32 bytes for
  saving SMTP passwords

Composer is not required when using the release tarball because production
dependencies, including PHPMailer, are bundled.

## New installation

1. Extract `php-budget-0.12-beta.tar.gz` on the server.
2. Copy `config.sample.php` to `config.php` and configure the database,
   `base_url`, timezone, and a private random `setup_token`. Generate one with
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Create the database if
   the configured database user cannot do so. Set a valid `app_key` as shown
   in the sample before saving SMTP passwords.
3. Point the web server at `public/` (recommended), or use the Apache flat
   deployment documented in the README. Do not deploy flat on Nginx.
4. Open `install.php`, enter the private setup token, and create the first
   administrator account.
5. Remove `setup_token` from `config.php`, configure email and the reminder
   cron job (`0 6 * * * php /path/to/php-budget/bin/send_reminders.php`), and
   optionally delete `public/install.php`.

## Upgrading

The same steps apply to 0.11-beta and 0.10-beta:

1. Securely back up the database and `config.php`. Preserve the existing
   `app_key`: saved SMTP passwords depend on it.
2. Extract `php-budget-0.12-beta.tar.gz` over the existing installation. The
   archive excludes `config.php`; do not replace it with the sample.
3. Set `trusted_proxies` in `config.php` only if you rely on forwarded HTTPS
   metadata. List the exact IP addresses of the connecting proxies and make
   sure they overwrite `X-Forwarded-Proto`. Set `mail.allowed_smtp_hosts` only
   if you want to restrict relay choices, including for reminder cron. List
   the exact allowed SMTP hostnames or IP addresses; unset or empty leaves
   relay choices unrestricted.
4. Passwords longer than 1,024 bytes must be reset. Reset member passwords
   from Users; if the owner cannot sign in, see the README's operator guidance.
   Resetting a password invalidates only that account's existing sessions.

Schema version stays 9. There is no database migration, release-wide forced
sign-in, or token reset. For older installations, follow the applicable earlier
upgrade instructions in the README.
