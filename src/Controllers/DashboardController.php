<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Services\AllocationService;
use App\Services\OccurrenceService;
use App\Services\ScheduleService;
use App\View;
use DateTimeImmutable;

final class DashboardController
{
    /** Paycheck cards per page (3 columns x 3 rows). */
    private const PER_PAGE = 9;

    public function index(): void
    {
        $user = Auth::requireLogin();
        $userId = (int) $user['id'];

        // Lazy generation: everything is idempotent and cheap at this scale,
        // so the window is always fresh exactly when it is viewed.
        ScheduleService::generateForUser($userId);
        OccurrenceService::generateForUser($userId);
        AllocationService::autoAllocate($userId);

        $pdo = Database::pdo();
        $settings = ScheduleService::userSettings($userId) ?? [];

        // A sort choice on the query string is persisted for next time.
        $sort = (string) ($settings['dashboard_sort'] ?? 'amount_desc');
        $sortParam = input_string('sort', $_GET);
        if ($sortParam !== '' && in_array($sortParam, ScheduleService::SORT_OPTIONS, true) && $sortParam !== $sort) {
            $pdo->prepare('UPDATE user_settings SET dashboard_sort = ? WHERE user_id = ?')
                ->execute([$sortParam, $userId]);
            $sort = $sortParam;
        }

        $today = new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $endStr = $today->modify('+' . ScheduleService::windowDays($settings) . ' days')->format('Y-m-d');

        // Window starts at the paycheck covering today (latest pay_date <=
        // today), so the current period's bills stay visible.
        $stmt = $pdo->prepare(
            'SELECT MAX(pay_date) FROM paychecks WHERE user_id = ? AND pay_date <= ?'
        );
        $stmt->execute([$userId, $todayStr]);
        $startStr = (string) ($stmt->fetchColumn() ?: $todayStr);

        $stmt = $pdo->prepare(
            'SELECT * FROM paychecks
             WHERE user_id = ? AND pay_date BETWEEN ? AND ?
             ORDER BY pay_date'
        );
        $stmt->execute([$userId, $startStr, $endStr]);
        $paychecks = $stmt->fetchAll();

        // Paginate: PER_PAGE cards per page, soonest first.
        $totalPages = max(1, (int) ceil(count($paychecks) / self::PER_PAGE));
        $page = max(1, min($totalPages, input_int('page', $_GET) ?: 1));
        $paychecks = array_slice($paychecks, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $rowsByPaycheck = [];
        if ($paychecks !== []) {
            $ids = array_map(static fn (array $p): int => (int) $p['id'], $paychecks);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $pdo->prepare(
                "SELECT a.paycheck_id, a.amount AS alloc_amount,
                        o.id AS occurrence_id, o.due_date, o.amount AS occ_amount,
                        o.paid, o.paid_source, b.name AS bill_name,
                        (SELECT COUNT(*) FROM allocations a2 WHERE a2.occurrence_id = o.id) AS alloc_count
                 FROM allocations a
                 INNER JOIN bill_occurrences o ON o.id = a.occurrence_id
                 INNER JOIN bills b ON b.id = o.bill_id
                 WHERE a.user_id = ? AND a.paycheck_id IN ($placeholders)
                 ORDER BY o.due_date, b.name"
            );
            $stmt->execute([$userId, ...$ids]);

            foreach ($stmt->fetchAll() as $row) {
                $rowsByPaycheck[(int) $row['paycheck_id']][] = $row;
            }

            foreach ($rowsByPaycheck as &$rows) {
                usort($rows, match ($sort) {
                    'amount_asc' => static fn (array $a, array $b): int => (float) $a['alloc_amount'] <=> (float) $b['alloc_amount'],
                    'due_date'   => static fn (array $a, array $b): int => [$a['due_date'], $a['bill_name']] <=> [$b['due_date'], $b['bill_name']],
                    default      => static fn (array $a, array $b): int => (float) $b['alloc_amount'] <=> (float) $a['alloc_amount'],
                });
            }
            unset($rows);
        }

        // The card covering today: the last one with pay_date <= today.
        $currentId = null;
        foreach ($paychecks as $paycheck) {
            if ($paycheck['pay_date'] <= $todayStr) {
                $currentId = (int) $paycheck['id'];
            }
        }

        echo View::render('dashboard/index', [
            'title'          => 'Budget Dashboard',
            'paychecks'      => $paychecks,
            'rowsByPaycheck' => $rowsByPaycheck,
            'currentId'      => $currentId,
            'today'          => $todayStr,
            'sort'           => $sort,
            'page'           => $page,
            'totalPages'     => $totalPages,
            'scripts'        => [asset('js/dashboard.js')],
        ]);
    }

    /**
     * AJAX: override one paycheck's income amount.
     */
    public function updateAmount(): void
    {
        $user = Auth::requireLoginJson();
        Csrf::requireJson();

        $paycheckId = input_int('id');
        $amount = input_decimal('amount');
        if ($paycheckId < 1 || $amount === null) {
            json_response(['success' => false, 'error' => 'Enter a valid dollar amount.'], 422);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE paychecks SET amount = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$amount, $paycheckId, (int) $user['id']]);

        $totals = AllocationService::paycheckTotals((int) $user['id'], [$paycheckId]);

        json_response(['success' => true, 'totals' => array_values($totals)]);
    }
}
