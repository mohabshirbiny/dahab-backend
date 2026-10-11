<?php

namespace App\Actions\MarketMakers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CustomerType;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create a market-maker code (spec 019 FR-060). Only that kind exists here (`first_sale` is a
 * later money spec, FR-065), tied to exactly one customer of type `market_maker`; the code
 * waives commission and gives the spread to its dealer. The code is bound to the account, so
 * it works on any of its devices.
 */
final class CreatePromoCodeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $kind, string $code, string $customerId, ?string $monthlyCap, ?RequestContext $ctx = null): PromoCode
    {
        if ($kind !== 'market_maker') {
            throw DomainApiException::promoKindNotAvailable();
        }

        return DB::transaction(function () use ($actor, $code, $customerId, $monthlyCap, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->firstOrFail();
            if ($customer->customer_type !== CustomerType::MARKET_MAKER) {
                throw DomainApiException::promoCustomerNotMarketMaker();
            }

            try {
                $promo = PromoCode::query()->create([
                    'code' => strtoupper(trim($code)),
                    'kind' => 'market_maker',
                    'tied_customer_id' => $customer->customer_id,
                    'commission_waived' => true,
                    'gives_spread' => true,
                    'monthly_cap_egp' => $monthlyCap,
                    'is_active' => true,
                    'created_by' => $actor->staff_id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    throw DomainApiException::promoCodeExists();
                }
                throw $e;
            }

            $this->audit->execute(
                AuditEvent::PROMO_CREATED,
                'success',
                ['code' => $promo->code, 'customer_ref' => $customer->display_ref, 'monthly_cap_egp' => $monthlyCap],
                entityType: 'promo_code',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return $promo->refresh();
        });
    }
}
