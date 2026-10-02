<?php

namespace App\Actions\Orders;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\HoldListingsOfCustomerAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\SuspendedReason;
use App\Models\Customer;
use App\Models\Staff;

/**
 * A suspension the orders module triggers (spec 012 FR-007, FR-012, research
 * R8, R9): the system actor when a seller's cancellations reach the threshold
 * (the sweep's suspension pass), or the inspector when a piece is not what it
 * claimed (karat mismatch / counterfeit). Always inside a staff-scope
 * transaction that is already running — never from a customer request
 * (analysis C2). The same effects as a founder's suspension (spec 007): the
 * status and reason, the live and reserved listings held (spec 010/011), one
 * audit row naming the actor. A seller already suspended is left as they are.
 *
 * @return bool whether this call suspended them
 */
final class SuspendSellerAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly HoldListingsOfCustomerAction $listings,
    ) {}

    public function handle(string $sellerId, SuspendedReason $reason, string $note, Staff $actor): bool
    {
        $customer = Customer::query()->whereKey($sellerId)->lockForUpdate()->firstOrFail();

        if ($customer->status === CustomerStatus::SUSPENDED) {
            return false;
        }

        $before = $customer->status;
        $customer->suspend($reason, $note, $actor);
        $customer->save();

        $held = $this->listings->hold($actor, $customer->customer_id);

        $this->audit->execute(
            AuditEvent::CUSTOMER_SUSPENDED,
            'success',
            ['status' => CustomerStatus::SUSPENDED->value, 'suspended_reason' => $reason->value, 'listings_held' => $held],
            'customer',
            $customer->customer_id,
            null,
            actorStaffId: $actor->staff_id,
            before: ['status' => $before->value, 'suspended_reason' => null],
            reason: $note,
        );

        return true;
    }
}
