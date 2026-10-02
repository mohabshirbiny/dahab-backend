<?php

namespace App\Support\Orders;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Order;
use App\Models\Staff;
use App\Support\RequestContext;

/**
 * Where a staff member may act on a piece (spec 012 FR-004, research R5;
 * Part 1 §3.4). Read from the staff member's assigned branch (spec 004),
 * never from a role name: assigned → that branch only; unassigned → any.
 *
 * Not spec 002's BranchScope: that one is for permissions flagged
 * `isBranchScoped()`, which grant nothing without a branch. The order codes
 * are not flagged — head-office staff with no branch act at every branch
 * (Clarification 2026-10-01).
 */
final class StaffBranchScope
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * Before the Action's transaction: refuse, and record the refusal, when the
     * order is at another branch (an audit row written inside the transaction
     * would roll back with the refusal). 404 for an unknown order.
     */
    public function guard(Staff $staff, string $orderId, ?RequestContext $ctx = null): void
    {
        $order = Order::query()->findOrFail($orderId, ['order_id', 'order_ref', 'branch_id']);

        if (! $this->canActAt($staff, $order->branch_id)) {
            $this->audit->execute(
                AuditEvent::STAFF_PERMISSION_DENIED,
                'denied',
                ['reason' => 'wrong_branch', 'order_ref' => $order->order_ref, 'order_branch_id' => $order->branch_id, 'staff_branch_id' => $staff->branch_id],
                'order',
                $order->order_id,
                $ctx,
                actorStaffId: $staff->staff_id,
            );

            throw DomainApiException::wrongBranch();
        }
    }

    /** Inside the transaction, on the locked order (the branch may have changed since guard()). */
    public function assertCanActAt(Staff $staff, int $branchId): void
    {
        if (! $this->canActAt($staff, $branchId)) {
            throw DomainApiException::wrongBranch();
        }
    }

    public function canActAt(Staff $staff, int $branchId): bool
    {
        return $staff->branch_id === null || (int) $staff->branch_id === $branchId;
    }

    /** The branch a list is limited to: the assigned one, or the requested one for unassigned staff. */
    public function branchFilterFor(Staff $staff, ?int $requested = null): ?int
    {
        return $staff->branch_id !== null ? (int) $staff->branch_id : $requested;
    }
}
