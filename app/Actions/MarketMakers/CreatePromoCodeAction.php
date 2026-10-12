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
use Illuminate\Validation\ValidationException;

/**
 * Create a promo code (spec 019 FR-060, spec 023 Story 5, contracts/staff-promo-api.md §2).
 *
 * One body for the three kinds, the way the Dashboard's New-code form is laid out. The kind is
 * inferred when not sent: a tie to a market maker is a dealer code; a code that pays the advance
 * is a first-sale code; any other is a seller-side commission discount. A kind that contradicts
 * the terms is a validation error. The terms are immutable afterwards (guard DH020). The old
 * three-field body (`code`, `tied_customer_id`, `monthly_cap_egp`) still creates a dealer code
 * exactly as before.
 */
final class CreatePromoCodeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  array<string, mixed>  $in  the validated request (see CreatePromoCodeRequest)
     */
    public function handle(Staff $actor, array $in, ?RequestContext $ctx = null): PromoCode
    {
        // The spec 019 body (code, tied_customer_id, monthly_cap_egp) is a dealer code, as it always was.
        $newTerms = ['kind', 'audience', 'tied_to', 'use_limit', 'commission_mode', 'commission_percent', 'gives_spread',
            'min_listed_days', 'requires_approval', 'starts_on', 'ends_on', 'first_sale_only', 'pays_advance'];
        if (! empty($in['tied_customer_id']) && array_intersect($newTerms, array_keys($in)) === []) {
            $in['kind'] = 'market_maker';
        }

        return DB::transaction(function () use ($actor, $in, $ctx) {
            $tied = $this->resolveTie($in);
            $audience = $in['audience'] ?? ($tied !== null ? 'account' : 'anyone');

            if ($audience === 'account' && $tied === null) {
                throw $this->invalid('tied_to', 'Tell us which account this code is for.');
            }
            if ($audience === 'anyone' && $tied !== null) {
                throw $this->invalid('tied_to', 'A code for anyone is not tied to an account.');
            }

            $isDealer = $tied !== null && $tied->customer_type === CustomerType::MARKET_MAKER;
            $kind = $this->kind($in, $tied, $isDealer);

            $paysAdvance = (bool) ($in['pays_advance'] ?? ($kind === 'first_sale'));
            $firstSaleOnly = $kind === 'first_sale' ? true : (bool) ($in['first_sale_only'] ?? false);
            if ($paysAdvance && $kind !== 'first_sale') {
                throw $this->invalid('pays_advance', 'A code that pays the advance cannot also be a dealer code.');
            }
            if ($kind === 'first_sale' && ! $paysAdvance) {
                throw $this->invalid('pays_advance', 'A first-sale code pays the first gold sale in advance.');
            }

            $mode = $in['commission_mode'] ?? ($kind === 'market_maker' ? 'waive' : 'none');
            if ($kind === 'market_maker' && $mode !== 'waive') {
                throw $this->invalid('commission_mode', 'A dealer code waives the commission.');
            }
            if ($kind === 'discount' && $mode === 'none') {
                throw $this->invalid('commission_mode', 'A discount code must waive or reduce the commission.');
            }
            $percent = $mode === 'percent' ? ($in['commission_percent'] ?? null) : null;
            if ($mode === 'percent' && $percent === null) {
                throw $this->invalid('commission_percent', 'Say how many percent to reduce it by.');
            }
            if ($mode !== 'percent' && ($in['commission_percent'] ?? null) !== null) {
                throw $this->invalid('commission_percent', 'A percentage only goes with "Reduce by a percentage".');
            }

            $givesSpread = (bool) ($in['gives_spread'] ?? ($kind === 'market_maker'));
            if ($givesSpread && $kind !== 'market_maker') {
                throw $this->invalid('gives_spread', 'The buy and sell difference can only go to a market maker.');
            }
            if ($kind === 'market_maker' && ! $givesSpread) {
                throw $this->invalid('gives_spread', 'A dealer code gives the buy and sell difference to the dealer.');
            }

            $code = strtoupper(trim((string) $in['code']));
            if (DB::table('referral_code')->where('code', $code)->exists()) {
                throw DomainApiException::promoCodeExists();
            }

            try {
                $promo = PromoCode::query()->create([
                    'code' => $code,
                    'kind' => $kind,
                    'audience' => $audience,
                    'tied_customer_id' => $tied?->customer_id,
                    'commission_mode' => $mode,
                    'commission_percent' => $percent === null ? null : (string) $percent,
                    'commission_waived' => $mode === 'waive',
                    'gives_spread' => $givesSpread,
                    'use_limit' => $in['use_limit'] ?? 'unlimited',
                    'first_sale_only' => $firstSaleOnly,
                    'pays_advance' => $paysAdvance,
                    'min_listed_days' => $in['min_listed_days'] ?? null,
                    'requires_approval' => (bool) ($in['requires_approval'] ?? true),
                    'monthly_cap_egp' => isset($in['monthly_cap_egp']) ? (string) $in['monthly_cap_egp'] : null,
                    'starts_on' => $in['starts_on'] ?? null,
                    'ends_on' => $in['ends_on'] ?? null,
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
                [
                    'code' => $promo->code, 'kind' => $kind, 'audience' => $audience,
                    'customer_ref' => $tied?->display_ref,
                    'commission_mode' => $mode, 'commission_percent' => $percent === null ? null : (string) $percent,
                    'gives_spread' => $givesSpread, 'use_limit' => $promo->use_limit,
                    'first_sale_only' => $firstSaleOnly, 'pays_advance' => $paysAdvance,
                    'min_listed_days' => $promo->min_listed_days, 'requires_approval' => $promo->requires_approval,
                    'monthly_cap_egp' => $promo->monthly_cap_egp,
                    'starts_on' => $in['starts_on'] ?? null, 'ends_on' => $in['ends_on'] ?? null,
                ],
                entityType: 'promo_code',
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return $promo->refresh()->load('customer');
        });
    }

    /** The account a code is tied to: by id, by account number, or by phone; null when none was sent. */
    private function resolveTie(array $in): ?Customer
    {
        if (! empty($in['tied_customer_id'])) {
            return Customer::query()->whereKey($in['tied_customer_id'])->first()
                ?? throw DomainApiException::promoAccountNotFound();
        }
        if (empty($in['tied_to'])) {
            return null;
        }

        $ref = trim((string) $in['tied_to']);
        $phone = preg_replace('/[\s\-()]/', '', $ref);
        if (str_starts_with((string) $phone, '00')) {
            $phone = '+'.substr((string) $phone, 2);
        }

        return Customer::query()
            ->where(fn ($q) => $q->where('display_ref', $ref)->orWhere('phone', $phone))
            ->first() ?? throw DomainApiException::promoAccountNotFound();
    }

    private function kind(array $in, ?Customer $tied, bool $isDealer): string
    {
        $sent = $in['kind'] ?? null;

        if ($sent === 'market_maker') {
            if ($tied === null) {
                throw $this->invalid('tied_to', 'A dealer code is tied to the dealer\'s account.');
            }
            if (! $isDealer) {
                throw DomainApiException::promoCustomerNotMarketMaker();
            }

            return 'market_maker';
        }

        $inferred = $isDealer ? 'market_maker' : (($in['pays_advance'] ?? false) ? 'first_sale' : 'discount');
        if ($sent !== null && $sent !== $inferred) {
            // A first-sale code is the only kind a sender can pick over the inference.
            if ($sent === 'first_sale' && ! $isDealer) {
                return 'first_sale';
            }
            throw $this->invalid('kind', 'The kind does not match the terms of the code.');
        }

        return $inferred;
    }

    private function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
