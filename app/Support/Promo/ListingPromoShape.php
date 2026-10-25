<?php

namespace App\Support\Promo;

use App\Enums\CreditStatus;
use App\Enums\SettingKey;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPromo;
use App\Support\DatabaseActor;
use App\Support\FirstSale\FirstSaleEligibility;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The `promo` and `pays_advance` blocks of the seller's own listing (spec 023 contract §4).
 * The listing's code row and its credit are the seller's own (row security). Lookups that repeat
 * across a list (a code's terms, the seller's enablement, their eligibility) are read once per
 * request, so a list stays a fixed number of queries.
 */
final class ListingPromoShape
{
    /** @return array<string, mixed>|null */
    public static function of(Listing $l): ?array
    {
        $row = $l->relationLoaded('promo') ? $l->promo : $l->promo()->first();
        if (! $row instanceof ListingPromo) {
            return null;
        }

        $credit = $row->credit_id === null ? null : ($row->relationLoaded('credit') ? $row->credit : $row->credit()->first());
        $code = $row->promo_code === null ? null : self::code($row->promo_code);

        $status = match ($credit?->status) {
            CreditStatus::EXHAUSTED => 'spent',
            CreditStatus::VOIDED, CreditStatus::EXPIRED => 'voided',
            default => 'active',
        };
        $inLine = in_array($l->state->value, ['reserved', 'accepted', 'at_inspection', 'settling', 'sold'], true);

        if ($code === null) {
            // A referral code: the friend's percentage is the credit's.
            return [
                'code' => 'referral', 'kind' => 'referral', 'source' => 'referral',
                'commission' => ['mode' => 'percent', 'percent' => $credit?->percent_off === null ? null : number_format((float) $credit->percent_off, 4, '.', '')],
                'pays_advance' => false, 'status' => $status, 'can_change' => ! $inLine,
            ];
        }

        return [
            'code' => $code->code, 'kind' => $code->kind, 'source' => 'promo_code',
            'commission' => [
                'mode' => $code->commission_mode,
                'percent' => $code->commission_percent === null ? null : number_format((float) $code->commission_percent, 4, '.', ''),
            ] + ($code->commission_mode === 'amount' ? ['amount_egp' => number_format((float) $code->commission_amount_egp, 4, '.', '')] : []),
            'pays_advance' => (bool) $code->pays_advance, 'status' => $status, 'can_change' => ! $inLine,
        ];
    }

    /** @return array{available: bool, cap_egp: string, reason: string|null}|null */
    public static function paysAdvance(Listing $l): ?array
    {
        $row = $l->relationLoaded('promo') ? $l->promo : $l->promo()->first();
        $code = $row instanceof ListingPromo && $row->promo_code !== null ? self::code($row->promo_code) : null;

        $reason = self::once('reason:'.$l->seller_id.':'.$l->category->value, function () use ($l) {
            $seller = Customer::query()->find($l->seller_id);

            return $seller === null ? FirstSaleEligibility::REASON_SELLER_NOT_ELIGIBLE : app(FirstSaleEligibility::class)->reason($seller, $l->category);
        });

        // Opened by the code or an enablement; otherwise shown only when the automatic advance is offered.
        $opens = $code !== null && $code->pays_advance;
        if (! $opens && ! FirstSaleEligibility::offeredAutomatically($reason)) {
            $enablement = self::once('enablement:'.$l->seller_id, fn () => app(FirstSaleEligibility::class)->openEnablement($l->seller_id));
            if ($enablement === null) {
                return null;
            }
        }

        return [
            'available' => $reason === null,
            'cap_egp' => Money::fixed4(app(Settings::class)->numeric(SettingKey::PAYOUT_FIRST_SALE_CAP_EGP)),
            'reason' => $reason,
        ];
    }

    /** A code's terms (readable in the `promo` scope), once per request. */
    private static function code(string $code): ?object
    {
        return self::once('code:'.$code, fn () => DatabaseActor::promo(fn () => DB::table('promo_code')->where('code', $code)->first()));
    }

    /** @template T @param Closure(): T $make @return T */
    private static function once(string $key, Closure $make): mixed
    {
        $bag = request()->attributes;
        $all = $bag->get('listing_promo_shape', []);
        if (! array_key_exists($key, $all)) {
            $all[$key] = $make();
            $bag->set('listing_promo_shape', $all);
        }

        return $all[$key];
    }
}
