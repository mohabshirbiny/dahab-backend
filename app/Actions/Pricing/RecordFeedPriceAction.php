<?php

namespace App\Actions\Pricing;

use App\Actions\Pricing\Concerns\LocksGoldPrice;
use App\Enums\PriceSource;
use App\Models\GoldPrice;
use App\Models\PriceFeedStatus;
use App\Services\PriceFeed\GoldQuote;
use App\Support\SystemActor;

/**
 * Store a good feed reading (spec 005 FR-016, Part 4 §1.4): a new price only
 * when the bid or ask changed, attributed to the system actor; any pending
 * manual request is closed, since the feed takes over again. Every good
 * reading refreshes the feed's health.
 */
final class RecordFeedPriceAction
{
    use LocksGoldPrice;

    public function handle(GoldQuote $quote): ?GoldPrice
    {
        return $this->withPriceLock(function () use ($quote) {
            $current = GoldPrice::current();
            $changed = $current === null
                || bccomp((string) $current->bid_24k, $quote->bid24k, 4) !== 0
                || bccomp((string) $current->ask_24k, $quote->ask24k, 4) !== 0;

            $this->closePending();

            $price = $changed ? GoldPrice::query()->create([
                'source' => PriceSource::FEED,
                'bid_24k' => $quote->bid24k,
                'ask_24k' => $quote->ask24k,
                'recorded_by' => SystemActor::id(),
            ]) : null;

            $status = PriceFeedStatus::row();
            $status->last_success_at = now();
            $status->save();

            return $price;
        });
    }

    public function recordFailure(string $error): void
    {
        $status = PriceFeedStatus::row();
        $status->last_failure_at = now();
        $status->last_error = mb_substr($error, 0, 500);
        $status->save();
    }
}
