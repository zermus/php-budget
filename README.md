# php-budget

A simple self-hosted paycheck budgeting app. Version 0.2.

Money is modeled the way a paycheck-to-paycheck budget actually works:
recurring paychecks are allocated to bills. The dashboard shows a rolling
window of upcoming paychecks (14–365 days ahead, your choice; default 90),
what each one pays, and what's left over. Bills get a paid checkbox, nightly email reminders cover anything due
soon or overdue, and a third biweekly check landing in one calendar month is
flagged with a "Wave" badge — the extra check of a three-paycheck month.

There is no bank sync, but paying a bill goes through a single
`markPaid(occurrence, user, source)` seam (`source` is `manual` or `sync`),
so a future sync can plug in without touching the UI.

Download the latest release tarball from the releases directory — it bundles
all dependencies, so Composer is not required on the server.

## Requirements

- PHP 8.3+ with `pdo_mysql` and `mbstring` (and argon2 support, which the
  standard distribution packages include)
- MySQL 5.7+ / MariaDB 10.2+
- Apache (with `mod_rewrite`) or Nginx + PHP-FPM
- An SMTP relay for reminder emails (a local Postfix on 127.0.0.1:25 works)

## Directory layout

```
php-budget/
├── public/            <- document root (index.php, install.php, assets/)
├── src/               <- application code (kept outside the web root)
├── templates/
├── migrations/
├── bin/               <- send_reminders.php cron script
├── vendor/            <- bundled dependencies (PHPMailer)
├── config.sample.php  <- copy to config.php
└── .htaccess          <- flat-deploy shim (Apache only)
```

## Installation

1. Extract the tarball on your server.
2. Copy `config.sample.php` to `config.php` and fill in the database
   credentials, `base_url`, timezone, and mail settings. The database user
   needs permission to create the database (or create it yourself first).
3. Point your web server at the app (see Deployment modes below).
4. Open `https://your.site/install.php` in a browser. The installer creates
   the schema, asks for your email and password (the first user account),
   and can optionally seed a starter budget.
5. Add the reminder cron entry (shown by the installer, see below), then
   delete `public/install.php` if you like — it refuses to reinstall either
   way.

## Deployment modes

**Recommended: document root = `public/`.** Application code, templates,
config, and vendor libraries stay outside the web root. Nginx example:

```nginx
server {
    listen 443 ssl;
    server_name budget.your.website.com;
    root /opt/php-budget/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php-fpm/www.sock;
    }
}
```

**Flat deploy (Apache only):** extract the whole tree into a web directory
(e.g. `/var/www/html/budget/`). The bundled `.htaccess` files rewrite
requests into `public/` and deny access to `src/`, `config.php`, and the
other internals. Do not deploy flat on Nginx — it does not read `.htaccess`,
so the internals would be exposed.

## Reminder cron

```
0 6 * * * php /path/to/php-budget/bin/send_reminders.php
```

Runs nightly: each user gets one summary email of unpaid bills that are due
within their reminder lead time (Settings, default 1 day) or overdue. No
send-tracking is kept — a bill still unpaid tomorrow simply appears in the
next day's summary.

## Email

`config.php` supports three transports under `mail.transport`:

- `smtp` (default) — relay via `mail.smtp.*`; ships pointed at
  `127.0.0.1:25` with no auth, which suits a local Postfix/Sendmail.
  Authenticated SMTP with TLS is supported via the `username`, `password`,
  and `encryption` settings.
- `mail` — PHP's `mail()`.
- `log` — append messages to `mail.log_path` instead of sending (testing).

Each user can also set a personal SMTP relay (`host` or `host:port`, no
auth) in Settings; the reminder cron uses it for that user's email.

## Pay schedules and bills

- **Schedules:** weekly and biweekly repeat from an anchor pay date;
  semimonthly pays on two fixed days of the month; monthly on one. Days past
  the end of a short month clamp to its last day.
- **Wave checks:** with a biweekly schedule, a month containing three
  paychecks gets its third check flagged with a "Wave" badge on the
  dashboard. The badge marks the schedule's extra check — whether it ends up
  with money to spare depends on what gets allocated to it. Semimonthly and
  monthly schedules can never have one.
- **Bills** recur monthly on a day, every N paychecks, or once. An
  every-N-paychecks bill is anchored to the first paycheck it applies to,
  which fixes its phase — e.g. two sets of bills alternating between
  biweekly checks are two anchors one paycheck apart.
- Editing a bill's amount or recurrence, or changing the pay schedule,
  rebuilds upcoming unpaid occurrences; paid history is always kept.

## Upgrading from a previous version

Extract the new tarball over the old directory (your `config.php` is never
part of a release) and open `install.php` — it detects an older schema and
applies migrations. Back up your database first.

## License

MIT — see LICENSE. © 2026 Cody Gee.
