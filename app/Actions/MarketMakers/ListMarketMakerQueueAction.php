<?php

namespace App\Actions\MarketMakers;

use App\Enums\ListingState;
use App\Enums\SettingKey;
use App\Models\Listing;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Settings;
use Illuminate\Support\Collection;

/**
 * The pieces waiting for a market-maker approval (spec 019 FR-062): live, listed for at least
 * `marketmaker.min_list_age_days`, not yet approved. Each carries the numbers the approver
 * weighs; how many views it had is *not measured* (no source defines it), and is said so.
 */
final class ListMarketMakerQueueAction
{
    public function __construct(private readonly Settings $settings, private readonly ListingPricer $pricer) {}

    /** @return array{days: int, rows: Collection<int, array<string, mixed>>} */
    public function handle(int $limit = 100): array
    {
        $days = $this->settings->integer(SettingKey::MARKETMAKER_MIN_LIST_AGE_DAYS);

        $rows = Listing::query()
            ->where('state', ListingState::LIVE->value)
            ->where('listed_at', '<=', now()->subDays($days))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('market_maker_approval as a')->whereColumn('a.listing_id', 'listing.listing_id'))
            ->with('pieceType')
            ->orderBy('listed_at')
            ->limit($limit)
            ->get();

        return [
            'days' => $days,
            'rows' => $rows->map(function (Listing $l) {
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
                    'views' => null,
                ];
            }),
        ];
    }
}
