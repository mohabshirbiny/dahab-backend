<?php

namespace App\Actions\Customers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerType;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\Staff;
use App\Support\CustomerFileLoader;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Make a customer a market maker, or an ordinary customer again. The type is what lets a
 * dealer code be tied to the account (CreatePromoCodeAction), so it is set by a person, with a
 * note, and audited. Removing it is refused while a dealer code tied to the account is still
 * on: the code is turned off first.
 */
final class SetCustomerTypeAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly CustomerFileLoader $file,
    ) {}

    public function handle(Staff $actor, string $customerId, CustomerType $type, string $note, RequestContext $ctx): Customer
    {
        DB::transaction(function () use ($actor, $customerId, $type, $note, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
            $before = $customer->customer_type;

            if ($before === $type) {
                throw DomainApiException::customerTypeUnchanged();
            }

            if ($type === CustomerType::ORDINARY && PromoCode::query()
                ->where('tied_customer_id', $customer->customer_id)
                ->where('kind', 'market_maker')
                ->where('is_active', true)
                ->exists()) {
                throw DomainApiException::customerHasActiveDealerCode();
            }

            $customer->customer_type = $type;
            $customer->save();

            $this->audit->execute(
                AuditEvent::CUSTOMER_TYPE_CHANGED,
                'success',
                ['customer_type' => $type->value],
                'customer',
                $customer->customer_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['customer_type' => $before->value],
                reason: $note,
            );
        });

        return $this->file->load($customerId);
    }
}
