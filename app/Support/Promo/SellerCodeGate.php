<?php

namespace App\Support\Promo;

use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether a seller may use a code (promo or referral) on a sale (spec 023 research R7, R22).
 *
 * The checks run in this order and the first failure wins; the reason travels in
 * `promo_code_invalid.details.reason`:
 *
 *   not_open · unknown · wrong_product · not_started · ended · first_sale_only · already_used · cap_reached
 *   own_referral · referral_joined · referral_full · device_limit
 *
 * An account-tied code of someone else, an inactive code and a missing code are all `unknown`, so
 * the existence of a private code is never revealed. A "once per ID" limit counts against the
 * national-ID key (CustomerIdKey), a cap by the listing's price at redemption (P23-10). The code
 * row is locked when `$lock` (redemption), so two redemptions cannot both take the last of a cap.
 *
 * Runs in the `promo` scope: cross-customer counts come from SQL functions as plain numbers.
 */
final class SellerCodeGate
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @throws DomainApiException promo_code_invalid
     */
    public function check(Customer $seller, string $input, string $price, bool $lock = false, ?RequestContext $ctx = null, ?string $category = null): SellerCode
    {
        $code = strtoupper(trim($input));
        $promoEnabled = $this->settings->bool(SettingKey::PROMO_SELLER_CODES_ENABLED);
        $referralEnabled = $this->settings->bool(SettingKey::REFERRAL_ENABLED);

        $query = PromoCode::query()->whereKey($code);
        $promo = ($lock ? $query->lockForUpdate() : $query)->first();

        // Dealer and buyer codes are not seller codes: to a seller they do not exist.
        if ($promo !== null && in_array($promo->kind, ['discount', 'first_sale'], true)) {
            if (! $promoEnabled) {
                throw DomainApiException::promoCodeInvalid('not_open');
            }

            return $this->checkPromo($seller, $promo, $price, $category);
        }

        $inviterId = $promo === null ? $this->inviterOf($code) : null;
        if ($inviterId !== null) {
            if (! $referralEnabled) {
                throw DomainApiException::promoCodeInvalid('not_open');
            }

            return $this->checkReferral($seller, $code, $inviterId, $ctx);
        }

        // Nothing matches. With both programmes closed the honest answer is "not open".
        throw DomainApiException::promoCodeInvalid(! $promoEnabled && ! $referralEnabled ? 'not_open' : 'unknown');
    }

    /** Record a refused attempt (staff read the log; the seller never does). */
    public function logBlocked(Customer $seller, string $input, string $reason, ?string $listingId, ?RequestContext $ctx): void
    {
        $code = strtoupper(trim($input));
        if (! PromoCode::query()->whereKey($code)->exists()) {
            return;
        }

        PromoCodeUse::query()->create([
            'code' => $code,
            'customer_id' => $seller->customer_id,
            'allowed' => false,
            'block_reason' => $reason,
            'listing_id' => $listingId,
            'id_key' => CustomerIdKey::for($seller),
            'device_fingerprint' => $ctx?->deviceFingerprintHash,
        ]);
    }

    private function checkPromo(Customer $seller, PromoCode $promo, string $price, ?string $category): SellerCode
    {
        if (! $promo->is_active) {
            throw DomainApiException::promoCodeInvalid('unknown');
        }
        if ($promo->audience === 'account' && $promo->tied_customer_id !== $seller->customer_id) {
            throw DomainApiException::promoCodeInvalid('unknown');
        }
        // A code is for one product: gold, diamond or gold with diamond.
        if ($promo->product_type !== null && $category !== null && $promo->product_type !== $category) {
            throw DomainApiException::promoCodeInvalid('wrong_product');
        }

        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        if ($promo->starts_on !== null && $today->lt($promo->starts_on->startOfDay())) {
            throw DomainApiException::promoCodeInvalid('not_started');
        }
        if ($promo->ends_on !== null && $today->gt($promo->ends_on->startOfDay())) {
            throw DomainApiException::promoCodeInvalid('ended');
        }

        if ($promo->first_sale_only && $this->hasPaidSale($seller)) {
            throw DomainApiException::promoCodeInvalid('first_sale_only');
        }

        $key = CustomerIdKey::for($seller);
        if ($promo->use_limit !== 'unlimited') {
            $since = $promo->use_limit === 'monthly' ? CarbonImmutable::now('Africa/Cairo')->startOfMonth() : null;
            $uses = (int) DB::selectOne('SELECT promo_code_id_uses(?, ?, ?) AS n', [$promo->code, $key, $since])->n;
            if ($uses > 0) {
                throw DomainApiException::promoCodeInvalid('already_used');
            }
        }

        if ($promo->monthly_cap_egp !== null) {
            $since = CarbonImmutable::now('Africa/Cairo')->startOfMonth();
            $used = (string) DB::selectOne('SELECT promo_code_value_since(?, ?) AS v', [$promo->code, $since])->v;
            if (Money::cmp(Money::add($used, $price), (string) $promo->monthly_cap_egp) > 0) {
                throw DomainApiException::promoCodeInvalid('cap_reached');
            }
        }

        return new SellerCode(promo: $promo, inviterId: null, referralCode: null, idKey: $key);
    }

    private function checkReferral(Customer $seller, string $code, string $inviterId, ?RequestContext $ctx): SellerCode
    {
        $key = CustomerIdKey::for($seller);

        if ($inviterId === $seller->customer_id
            || (bool) DB::selectOne('SELECT referral_same_person(?, ?) AS same', [$inviterId, $key])->same) {
            throw DomainApiException::promoCodeInvalid('own_referral');
        }
        if (DB::table('referral')->where('friend_id', $seller->customer_id)->exists()) {
            throw DomainApiException::promoCodeInvalid('referral_joined');
        }
        if ($this->hasPaidSale($seller)) {
            throw DomainApiException::promoCodeInvalid('first_sale_only');
        }
        if ((int) DB::selectOne('SELECT referral_inviter_count(?) AS n', [$inviterId])->n
            >= $this->settings->integer(SettingKey::REFERRAL_MAX_USES_PER_CODE)) {
            throw DomainApiException::promoCodeInvalid('referral_full');
        }
        $device = $ctx?->deviceFingerprintHash;
        if ($device !== null && (int) DB::selectOne('SELECT referral_device_count(?) AS n', [$device])->n
            >= $this->settings->integer(SettingKey::REFERRAL_MAX_PER_DEVICE)) {
            throw DomainApiException::promoCodeInvalid('device_limit');
        }

        return new SellerCode(promo: null, inviterId: $inviterId, referralCode: $code, idKey: $key);
    }

    private function inviterOf(string $code): ?string
    {
        if (! preg_match('/^[A-Z0-9]{8}$/', $code)) {
            return null;
        }

        $id = DB::selectOne('SELECT referral_lookup(?) AS id', [$code])->id;

        return $id === null ? null : (string) $id;
    }

    /** A paid sale as the seller: an order of theirs whose settlement posted. */
    private function hasPaidSale(Customer $seller): bool
    {
        return DB::table('order')->where('seller_id', $seller->customer_id)->whereNotNull('settlement_txn_id')->exists();
    }
}
