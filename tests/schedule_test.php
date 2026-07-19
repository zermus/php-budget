<?php

declare(strict_types=1);

/**
 * CLI assertions for the pure pay-schedule engine. No database needed:
 *   php tests/schedule_test.php
 * Not shipped in releases.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Services/ScheduleService.php';
require __DIR__ . '/../src/Services/OccurrenceService.php';

use App\Services\OccurrenceService;
use App\Services\ScheduleService;

$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "  ok: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

/** @param list<array{date:string,is_wave:bool}> $payDates */
function dates(array $payDates): array
{
    return array_column($payDates, 'date');
}

/** @param list<array{date:string,is_wave:bool}> $payDates */
function waves(array $payDates): array
{
    return array_column(
        array_values(array_filter($payDates, static fn (array $p): bool => $p['is_wave'])),
        'date'
    );
}

$d = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);

// --- Biweekly (the seeded schedule) -------------------------------------

echo "Biweekly, anchor 2026-07-22:\n";
$biweekly = ['schedule_type' => 'biweekly', 'anchor_date' => '2026-07-22'];
$seq = ScheduleService::payDates($biweekly, $d('2026-07-01'), $d('2027-01-31'));

check(dates($seq) === [
    '2026-07-08', '2026-07-22', '2026-08-05', '2026-08-19',
    '2026-09-02', '2026-09-16', '2026-09-30', '2026-10-14',
    '2026-10-28', '2026-11-11', '2026-11-25', '2026-12-09',
    '2026-12-23', '2027-01-06', '2027-01-20',
], 'pay-date sequence Jul 2026 – Jan 2027');

check(waves($seq) === ['2026-09-30'], 'September 2026 has three checks; only 9/30 is the wave check');

// Mid-month "today" must not shift in-month ordinals.
$late = ScheduleService::payDates($biweekly, $d('2026-09-20'), $d('2026-10-31'));
check(waves($late) === ['2026-09-30'], 'wave flag survives a mid-month window start');

// The series extends backward from the anchor too.
$early = ScheduleService::payDates($biweekly, $d('2026-01-01'), $d('2026-02-01'));
check(dates($early) === ['2026-01-07', '2026-01-21'], 'series extends backward from the anchor');

// --- Weekly: five checks in a month are never wave-flagged ---------------

echo "Weekly, anchor 2026-07-03:\n";
$weekly = ['schedule_type' => 'weekly', 'anchor_date' => '2026-07-03'];
$julyWeekly = ScheduleService::payDates($weekly, $d('2026-07-01'), $d('2026-07-31'));
check(count(dates($julyWeekly)) === 5, 'July 2026 has five Friday paychecks');
check(waves($julyWeekly) === [], 'weekly is never wave-flagged');

// --- Semimonthly ----------------------------------------------------------

echo "Semimonthly:\n";
$semi = ['schedule_type' => 'semimonthly', 'days_of_month' => '[1,15]'];
$semiDates = ScheduleService::payDates($semi, $d('2026-07-01'), $d('2026-09-30'));
check(dates($semiDates) === [
    '2026-07-01', '2026-07-15', '2026-08-01', '2026-08-15', '2026-09-01', '2026-09-15',
], 'days [1,15]');

$semi31 = ['schedule_type' => 'semimonthly', 'days_of_month' => '[15,31]'];
$semi31Dates = ScheduleService::payDates($semi31, $d('2026-09-01'), $d('2026-09-30'));
check(dates($semi31Dates) === ['2026-09-15', '2026-09-30'], 'day 31 clamps to Sep 30');
check(waves($semi31Dates) === [], 'semimonthly is never wave-flagged');

// --- Monthly with clamping -------------------------------------------------

echo "Monthly, day 31:\n";
$monthly = ['schedule_type' => 'monthly', 'day_of_month' => 31];
$feb27 = ScheduleService::payDates($monthly, $d('2027-02-01'), $d('2027-02-28'));
check(dates($feb27) === ['2027-02-28'], 'clamps to Feb 28 in a common year');

$feb28 = ScheduleService::payDates($monthly, $d('2028-02-01'), $d('2028-02-29'));
check(dates($feb28) === ['2028-02-29'], 'clamps to Feb 29 in a leap year');
check(waves(ScheduleService::payDates($monthly, $d('2026-07-01'), $d('2026-12-31'))) === [], 'monthly is never wave-flagged');

// --- every_n_paychecks phase math (the seeded A/B bills) --------------------

echo "every_n_paychecks phases:\n";
$seqDates = dates($seq);
$phaseA = OccurrenceService::everyNth($seqDates, '2026-07-22', 2);
$phaseB = OccurrenceService::everyNth($seqDates, '2026-08-05', 2);

check($phaseA === [
    '2026-07-22', '2026-08-19', '2026-09-16', '2026-10-14',
    '2026-11-11', '2026-12-09', '2027-01-06',
], 'phase A (anchor 7/22): alternating checks incl. 9/16');

check($phaseB === [
    '2026-08-05', '2026-09-02', '2026-09-30', '2026-10-28',
    '2026-11-25', '2026-12-23', '2027-01-20',
], 'phase B (anchor 8/5): alternating checks incl. the 9/30 wave check');

check(array_intersect($phaseA, $phaseB) === [], 'phases are disjoint');
check(!in_array('2026-07-08', array_merge($phaseA, $phaseB), true), 'no occurrences before a bill\'s anchor');

// Anchor that no longer lands on a pay date snaps to the nearest earlier one.
$snapped = OccurrenceService::everyNth($seqDates, '2026-07-20', 2);
check($snapped[0] === '2026-08-05', 'off-schedule anchor snaps to the 7/8 phase, first date >= anchor is 8/5');

// --- Bill due-date generation ----------------------------------------------

echo "Bill dueDates:\n";
$monthlyBill = [
    'recurrence_type'  => 'monthly_day',
    'recurrence_value' => '{"day":31}',
];
$due = OccurrenceService::dueDates($monthlyBill, $biweekly, $d('2027-01-01'), $d('2027-03-31'));
check($due === ['2027-01-31', '2027-02-28', '2027-03-31'], 'monthly_day 31 clamps per month');

$oneTime = [
    'recurrence_type'  => 'one_time',
    'recurrence_value' => '{"date":"2026-06-01"}',
];
$due = OccurrenceService::dueDates($oneTime, $biweekly, $d('2026-07-01'), $d('2026-10-01'));
check($due === ['2026-06-01'], 'past one_time date still generates (shows as overdue)');

$everyN = [
    'recurrence_type'  => 'every_n_paychecks',
    'recurrence_value' => '{"n":2,"anchor":"2026-08-05"}',
];
$due = OccurrenceService::dueDates($everyN, $biweekly, $d('2026-09-01'), $d('2026-10-31'));
check($due === ['2026-09-02', '2026-09-30', '2026-10-28'], 'every_n dueDates stay in phase across a window start');

// ---------------------------------------------------------------------------

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
