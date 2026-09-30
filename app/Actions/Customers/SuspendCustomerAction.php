<?php

namespace App\Actions\Customers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\HoldListingsOfCustomerAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\SuspendedReason;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\CustomerFileLoader;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Suspend a customer from any state (spec 007 US2, Part 1 §4.3, Part 2 §543).
 * The customer keeps signing in and reading; every trade-gated action is
 * refused with account_suspended (EnsureCustomerStanding re-reads the row).
 * Sessions are not ended. Their live listings are taken off the market
 * (`suspended_hold`, spec 010). One audit entry, in the same transaction.
 */
final class SuspendCustomerAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly CustomerFileLoader $file,
        private readonly HoldListingsOfCustomerAction $listings,
    ) {}

    public function handle(Staff $actor, string $customerId, SuspendedReason $reason, string $note, RequestContext $ctx): Customer
    {
        DB::transaction(function () use ($actor, $customerId, $reason, $note, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

            if ($customer->status === CustomerStatus::SUSPENDED) {
                throw DomainApiException::customerAlreadySuspended();
            }

            $before = $customer->status;
            $customer->suspend($reason, $note, $actor);
            $customer->save();

            // Spec 010 FR-037: their live listings leave the market with them, in this transaction.
            $held = $this->listings->hold($actor, $customer->customer_id);

            $this->audit->execute(
                AuditEvent::CUSTOMER_SUSPENDED,
                'success',
                ['status' => CustomerStatus::SUSPENDED->value, 'suspended_reason' => $reason->value, 'listings_held' => $held],
                'customer',
                $customer->customer_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['status' => $before->value, 'suspended_reason' => null],
                reason: $note,
            );
        });

        return $this->file->load($customerId);
    }
}
