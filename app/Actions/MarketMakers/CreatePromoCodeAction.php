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
            'min_listed_days', 'requires_approval', 'starts_on', 'ends_on', 'first_sale_only', 'pays_advance',
            'side', 'product_type', 'commission_amount_egp', 'deposit_percent', 'deposit_amount_egp'];
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

            // A side picked on the form decides the kind; a dealer code is only inferred when no side was sent.
            $side = $in['side'] ?? null;
            $isDealer = $side === null && $tied !== null && $tied->customer_type === CustomerType::MARKET_MAKER;
            $kind = $this->kind($in, $tied, $isDealer);
            $side = in_array($kind, ['market_maker', 'buyer'], true) ? 'buyer' : 'seller';
            if (($in['side'] ?? $side) !== $side) {
                throw $this->invalid('side', 'The side does not match the kind of the code.');
            }

            $product = $in['product_type'] ?? null;
            // A buyer code is always for one product. A seller code is too when the form sends a side; the
            // older body (no side) keeps working and then covers every product.
            if (($kind === 'buyer' || ($kind === 'discount' && array_key_exists('side', $in))) && $product === null) {
                throw $this->invalid('product_type', 'Say which product this code is for: gold, diamond or gold with diamond.');
            }
            if (! in_array($kind, ['discount', 'buyer'], true) && $product !== null) {  // dealer and first-sale codes
                throw $this->invalid('product_type', 'This kind of code is not tied to a product.');
            }

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
            if ($kind === 'buyer' && $mode !== 'none') {
                throw $this->invalid('commission_mode', 'A buyer code does not change the commission.');
            }
            $percent = $mode === 'percent' ? ($in['commission_percent'] ?? null) : null;
            if ($mode === 'percent' && $percent === null) {
                throw $this->invalid('commission_percent', 'Say how many percent to reduce it by.');
            }
            if ($mode !== 'percent' && ($in['commission_percent'] ?? null) !== null) {
                throw $this->invalid('commission_percent', 'A percentage only goes with "Reduce by a percentage".');
            }
            $amount = $mode === 'amount' ? ($in['commission_amount_egp'] ?? null) : null;
            if ($mode === 'amount' && $amount === null) {
                throw $this->invalid('commission_amount_egp', 'Say how many pounds to take off the commission.');
            }
            if ($mode !== 'amount' && ($in['commission_amount_egp'] ?? null) !== null) {
                throw $this->invalid('commission_amount_egp', 'An amount only goes with "Reduce by an amount".');
            }

            // Gold: the buyer pays the seller's price (the positive spread is handed back). Stones have no
            // spread; there a buyer code lowers the deposit by a percentage or an amount instead.
            $givesSpread = (bool) ($in['gives_spread'] ?? ($kind === 'market_maker' || ($kind === 'buyer' && $product === 'gold')));
            if ($givesSpread && ! in_array($kind, ['market_maker', 'buyer'], true)) {
                throw $this->invalid('gives_spread', 'The buy and sell difference can only go to a buyer.');
            }
            if ($kind === 'market_maker' && ! $givesSpread) {
                throw $this->invalid('gives_spread', 'A dealer code gives the buy and sell difference to the dealer.');
            }
            if ($kind === 'buyer' && $givesSpread !== ($product === 'gold')) {
                throw $this->invalid('gives_spread', $product === 'gold'
                    ? 'A gold buyer code gives the buyer the seller\'s price.'
                    : 'Only gold has a buy and sell difference to give back.');
            }

            $depositPercent = $in['deposit_percent'] ?? null;
            $depositAmount = $in['deposit_amount_egp'] ?? null;
            $depositMode = $depositPercent !== null ? 'percent' : ($depositAmount !== null ? 'amount' : 'none');
            if ($depositPercent !== null && $depositAmount !== null) {
                throw $this->invalid('deposit_percent', 'Take the deposit down by a percentage or by an amount, not both.');
            }
            if ($kind === 'buyer' && $product === 'gold' && $depositMode !== 'none') {
                throw $this->invalid('deposit_percent', 'A gold buyer code does not change the deposit.');
            }
            if ($kind === 'buyer' && $product !== 'gold' && $depositMode === 'none') {
                throw $this->invalid('deposit_percent', 'Say how much to take off the deposit: a percentage or an amount.');
            }
            if ($kind !== 'buyer' && $depositMode !== 'none') {
                throw $this->invalid('deposit_percent', 'Only a buyer code changes the deposit.');
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
                    'commission_amount_egp' => $amount === null ? null : (string) $amount,
                    'commission_waived' => $mode === 'waive',
                    'side' => $side,
                    'product_type' => $product,
                    'deposit_mode' => $depositMode,
                    'deposit_percent' => $depositPercent === null ? null : (string) $depositPercent,
                    'deposit_amount_egp' => $depositAmount === null ? null : (string) $depositAmount,
                    'gives_spread' => $givesSpread,
                    'use_limit' => $in['use_limit'] ?? 'unlimited',
                    'first_sale_only' => $firstSaleOnly,
                    'pays_advance' => $paysAdvance,
                    'min_listed_days' => $in['min_listed_days'] ?? null,
                    'requires_approval' => $kind === 'market_maker' ? (bool) ($in['requires_approval'] ?? true) : false,
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
                    'commission_amount_egp' => $amount === null ? null : (string) $amount,
                    'side' => $side, 'product_type' => $product,
                    'deposit_mode' => $depositMode, 'deposit_percent' => $depositPercent, 'deposit_amount_egp' => $depositAmount,
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

        // A buyer code by product: any account (or one named), no dealer needed.
        if ($sent === 'buyer' || ($sent === null && ($in['side'] ?? null) === 'buyer')) {
            return 'buyer';
        }

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
