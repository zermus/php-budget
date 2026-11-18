<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Services\AllocationService;
use App\Services\OccurrenceService;

final class OccurrenceController
{
    /**
     * AJAX: the paid checkbox. Calls the mark_paid seam with source=manual;
     * a future bank sync calls the same service method with source=sync.
     */
    public function setPaid(): void
    {
        Auth::requirePayJson();
        Csrf::requireJson();

        $occurrenceId = input_int('id');
        $paid = !empty($_POST['paid']);
        $userId = Auth::dataUserId();

        $ok = $paid
            ? OccurrenceService::markPaid($occurrenceId, $userId, 'manual')
            : OccurrenceService::markUnpaid($occurrenceId, $userId);

        if (!$ok) {
            json_response(['success' => false, 'error' => 'Bill occurrence not found.'], 404);
        }

        json_response([
            'success' => true,
            'paid'    => $paid,
            'totals'  => array_values(
                AllocationService::paycheckTotals($userId, self::paycheckIdsFor($occurrenceId, $userId))
            ),
        ]);
    }

    /**
     * AJAX: inline edit of one occurrence's amount. A single (unsplit)
     * allocation follows the new amount; splits are left for the user to
     * rebalance on the allocation page.
     */
    public function updateAmount(): void
    {
        Auth::requireBudgeterJson();
        Csrf::requireJson();

        $occurrenceId = input_int('id');
        $amount = input_decimal('amount');
        $userId = Auth::dataUserId();

        // Zero is refused like everywhere else a bill amount is entered: a $0
        // occurrence could never be re-split (splits must be positive), so it
        // would be stuck. Skip is the way to drop a bill for one period.
        if ($occurrenceId < 1 || $amount === null || (float) $amount <= 0) {
            json_response([
                'success' => false,
                'error'   => 'Enter a dollar amount above zero. To drop this bill for one period, use Skip.',
            ], 422);
        }

        if (!OccurrenceService::updateAmount($occurrenceId, $userId, $amount)) {
            json_response(['success' => false, 'error' => 'Bill occurrence not found.'], 404);
        }

        json_response([
            'success' => true,
            'amount'  => $amount,
            'totals'  => array_values(
                AllocationService::paycheckTotals($userId, self::paycheckIdsFor($occurrenceId, $userId))
            ),
        ]);
    }

    /**
     * Every paycheck an occurrence is allocated against (more than one when
     * it is split), so the dashboard can refresh each affected card.
     *
     * @return list<int>
     */
    private static function paycheckIdsFor(int $occurrenceId, int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT paycheck_id FROM allocations WHERE occurrence_id = ? AND user_id = ?'
        );
        $stmt->execute([$occurrenceId, $userId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Skip a single upcoming occurrence (unpaid only). A soft flag rather
     * than a delete — a deleted row would just be regenerated on the next
     * pass. The bill itself and its other occurrences are untouched.
     */
    public function skip(): void
    {
        Auth::requireBudgeter();
        Csrf::require();

        $occurrenceId = input_int('id');
        $userId = Auth::dataUserId();

        if (OccurrenceService::skip($occurrenceId, $userId)) {
            flash('Bill occurrence skipped.');
        } else {
            flash('Only unpaid occurrences can be skipped.', 'error');
        }

        redirect('/dashboard');
    }
}
