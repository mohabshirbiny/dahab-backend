<?php

namespace App\Actions\FirstSale;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\FirstSaleEnablement;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Staff enable the first-sale advance for one seller, or take the enablement back before it is
 * used (spec 023 research R15; permission `first_sale.manage`). The seller's next eligible gold
 * sale is advanced with this staff member as the actor. One open enablement per seller; refused
 * for a seller who already had an advance. Every change is audited with the reason.
 */
final class ManageFirstSaleEnablementAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function enable(Staff $actor, string $customerId, string $reason, ?RequestContext $ctx = null): FirstSaleEnablement
    {
        return DB::transaction(function () use ($actor, $customerId, $reason, $ctx) {
            $seller = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

            if (DB::table('first_sale_advance')->where('seller_id', $seller->customer_id)->exists()) {
                throw DomainApiException::firstSaleNotEligible();
            }

            try {
                $row = FirstSaleEnablement::query()->create([
                    'seller_id' => $seller->customer_id,
                    'enabled_by' => $actor->staff_id,
                    'reason' => $reason,
                ])->refresh();
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    throw DomainApiException::firstSaleEnablementOpen();
                }
                throw $e;
            }

            $this->audit->execute(
                AuditEvent::FIRST_SALE_ENABLED, 'success',
                ['enablement_id' => $row->enablement_id, 'seller_ref' => $seller->display_ref],
                'customer', $seller->customer_id, $ctx, actorStaffId: $actor->staff_id, reason: $reason,
            );

            return $row;
        });
    }

    public function revoke(Staff $actor, string $customerId, ?string $reason, ?RequestContext $ctx = null): FirstSaleEnablement
    {
        return DB::transaction(function () use ($actor, $customerId, $reason, $ctx) {
            $seller = Customer::query()->whereKey($customerId)->firstOrFail();
            $row = FirstSaleEnablement::query()->where('seller_id', $seller->customer_id)
                ->whereNull('revoked_at')->whereNull('used_advance_id')->lockForUpdate()->first()
                ?? throw DomainApiException::firstSaleEnablementNotFound();

            $row->forceFill(['revoked_by' => $actor->staff_id, 'revoked_at' => now()])->save();

            $this->audit->execute(
                AuditEvent::FIRST_SALE_REVOKED, 'success',
                ['enablement_id' => $row->enablement_id, 'seller_ref' => $seller->display_ref],
                'customer', $seller->customer_id, $ctx, actorStaffId: $actor->staff_id, reason: $reason,
            );

            return $row->refresh();
        });
    }
}
