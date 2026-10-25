<?php

namespace App\Support\Promo;

use App\Enums\SettingKey;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Support\Pricing\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The staff-side shape of a promo code (spec 023 contracts/staff-promo-api.md §1): what it does,
 * who it is tied to, its conditions and what it has cost this Cairo month. Every field of the
 * spec 019 row stays; the new ones are additive.
 */
final class PromoCodePresenter
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  iterable<PromoCode>  $codes
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $codes): array
    {
        $codes = collect($codes)->values();
        $stats = $this->monthStats($codes->pluck('code')->all());
        $minAge = $this->settings->integer(SettingKey::MARKETMAKER_MIN_LIST_AGE_DAYS);
        $needsApproval = $this->settings->bool(SettingKey::MARKETMAKER_REQUIRE_APPROVAL);

        return $codes->map(fn (PromoCode $c) => $this->row($c, $stats[$c->code] ?? ['uses' => 0, 'value' => '0.0000'], $minAge, $needsApproval))->all();
    }

    public function one(PromoCode $code): array
    {
        return $this->rows([$code])[0];
    }

    /**
     * @param  array{uses: int, value: string}  $stats
     * @return array<string, mixed>
     */
    private function row(PromoCode $c, array $stats, int $minAge, bool $needsApproval): array
    {
        $dealer = $c->kind === 'market_maker';

        return [
            'code' => $c->code,
            'kind' => $c->kind,
            'side' => $c->side,
            'product_type' => $c->product_type,
            'customer' => $c->customer === null ? null : ['id' => $c->customer->customer_id, 'display_ref' => $c->customer->display_ref, 'full_name' => $c->customer->full_name],
            'what_it_does' => [
                'commission' => [
                    'mode' => $c->commission_mode,
                    'percent' => $c->commission_percent === null ? null : number_format((float) $c->commission_percent, 4, '.', ''),
                    'amount_egp' => $c->commission_amount_egp === null ? null : number_format((float) $c->commission_amount_egp, 4, '.', ''),
                ],
                'deposit' => [
                    'mode' => $c->deposit_mode,
                    'percent' => $c->deposit_percent === null ? null : number_format((float) $c->deposit_percent, 4, '.', ''),
                    'amount_egp' => $c->deposit_amount_egp === null ? null : number_format((float) $c->deposit_amount_egp, 4, '.', ''),
                ],
                'gives_spread' => $c->gives_spread,
                'pays_advance' => $c->pays_advance,
            ],
            'tied_to' => $c->audience === 'account' && $c->customer !== null
                ? ['audience' => 'account', 'customer' => [
                    'id' => $c->customer->customer_id, 'display_ref' => $c->customer->display_ref,
                    'full_name' => $c->customer->full_name, 'phone_masked' => $this->maskPhone($c->customer),
                ]]
                : ['audience' => $c->audience],
            'first_sale_only' => $c->first_sale_only,
            'conditions' => [
                'use_limit' => $c->use_limit,
                // The age and approval gate a dealer's buy request, so they are shown for dealer codes only
                // (research R9), with the effective value: the code's own term, else the setting.
                'min_listed_days' => $dealer ? ($c->min_listed_days ?? $minAge) : null,
                'requires_approval' => $dealer ? ($c->requires_approval && $needsApproval) : null,
                'starts_on' => $c->starts_on?->toDateString(),
                'ends_on' => $c->ends_on?->toDateString(),
            ],
            'monthly_cap_egp' => $c->monthly_cap_egp,
            'is_active' => $c->is_active,
            'commission_waived' => $c->commission_waived,
            'gives_spread' => $c->gives_spread,
            'uses_this_month' => $stats['uses'],
            'value_this_month_egp' => $stats['value'],
            'created_at' => $c->created_at->toIso8601String(),
            'deactivated_at' => $c->deactivated_at?->toIso8601String(),
        ];
    }

    /**
     * Allowed, non-voided uses of the Cairo calendar month and their value, per code.
     *
     * @param  list<string>  $codes
     * @return array<string, array{uses: int, value: string}>
     */
    private function monthStats(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $start = CarbonImmutable::now('Africa/Cairo')->startOfMonth();
        $rows = DB::table('promo_code_use as u')
            ->leftJoin('commission_credit as c', 'c.credit_id', '=', 'u.credit_id')
            ->whereIn('u.code', $codes)->where('u.allowed', true)->where('u.used_at', '>=', $start)
            // A use counts while its credit is not voided; a credit-less seller use while its code is on the listing.
            ->where(fn ($q) => $q->where(fn ($d) => $d->whereNotNull('u.credit_id')->where('c.status', '<>', 'voided'))
                ->orWhere(fn ($d) => $d->whereNull('u.credit_id')->whereNull('u.listing_id'))
                ->orWhere(fn ($d) => $d->whereNull('u.credit_id')->whereNotNull('u.listing_id')
                    ->whereExists(fn ($e) => $e->selectRaw('1')->from('listing_promo as lp')
                        ->whereColumn('lp.listing_id', 'u.listing_id')->whereColumn('lp.promo_code', 'u.code'))))
            ->selectRaw('u.code, count(*) as n, coalesce(sum(u.amount_egp), 0) as v')->groupBy('u.code')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->code] = ['uses' => (int) $r->n, 'value' => number_format((float) $r->v, 4, '.', '')];
        }

        return $out;
    }

    /** `+20 10 ••8842`: the country, the first two digits and the last four, never the whole number. */
    private function maskPhone(Customer $customer): ?string
    {
        $phone = (string) $customer->phone;
        if ($phone === '') {
            return null;
        }
        if (preg_match('/^\+20(\d{2})\d+(\d{4})$/', $phone, $m)) {
            return "+20 {$m[1]} ••{$m[2]}";
        }

        return '••'.substr($phone, -4);
    }
}
