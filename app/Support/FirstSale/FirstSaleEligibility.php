<?php

namespace App\Support\FirstSale;

use App\Enums\CustomerStatus;
use App\Enums\PieceCategory;
use App\Enums\SettingKey;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\PromoCode;
use App\Support\Pricing\CommissionResolution;
use App\Support\Pricing\Money;
use App\Support\Pricing\PriceBreakdown;
use App\Support\Pricing\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Whether a seller's first gold sale may be paid ahead (spec 023 research R11–R13; TS Part 3 §6.1).
 *
 * All must hold at the moment: the switch is on; the piece is gold (never diamond or gold with
 * diamond); the seller has no earlier advance (the database's UNIQUE is the backstop) and no
 * earlier paid gold sale; the seller is not suspended; a gate is open — the listing carries a code
 * that pays the advance, or staff enabled the seller — and the exposure ceiling is not passed.
 * Never automatic. The reasons are plain words the sell form maps.
 */
final class FirstSaleEligibility
{
    public const REASON_SWITCH_OFF = 'switch_off';

    public const REASON_CEILING = 'ceiling';

    public const REASON_NOT_FIRST_GOLD = 'not_first_gold';

    public const REASON_NOT_GOLD = 'not_gold';

    public const REASON_SELLER_NOT_ELIGIBLE = 'seller_not_eligible';

    public function __construct(private readonly Settings $settings) {}

    /** EGP out in advances that are still outstanding, across everyone. */
    public function exposure(): string
    {
        return Money::fixed4((string) (DB::table('first_sale_advance')->where('status', 'outstanding')->sum('amount_egp') ?: '0'));
    }

    /** The reason the seller cannot be advanced for this category right now, or null when they can. */
    public function reason(Customer $seller, PieceCategory $category, bool $checkCeiling = true): ?string
    {
        if (! $this->settings->bool(SettingKey::PAYOUT_FIRST_SALE_ENABLED)) {
            return self::REASON_SWITCH_OFF;
        }
        if ($category !== PieceCategory::GOLD) {
            return self::REASON_NOT_GOLD;
        }
        if ($seller->status === CustomerStatus::SUSPENDED) {
            return self::REASON_SELLER_NOT_ELIGIBLE;
        }
        if (DB::table('first_sale_advance')->where('seller_id', $seller->customer_id)->exists()
            || $this->hasPaidGoldSale($seller)) {
            return self::REASON_NOT_FIRST_GOLD;
        }
        if ($checkCeiling && Money::cmp($this->exposure(), $this->settings->numeric(SettingKey::PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP)) >= 0) {
            return self::REASON_CEILING;
        }

        return null;
    }

    /**
     * What opens the gate for this listing: its first-sale code, or an open staff enablement.
     *
     * @return array{basis: string, promo_code: string|null, enablement_id: string|null}|null
     */
    public function gate(Customer|string $seller, Listing $listing): ?array
    {
        $code = DB::table('listing_promo as lp')
            ->join('promo_code as p', 'p.code', '=', 'lp.promo_code')
            ->where('lp.listing_id', $listing->listing_id)->where('p.pays_advance', true)
            ->value('p.code');
        if ($code !== null) {
            return ['basis' => 'promo_code', 'promo_code' => (string) $code, 'enablement_id' => null];
        }

        $enablement = $this->openEnablement($seller);

        return $enablement === null ? null : ['basis' => 'staff_enablement', 'promo_code' => null, 'enablement_id' => $enablement];
    }

    public function openEnablement(Customer|string $seller): ?string
    {
        // A customer never reads the enablement table; the function answers with the open one's id.
        $id = DB::selectOne('SELECT first_sale_open_enablement(?) AS id', [$seller instanceof Customer ? $seller->customer_id : $seller])->id;

        return $id === null ? null : (string) $id;
    }

    /**
     * The sell form's "paid early" shape (contract customer-sell-api §1/§4): null unless the code
     * pays the advance or the seller has an open enablement.
     *
     * @return array{available: bool, cap_egp: string, reason: string|null}|null
     */
    public function preview(Customer $seller, Listing $draft, ?PromoCode $code, PriceBreakdown $ordinary, CommissionResolution $resolution): ?array
    {
        if (($code === null || ! $code->pays_advance) && $this->openEnablement($seller) === null) {
            return null;
        }

        $reason = $this->reason($seller, $draft->category);

        return [
            'available' => $reason === null,
            'cap_egp' => Money::fixed4($this->settings->numeric(SettingKey::PAYOUT_FIRST_SALE_CAP_EGP)),
            'reason' => $reason,
        ];
    }

    /** Any settled gold order of the seller (the listing's category is gold). */
    private function hasPaidGoldSale(Customer $seller): bool
    {
        return DB::table('order as o')->join('listing as l', 'l.listing_id', '=', 'o.listing_id')
            ->where('o.seller_id', $seller->customer_id)->whereNotNull('o.settlement_txn_id')
            ->where('l.category', PieceCategory::GOLD->value)->exists();
    }
}
