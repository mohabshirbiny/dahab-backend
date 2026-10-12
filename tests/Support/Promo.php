<?php

namespace Tests\Support;

use App\Enums\SettingKey;
use App\Models\CommissionCredit;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\PromoCode;
use App\Models\Referral;
use App\Models\Setting;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 023 fixtures: codes and credits are written straight to the tables (as the other spec
 * fixtures do), so a test can set up exactly the state it wants. The behaviour under test goes
 * through the HTTP boundary.
 */
final class Promo
{
    public const PREVIEW_URL = '/api/v1/customer/me/commission-preview';

    public const LISTINGS_URL = '/api/v1/customer/me/listings';

    /** Switch the three master switches (data-model §7: all default off). */
    public static function open(bool $firstSale = false, bool $sellerCodes = true, bool $referral = false): void
    {
        self::set(SettingKey::PAYOUT_FIRST_SALE_ENABLED, $firstSale);
        self::set(SettingKey::PROMO_SELLER_CODES_ENABLED, $sellerCodes);
        self::set(SettingKey::REFERRAL_ENABLED, $referral);
    }

    public static function set(SettingKey $key, bool|int|string $value): void
    {
        $column = is_bool($value) ? 'value_bool' : 'value_numeric';
        Setting::query()->whereKey($key->value)->update([$column => $value]);
    }

    public static function key(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    /**
     * A seller-side code (a 50 % discount for anyone by default). `$attributes` override any column.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function code(array $attributes = []): PromoCode
    {
        $attributes += [
            'code' => 'CODE-'.strtoupper(Str::random(6)),
            'kind' => 'discount',
            'audience' => 'anyone',
            'commission_mode' => 'percent',
            // The system actor, not a fresh staff row: a staff member made before the roles are seeded stops the seeding.
            'created_by' => SystemActor::id(),
        ];
        if ($attributes['commission_mode'] === 'percent') {
            $attributes['commission_percent'] ??= '50';
        }
        // The two columns that must agree with the mode.
        $attributes['commission_waived'] ??= $attributes['commission_mode'] === 'waive';

        return PromoCode::query()->create($attributes)->refresh();
    }

    /** A dealer code (waive + the spread to the dealer) tied to `$dealer`. */
    public static function dealerCode(Customer $dealer, array $attributes = []): PromoCode
    {
        $dealer->forceFill(['customer_type' => 'market_maker'])->save();

        return self::code($attributes + [
            'code' => 'DEALER-'.strtoupper(Str::random(5)),
            'kind' => 'market_maker',
            'audience' => 'account',
            'tied_customer_id' => $dealer->customer_id,
            'commission_mode' => 'waive',
            'gives_spread' => true,
        ]);
    }

    /**
     * A commission credit written directly (bound to `$listing` or, for an inviter reward, the pool).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function credit(Customer $seller, ?Listing $listing, array $attributes = []): CommissionCredit
    {
        $kind = $attributes['kind'] ?? 'percent';
        $base = [
            'customer_id' => $seller->customer_id,
            'source' => $listing === null ? 'referral_inviter' : 'promo_code',
            'kind' => $kind,
            'listing_id' => $listing?->listing_id,
        ];
        if ($kind === 'percent') {
            $base += ['percent_off' => '50', 'uses_total' => 1, 'uses_left' => 1];
        } elseif ($kind === 'waive') {
            $base += ['uses_total' => 1, 'uses_left' => 1];
        } else {
            $base += ['amount_egp' => '200', 'amount_left_egp' => '200'];
        }

        $credit = $attributes + $base;
        if ($credit['source'] === 'promo_code' && ! isset($credit['promo_code'])) {
            $credit['promo_code'] = self::code()->code;
        }
        if ($credit['source'] !== 'promo_code' && ! isset($credit['referral_id'])) {
            $credit['referral_id'] = self::referral($seller)->referral_id;
        }

        return CommissionCredit::query()->create($credit)->refresh();
    }

    /** A referral row of `$inviter` with a fresh friend (the friend's account is created). */
    public static function referral(Customer $inviter, ?Customer $friend = null, array $attributes = []): Referral
    {
        $friend ??= Customer::factory()->verified()->create();
        DB::table('referral_code')->insertOrIgnore(['customer_id' => $inviter->customer_id, 'code' => strtoupper(Str::random(8))]);

        return Referral::query()->create($attributes + [
            'inviter_id' => $inviter->customer_id,
            'friend_id' => $friend->customer_id,
            'code' => (string) DB::table('referral_code')->where('customer_id', $inviter->customer_id)->value('code'),
        ])->refresh();
    }
}
