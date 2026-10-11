<?php

namespace App\Support\MarketMakers;

use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\MarketMakerApproval;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether a market-maker code may be used for one buy request (spec 019 FR-063, D3), and the
 * log of every attempt (FR-061).
 *
 * A code is valid for a request when it is on, belongs to the buyer's own account, the piece
 * is approved and has been listed for the minimum age, and the buyer's monthly cap is not
 * passed by this request together with the ones still open. The code row is locked, so two
 * requests at once cannot both pass the last of the cap. Reasons: inactive, not_your_code,
 * not_approved, too_new, cap_reached.
 */
final class PromoCodeGate
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @throws DomainApiException `promo_code_invalid` with the reason
     */
    public function validate(Customer $buyer, Listing $listing, string $price, string $code): PromoCode
    {
        $promo = PromoCode::query()->whereKey($code)->lockForUpdate()->first();
        if ($promo === null || ! $promo->is_active || $promo->kind !== 'market_maker') {
            throw DomainApiException::promoCodeInvalid('inactive');
        }
        if ($promo->tied_customer_id !== $buyer->customer_id) {
            throw DomainApiException::promoCodeInvalid('not_your_code');
        }
        if (! MarketMakerApproval::query()->where('listing_id', $listing->listing_id)->exists()) {
            throw DomainApiException::promoCodeInvalid('not_approved');
        }

        $days = $this->settings->integer(SettingKey::MARKETMAKER_MIN_LIST_AGE_DAYS);
        if ($listing->listed_at === null || $listing->listed_at->gt(now()->subDays($days))) {
            throw DomainApiException::promoCodeInvalid('too_new');
        }

        if ($promo->monthly_cap_egp !== null) {
            $monthStart = CarbonImmutable::now()->startOfMonth();
            // Uses whose request is still open or has become an order; a request that was released
            // or withdrawn frees its share again.
            $open = (string) (DB::table('promo_code_use as u')
                ->join('buy_request as b', 'b.buy_request_id', '=', 'u.buy_request_id')
                ->where('u.code', $promo->code)->where('u.allowed', true)->where('u.used_at', '>=', $monthStart)
                ->whereIn('b.state', ['queued', 'accepted'])
                ->sum('u.amount_egp') ?: '0');

            if (Money::cmp(Money::add($open, $price), (string) $promo->monthly_cap_egp) > 0) {
                throw DomainApiException::promoCodeInvalid('cap_reached');
            }
        }

        return $promo;
    }

    public function allowed(PromoCode $promo, Customer $buyer, string $buyRequestId, string $price, ?RequestContext $ctx): void
    {
        PromoCodeUse::query()->create([
            'code' => $promo->code,
            'customer_id' => $buyer->customer_id,
            'allowed' => true,
            'buy_request_id' => $buyRequestId,
            'amount_egp' => $price,
            'device_fingerprint' => $ctx?->deviceFingerprintHash,
        ]);
    }

    /** A refused use, written on its own so the refusal that rolled the request back keeps it. */
    public function blocked(string $code, Customer $buyer, string $reason, ?RequestContext $ctx): void
    {
        if (! PromoCode::query()->whereKey($code)->exists()) {
            return;
        }

        PromoCodeUse::query()->create([
            'code' => $code,
            'customer_id' => $buyer->customer_id,
            'allowed' => false,
            'block_reason' => $reason,
            'device_fingerprint' => $ctx?->deviceFingerprintHash,
        ]);
    }
}
