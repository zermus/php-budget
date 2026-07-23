# php-budget

A simple self-hosted paycheck budgeting app. Version 0.4-beta.

> **Beta:** releases are currently marked beta — the app works and is in
> daily use, but expect rough edges and back up your database before
> upgrades.

Money is modeled the way a paycheck-to-paycheck budget actually works:
recurring paychecks are allocated to bills. The dashboard shows a rolling
window of upcoming paychecks (14–365 days ahead, your choice; default 90),
what each one pays, and what's left over. Bills get a paid checkbox, nightly email reminders cover anything due
soon or overdue, and a third biweekly check landing in one calendar month is
flagged with a "Wave" badge — the extra check of a three-paycheck month.

There is no bank sync, but paying a bill goes through a single
`markPaid(occurrence, user, source)` seam (`source` is `manual` or `sync`),
so a future sync can plug in without touching the UI.

Download the latest release tarball from the
[Releases page](https://github.com/zermus/php-budget/releases) — it bundles
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
   credentials, `base_url`, and timezone. The database user needs permission
   to create the database (or create it yourself first). Email is configured
   later, in the app.
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

Email is configured in the app: sign in as the administrator and open
**Settings → Email**. There are three transports:

- **SMTP relay** — host, port, encryption, and optional username/password.
  A local Postfix/Sendmail is typically `127.0.0.1`, port `25`, encryption
  **None**. Choosing None disables opportunistic STARTTLS, so relays that
  advertise TLS but can't complete it still work.
- **PHP mail()** — hands off to the server's own sendmail.
- **Log to a file** — appends to `mail.log_path` and sends nothing; useful
  for testing.

**Send test email** sends to the signed-in administrator using whatever is
currently in the form — saved or not — and reports the real failure reason
(e.g. "Could not connect to SMTP host") if it doesn't work. SMTP gives up
after 10 seconds rather than hanging.

The `mail` block in `config.php` is an optional fallback used only for
fields left blank in Settings; `log_path` is the one setting that still
lives there.

The SMTP password is stored in the database in plain text, exactly as it
previously sat in `config.php` — treat database backups accordingly.

## Users

The account created by the installer is the **administrator**: it owns the
budget and manages bills, the pay schedule, allocations, and users. From
**Users**, the administrator can add other people who share that same budget:

| Role | Can do |
|---|---|
| Administrator | Everything: bills, schedule, allocations, amounts, users |
| Bill payer | View the dashboard and tick bills paid |
| Read only | View the dashboard only |

Each user signs in with their own email and password and can change their own
password under Settings. Reminder emails are per-user: switch them on or off
for anyone in the household from the Users page.

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

Apache License 2.0 — see LICENSE. © 2026 Cody Gee.
