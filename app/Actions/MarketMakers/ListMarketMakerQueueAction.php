<?php

namespace App\Actions\MarketMakers;

use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Enums\SettingKey;
use App\Models\Listing;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The pieces waiting for a market-maker approval (spec 019 FR-062, spec 023 Story 6): live,
 * listed for at least `marketmaker.min_list_age_days`, not yet approved. Each carries the numbers
 * the approver weighs: views (one per account or device per day, staff only), the requests ever
 * sent, the files that can be opened, and *Against the guide*:
 *
 *  - gold: the making charge as a whole percent of the gold value, `ok` up to
 *    `marketmaker.making_charge_warn_pct`, `bad` above it;
 *  - stones: the guide is Rapaport, an integration left out — `none` ("No guide yet").
 *
 * The API returns numbers and a tone, never prose.
 */
final class ListMarketMakerQueueAction
{
    public function __construct(private readonly Settings $settings, private readonly ListingPricer $pricer) {}

    /** @return array{days: int, require_approval: bool, rows: Collection<int, array<string, mixed>>} */
    public function handle(int $limit = 100): array
    {
        $days = $this->settings->integer(SettingKey::MARKETMAKER_MIN_LIST_AGE_DAYS);
        $warn = $this->settings->numeric(SettingKey::MARKETMAKER_MAKING_CHARGE_WARN_PCT);

        $rows = Listing::query()
            ->where('state', ListingState::LIVE->value)
            ->where('listed_at', '<=', now()->subDays($days))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('market_maker_approval as a')->whereColumn('a.listing_id', 'listing.listing_id'))
            ->with(['pieceType', 'media'])
            ->orderBy('listed_at')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('listing_id')->all();
        $views = $ids === [] ? collect() : DB::table('listing_view')->whereIn('listing_id', $ids)->groupBy('listing_id')
            ->selectRaw('listing_id, count(*) as n')->pluck('n', 'listing_id');
        $requests = $ids === [] ? collect() : DB::table('buy_request')->whereIn('listing_id', $ids)->groupBy('listing_id')
            ->selectRaw('listing_id, count(*) as n')->pluck('n', 'listing_id');

        return [
            'days' => $days,
            'require_approval' => $this->settings->bool(SettingKey::MARKETMAKER_REQUIRE_APPROVAL),
            'rows' => $rows->map(function (Listing $l) use ($warn, $views, $requests) {
                $quote = $this->pricer->quote($l);

                return [
                    'listing_id' => $l->listing_id,
                    'title' => $l->title(),
                    'category' => $l->category->value,
                    'karat' => $l->karat_code,
                    'weight_g' => $l->stated_weight_g,
                    'asking_price' => $l->asking_price,
                    'current_price' => $quote->currentPrice,
                    'gold_value' => $quote->goldValue ?? null,
                    'listed_at' => $l->listed_at?->toIso8601String(),
                    'days_listed' => (int) $l->listed_at?->diffInDays(now()),
                    'views' => (int) ($views[$l->listing_id] ?? 0),
                    'requests' => (int) ($requests[$l->listing_id] ?? 0),
                    'media' => $l->media->map(fn ($m) => ['media_id' => $m->media_id, 'kind' => $m->kind->value])->values()->all(),
                    'guide' => $this->guide($l, $quote->makingTotal ?? null, $quote->goldValue ?? null, $warn),
                ];
            }),
        ];
    }

    /** @return array{kind: string, value_pct: int|null, tone: string} */
    private function guide(Listing $l, ?string $making, ?string $goldValue, string $warn): array
    {
        if ($l->category !== PieceCategory::GOLD || $making === null || $goldValue === null || Money::cmp($goldValue, '0') <= 0) {
            return ['kind' => 'none', 'value_pct' => null, 'tone' => 'none'];
        }

        $pct = (int) round((float) Money::mul(Money::div($making, $goldValue), '100'));

        return ['kind' => 'making_charge', 'value_pct' => $pct, 'tone' => Money::cmp((string) $pct, $warn) > 0 ? 'bad' : 'ok'];
    }
}
