<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateTimeImmutable;

final class OccurrenceService
{
    /**
     * Generate bill occurrences forward for every active bill, from the first
     * of the current month through today + window. Idempotent: relies on
     * UNIQUE (bill_id, due_date) with INSERT IGNORE, and never updates
     * existing rows, so edited amounts and paid state are preserved.
     */
    public static function generateForUser(int $userId): void
    {
        $settings = ScheduleService::userSettings($userId);
        if ($settings === null) {
            return;
        }

        $today = new DateTimeImmutable('today');
        $from = $today->modify('first day of this month');
        $to = $today->modify('+' . ScheduleService::WINDOW_DAYS . ' days');

        $bills = Database::pdo()->prepare(
            'SELECT * FROM bills WHERE user_id = ? AND active = 1'
        );
        $bills->execute([$userId]);

        $insert = Database::pdo()->prepare(
            'INSERT IGNORE INTO bill_occurrences (user_id, bill_id, due_date, amount)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($bills->fetchAll() as $bill) {
            $dueDates = self::dueDates($bill, $settings, $from, $to);
            foreach ($dueDates as $dueDate) {
                $insert->execute([$userId, (int) $bill['id'], $dueDate, (string) $bill['default_amount']]);
            }
        }
    }

    /**
     * Due dates for one bill within [$from, $to]. Pure given its inputs
     * (the schedule engine is pure too).
     *
     * @param array<string, mixed> $bill
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function dueDates(array $bill, array $settings, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $value = json_decode((string) $bill['recurrence_value'], true);
        if (!is_array($value)) {
            return [];
        }

        switch ((string) $bill['recurrence_type']) {
            case 'monthly_day':
                $day = (int) ($value['day'] ?? 0);
                if ($day < 1 || $day > 31) {
                    return [];
                }
                $dates = [];
                $cursor = $from->modify('first day of this month');
                while ($cursor <= $to) {
                    $date = $cursor->setDate(
                        (int) $cursor->format('Y'),
                        (int) $cursor->format('n'),
                        min($day, (int) $cursor->format('t'))
                    );
                    if ($date >= $from && $date <= $to) {
                        $dates[] = $date->format('Y-m-d');
                    }
                    $cursor = $cursor->modify('first day of next month');
                }

                return $dates;

            case 'one_time':
                $date = parse_date((string) ($value['date'] ?? ''));
                // Past one-time dates still generate (INSERT IGNORE makes it
                // harmless) so a bill entered late still shows up as overdue.
                return ($date !== null && $date <= $to) ? [$date->format('Y-m-d')] : [];

            case 'every_n_paychecks':
                $n = (int) ($value['n'] ?? 0);
                $anchor = (string) ($value['anchor'] ?? '');
                if ($n < 1 || parse_date($anchor) === null) {
                    return [];
                }

                // The anchor marks the first paycheck this bill applies to and
                // fixes its phase in the cycle. Extend the sequence back to the
                // anchor so the phase is computed against the real series.
                $seqFrom = min($from, parse_date($anchor));
                $seq = array_column(ScheduleService::payDates($settings, $seqFrom, $to), 'date');

                return array_values(array_filter(
                    self::everyNth($seq, $anchor, $n),
                    static fn (string $d): bool => $d >= $from->format('Y-m-d')
                ));
        }

        return [];
    }

    /**
     * Pure phase filter: the members of $payDates that are the anchor's
     * paycheck or a multiple of $n paychecks after it. Dates before the
     * anchor are excluded (the anchor is the bill's first paycheck). If the
     * anchor no longer lands exactly on a pay date (schedule changed), it
     * snaps to the nearest earlier pay date to keep the phase stable.
     *
     * @param list<string> $payDates sorted Y-m-d strings
     * @return list<string>
     */
    public static function everyNth(array $payDates, string $anchor, int $n): array
    {
        if ($payDates === [] || $n < 1) {
            return [];
        }

        $anchorIndex = null;
        foreach ($payDates as $i => $date) {
            if ($date === $anchor) {
                $anchorIndex = $i;
                break;
            }
            if ($date < $anchor) {
                $anchorIndex = $i; // best "nearest earlier" candidate so far
            }
        }

        if ($anchorIndex === null) {
            // Anchor precedes the whole sequence; treat the first date as
            // phase-aligned only if the anchor is in the future beyond range.
            $anchorIndex = 0;
        }

        $result = [];
        foreach ($payDates as $i => $date) {
            if ($i >= $anchorIndex && ($i - $anchorIndex) % $n === 0 && $date >= $anchor) {
                $result[] = $date;
            }
        }

        return $result;
    }

    /**
     * Mark an occurrence paid. The single seam for both the UI checkbox
     * (source=manual) and a future bank sync (source=sync).
     */
    public static function markPaid(int $occurrenceId, int $userId, string $source = 'manual'): bool
    {
        if (!in_array($source, ['manual', 'sync'], true)) {
            return false;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE bill_occurrences
             SET paid = 1, paid_at = NOW(), paid_source = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$source, $occurrenceId, $userId]);

        return $stmt->rowCount() > 0 || self::belongsToUser($occurrenceId, $userId);
    }

    public static function markUnpaid(int $occurrenceId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE bill_occurrences
             SET paid = 0, paid_at = NULL, paid_source = NULL
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$occurrenceId, $userId]);

        return $stmt->rowCount() > 0 || self::belongsToUser($occurrenceId, $userId);
    }

    private static function belongsToUser(int $occurrenceId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM bill_occurrences WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$occurrenceId, $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
