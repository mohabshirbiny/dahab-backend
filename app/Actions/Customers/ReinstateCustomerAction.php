<?php

namespace App\Actions\Customers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
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
 * live row; the audit log keeps them.
 */
final class ReinstateCustomerAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly CustomerFileLoader $file,
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

            $this->audit->execute(
                AuditEvent::CUSTOMER_UNSUSPENDED,
                'success',
                ['status' => $customer->status->value, 'suspended_reason' => null],
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
