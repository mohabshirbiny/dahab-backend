<?php

namespace App\Actions\Disputes\Staff;

use App\Actions\Orders\Staff\ShowOrderAction;
use App\Models\Dispute;

/**
 * One dispute for staff (spec 014 FR-008): the customer's words, the photos
 * (ids only), the history with pass-on notes, compensation paid, and the order
 * with its ledger and timeline (the spec 012 staff detail). Staff scope.
 */
final class ShowDisputeAction
{
    public function __construct(private readonly ShowOrderAction $orders) {}

    public function handle(string $disputeId): Dispute
    {
        $dispute = Dispute::query()->with([
            'raiser:customer_id,display_ref', 'assignee:staff_id,full_name', 'resolver:staff_id,full_name',
            'photos', 'changes.actorStaff:staff_id,full_name', 'changes.assignee:staff_id,full_name',
            'compensations.payer:staff_id,full_name',
        ])->findOrFail($disputeId);

        $dispute->setRelation('order', $this->orders->handle($dispute->order_id));

        return $dispute;
    }
}
