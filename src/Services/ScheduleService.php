<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use PDO;

final class ScheduleService
{
    /** Default forward window, in days, that paychecks and occurrences cover. */
    public const WINDOW_DAYS = 90;

    /** Dashboard sort options for the bills within a paycheck card. */
    public const SORT_OPTIONS = ['amount_desc', 'amount_asc', 'due_date'];

    /**
     * The user's forward window in days (Settings, clamped to 14–365).
     *
     * @param array<string, mixed> $settings
     */
    public static function windowDays(array $settings): int
    {
        return max(14, min(365, (int) ($settings['window_days'] ?? self::WINDOW_DAYS)));
    }

    /**
     * Pure pay-date engine: every pay date in [$from, $to] for a schedule.
     *
     * $settings uses the user_settings columns: schedule_type, anchor_date,
     * days_of_month (JSON array), day_of_month. Wave detection (the 3rd+
     * paycheck landing in one calendar month, biweekly only) is computed over
     * whole months internally, so flags are correct even for a mid-month $from.
     *
     * @param array<string, mixed> $settings
     * @return list<array{date: string, is_wave: bool}>
     */
    public static function payDates(array $settings, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $type = (string) ($settings['schedule_type'] ?? '');
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if ($from > $to) {
            return [];
        }

        // Generate over whole months so in-month ordinals (wave checks) are
        // right, then filter back down to the requested range.
        $genFrom = $from->modify('first day of this month');
        $genTo = $to->modify('last day of this month');

        $dates = match ($type) {
            'weekly'      => self::steppedDates($settings, 7, $genFrom, $genTo),
            'biweekly'    => self::steppedDates($settings, 14, $genFrom, $genTo),
            'semimonthly' => self::monthDayDates(self::daysOfMonth($settings), $genFrom, $genTo),
            'monthly'     => self::monthDayDates(
                [(int) ($settings['day_of_month'] ?? 0)],
                $genFrom,
                $genTo
            ),
            default       => [],
        };

        sort($dates);

        $byMonth = [];
        foreach ($dates as $date) {
            $byMonth[substr($date, 0, 7)][] = $date;
        }

        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        $result = [];
        foreach ($dates as $date) {
            if ($date < $fromStr || $date > $toStr) {
                continue;
            }
            $ordinal = (int) array_search($date, $byMonth[substr($date, 0, 7)], true);
            $result[] = [
                'date'    => $date,
                'is_wave' => $type === 'biweekly' && $ordinal >= 2,
            ];
        }

        return $result;
    }

    /** Number of schedule entries in [from, until), without enumerating history. */
    public static function countBefore(array $settings, DateTimeImmutable $from, DateTimeImmutable $until): int
    {
        if ($from >= $until) {
            return 0;
        }
        $type = $settings['schedule_type'] ?? '';
        if ($type === 'weekly' || $type === 'biweekly') {
            $step = $type === 'weekly' ? 7 : 14;
            $first = self::firstSteppedDate($settings, $step, $from);
            return $first !== null && $first < $until
                ? 1 + intdiv(self::calendarDays($first, $until) - 1, $step) : 0;
        }

        $days = self::validMonthDays(match ($type) {
            'monthly' => [(int) ($settings['day_of_month'] ?? 0)],
            'semimonthly' => self::daysOfMonth($settings),
            default => [],
        });
        $last = $until->modify('-1 day');
        $months = ((int) $last->format('Y') - (int) $from->format('Y')) * 12
            + (int) $last->format('n') - (int) $from->format('n');
        if ($months === 0) {
            return count(self::monthDayDates($days, $from, $last));
        }
        // Clamped days that coincide still occupy separate schedule slots.
        return count(self::monthDayDates($days, $from, $from->modify('last day of this month')))
            + ($months - 1) * count($days)
            + count(self::monthDayDates($days, $last->modify('first day of this month'), $last));
    }

    private static function calendarDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $utc = new \DateTimeZone('UTC');
        return (int) (new DateTimeImmutable($from->format('Y-m-d'), $utc))
            ->diff(new DateTimeImmutable($to->format('Y-m-d'), $utc))->format('%r%a');
    }

    private static function firstSteppedDate(array $settings, int $step, DateTimeImmutable $from): ?DateTimeImmutable
    {
        $anchor = parse_date((string) ($settings['anchor_date'] ?? ''));
        if ($anchor === null) {
            return null;
        }
        $remainder = self::calendarDays($anchor, $from) % $step;
        return $from->modify('+' . (($step - $remainder) % $step) . ' days');
    }

    /**
     * Generate/refresh the user's paychecks from the first of the current
     * month through today + WINDOW_DAYS. Idempotent: new rows get the
     * default income; existing rows only have their wave flag refreshed,
     * never their (possibly user-overridden) amount.
     */
    public static function generateForUser(int $userId): void
    {
        BudgetLock::run($userId, static fn (PDO $pdo) => self::generateForUserLocked($pdo, $userId));
    }

    /** Internal composition only: caller must already hold BudgetLock for this user. */
    public static function generateForUserLocked(PDO $pdo, int $userId): void
    {
        $settings = self::userSettings($userId, $pdo);
        if ($settings === null) {
            return;
        }

        $today = new DateTimeImmutable('today');
        $from = self::generationStart($settings, $today);
        $to = $today->modify('+' . self::windowDays($settings) . ' days');

        $payDates = self::payDates($settings, $from, $to);
        if ($payDates === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO paychecks (user_id, pay_date, amount, is_wave)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_wave = ?'
        );

        $income = (string) ($settings['default_income'] ?? '0.00');
        foreach ($payDates as $payDate) {
            $stmt->execute([$userId, $payDate['date'], $income, (int) $payDate['is_wave'], (int) $payDate['is_wave']]);
        }
    }

    /**
     * The user's settings row, or null if missing.
     *
     * @return array<string, mixed>|null
     */
    public static function userSettings(int $userId, ?PDO $pdo = null): ?array
    {
        $stmt = ($pdo ?? Database::pdo())->prepare('SELECT * FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $settings */
    public static function generationStart(array $settings, DateTimeImmutable $today): DateTimeImmutable
    {
        $monthStart = $today->modify('first day of this month')->setTime(0, 0);
        $effective = parse_date((string) ($settings['schedule_effective_date'] ?? ''));

        return $effective !== null && $effective > $monthStart ? $effective : $monthStart;
    }


    /**
     * Anchor + n*step series (weekly/biweekly), covering [$from, $to].
     * The series extends both directions from the anchor.
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private static function steppedDates(array $settings, int $step, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $date = self::firstSteppedDate($settings, $step, $from);
        if ($date === null) {
            return [];
        }
        $dates = [];
        while ($date <= $to) {
            $dates[] = $date->format('Y-m-d');
            $date = $date->modify("+{$step} days");
        }

        return $dates;
    }

    /**
     * One date per listed day for each month in [$from, $to], with days past
     * the end of a month clamped to its last day (31 -> Feb 28/29).
     *
     * @param list<int> $days
     * @return list<string>
     */
    private static function monthDayDates(array $days, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = self::validMonthDays($days);
        if ($days === []) {
            return [];
        }

        $dates = [];
        $cursor = $from->modify('first day of this month');
        while ($cursor <= $to) {
            $daysInMonth = (int) $cursor->format('t');
            foreach ($days as $day) {
                $date = $cursor->setDate(
                    (int) $cursor->format('Y'),
                    (int) $cursor->format('n'),
                    min($day, $daysInMonth)
                );
                if ($date >= $from && $date <= $to) {
                    $dates[] = $date->format('Y-m-d');
                }
            }
            $cursor = $cursor->modify('first day of next month');
        }

        return $dates;
    }

    private static function validMonthDays(array $days): array
    {
        return array_values(array_unique(array_filter($days, static fn (int $day): bool => $day >= 1 && $day <= 31)));
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<int>
     */
    private static function daysOfMonth(array $settings): array
    {
        $decoded = json_decode((string) ($settings['days_of_month'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }
}
