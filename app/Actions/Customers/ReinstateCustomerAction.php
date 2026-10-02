<?php

namespace App\Actions\Customers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\HoldListingsOfCustomerAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\CustomerFileLoader;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Reinstate a suspended customer to exactly the state the suspension
 * interrupted (spec 007 US2, FR-006/FR-007). The suspension details leave the
 * live row; the audit log keeps them. Listings held by the suspension return
 * to the market (spec 010).
 */
final class ReinstateCustomerAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly CustomerFileLoader $file,
        private readonly HoldListingsOfCustomerAction $listings,
    ) {}

    public function handle(Staff $actor, string $customerId, string $note, RequestContext $ctx): Customer
    {
        DB::transaction(function () use ($actor, $customerId, $note, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

            if ($customer->status !== CustomerStatus::SUSPENDED) {
                throw DomainApiException::customerNotSuspended();
            }

            $reason = $customer->suspended_reason?->value;
            $customer->reinstate();
            $customer->save();

            // Spec 012 FR-007: seller cancellations count again from this moment (the
            // database clock, to the microsecond, like seller_cancellation.cancelled_at).
            DB::table('customer')->where('customer_id', $customer->customer_id)
                ->update(['cancellations_reset_at' => DB::raw('clock_timestamp()')]);

            // Spec 010 FR-037: the listings held by the suspension go back on the market.
            $restored = $this->listings->restore($actor, $customer->customer_id);

            $this->audit->execute(
                AuditEvent::CUSTOMER_UNSUSPENDED,
                'success',
                ['status' => $customer->status->value, 'suspended_reason' => null, 'listings_restored' => $restored],
                'customer',
                $customer->customer_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['status' => CustomerStatus::SUSPENDED->value, 'suspended_reason' => $reason],
                reason: $note,
            );
        });

        return $this->file->load($customerId);
    }
}
