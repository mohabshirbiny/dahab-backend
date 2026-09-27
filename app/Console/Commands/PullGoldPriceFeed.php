<?php

namespace App\Console\Commands;

use App\Actions\Pricing\RecordFeedPriceAction;
use App\Contracts\GoldPriceFeed;
use App\Services\PriceFeed\GoldFeedException;
use App\Support\DatabaseActor;
use App\Support\Pricing\FeedHealth;
use App\Support\SystemActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read the gold price provider once (spec 005 US1). Scheduled every minute
 * in routes/console.php; runs as the system actor.
 */
class PullGoldPriceFeed extends Command
{
    protected $signature = 'pricing:pull-feed';

    protected $description = 'Read the 24K bid and ask from the gold price provider and record them when they changed';

    public function handle(GoldPriceFeed $feed, RecordFeedPriceAction $record): int
    {
        if (! FeedHealth::configured()) {
            $this->components->info('The gold price feed is not configured; manual prices are in use.');

            return self::SUCCESS;
        }

        return DatabaseActor::elevate('system', function () use ($feed, $record) {
            try {
                $price = $record->handle($feed->fetch());
            } catch (GoldFeedException $e) {
                $record->recordFailure($e->getMessage());
                Log::warning('pricing.feed.failed', ['error' => $e->getMessage()]);
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info($price === null ? 'Gold price unchanged.' : 'Gold price recorded.');

            return self::SUCCESS;
        }, SystemActor::id());
    }
}
