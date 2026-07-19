# Changelog

## 0.2

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
