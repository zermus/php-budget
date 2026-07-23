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
        Auth::requireAdminJson();
        Csrf::requireJson();

        $occurrenceId = input_int('id');
        $amount = input_decimal('amount');
        $userId = Auth::dataUserId();

        if ($occurrenceId < 1 || $amount === null) {
            json_response(['success' => false, 'error' => 'Enter a valid dollar amount.'], 422);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE bill_occurrences SET amount = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$amount, $occurrenceId, $userId]);

        AllocationService::syncSingleAllocation($occurrenceId, $userId, $amount);

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
        Auth::requireAdmin();
        Csrf::require();

        $occurrenceId = input_int('id');
        $userId = Auth::dataUserId();

        $stmt = Database::pdo()->prepare(
            'UPDATE bill_occurrences SET skipped = 1 WHERE id = ? AND user_id = ? AND paid = 0'
        );
        $stmt->execute([$occurrenceId, $userId]);

        if ($stmt->rowCount() > 0) {
            Database::pdo()->prepare('DELETE FROM allocations WHERE occurrence_id = ? AND user_id = ?')
                ->execute([$occurrenceId, $userId]);
            flash('Bill occurrence skipped.');
        } else {
            flash('Only unpaid occurrences can be skipped.', 'error');
        }

        redirect('/dashboard');
    }
}
