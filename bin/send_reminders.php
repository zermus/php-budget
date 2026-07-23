<?php

declare(strict_types=1);

/**
 * Nightly bill-reminder cron. For each user: refresh the paycheck/occurrence
 * window, then email one summary of unpaid bills that are due within their
 * reminder lead time or overdue. Idempotent per day by design — no sent
 * flags; a bill still unpaid tomorrow simply appears again.
 *
 * Crontab: 0 6 * * * php /path/to/php-budget/bin/send_reminders.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;
use App\Mailer;
use App\Services\AllocationService;
use App\Services\OccurrenceService;
use App\Services\ScheduleService;

$pdo = Database::pdo();
$today = new DateTimeImmutable('today');
$todayStr = $today->format('Y-m-d');

// One pass per budget owner; sub-users share the owner's schedule and bills.
$owners = $pdo->query(
    'SELECT u.id, u.email, u.receive_reminders, s.reminder_lead_days, s.smtp_host
     FROM users u
     INNER JOIN user_settings s ON s.user_id = u.id
     WHERE u.owner_id IS NULL'
)->fetchAll();

$recipientStmt = $pdo->prepare(
    'SELECT email FROM users
     WHERE owner_id = ? AND receive_reminders = 1
     ORDER BY email'
);

$sent = 0;

foreach ($owners as $user) {
    $userId = (int) $user['id'];

    // Keep the window fresh even if the user hasn't opened the dashboard.
    ScheduleService::generateForUser($userId);
    OccurrenceService::generateForUser($userId);
    AllocationService::autoAllocate($userId);

    $leadDays = (int) $user['reminder_lead_days'];
    $dueLimit = $today->modify("+{$leadDays} days")->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT o.due_date, o.amount, b.name
         FROM bill_occurrences o
         INNER JOIN bills b ON b.id = o.bill_id
         WHERE o.user_id = ? AND o.paid = 0 AND o.skipped = 0 AND o.due_date <= ?
         ORDER BY o.due_date, b.name'
    );
    $stmt->execute([$userId, $dueLimit]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        continue;
    }

    $overdue = array_values(array_filter($rows, static fn (array $r): bool => $r['due_date'] < $todayStr));
    $dueSoon = array_values(array_filter($rows, static fn (array $r): bool => $r['due_date'] >= $todayStr));

    $lines = [];
    $total = 0.0;

    if ($dueSoon !== []) {
        $lines[] = 'Due soon:';
        foreach ($dueSoon as $row) {
            $lines[] = sprintf('  %s — $%s due %s', $row['name'], money((string) $row['amount']), $row['due_date']);
            $total += (float) $row['amount'];
        }
        $lines[] = '';
    }

    if ($overdue !== []) {
        $lines[] = 'OVERDUE:';
        foreach ($overdue as $row) {
            $lines[] = sprintf('  %s — $%s was due %s', $row['name'], money((string) $row['amount']), $row['due_date']);
            $total += (float) $row['amount'];
        }
        $lines[] = '';
    }

    $lines[] = sprintf('Total unpaid: $%s', money((string) $total));
    $lines[] = '';
    $lines[] = 'Mark bills paid on your dashboard: ' . url('/dashboard');

    $count = count($rows);
    $subject = sprintf(
        'Bill reminder: %d bill%s need%s attention',
        $count,
        $count === 1 ? '' : 's',
        $count === 1 ? 's' : ''
    );

    $smtpHost = isset($user['smtp_host']) && $user['smtp_host'] !== null && $user['smtp_host'] !== ''
        ? (string) $user['smtp_host']
        : null;

    // The owner plus any sub-user who has reminders switched on.
    $recipients = [];
    if (!empty($user['receive_reminders'])) {
        $recipients[] = (string) $user['email'];
    }
    $recipientStmt->execute([$userId]);
    foreach ($recipientStmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $recipients[] = (string) $email;
    }

    foreach ($recipients as $email) {
        if (Mailer::send($email, $email, $subject, implode("\n", $lines), $smtpHost)) {
            $sent++;
        } else {
            fwrite(STDERR, "Failed to send reminder to {$email}: " . (Mailer::lastError() ?? 'unknown error') . "\n");
        }
    }
}

echo 'Reminders sent: ' . $sent . ' message(s) across ' . count($owners) . " budget(s) checked.\n";
