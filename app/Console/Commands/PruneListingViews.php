<?php

namespace App\Console\Commands;

use App\Enums\SettingKey;
use App\Support\DatabaseActor;
use App\Support\Pricing\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete listing views older than `listing.view_retention_days` (spec 023 R18). Daily. The table's
 * guard allows a delete only past that window.
 */
class PruneListingViews extends Command
{
    protected $signature = 'listing-views:prune';

    protected $description = 'Delete listing views older than the retention window';

    public function handle(Settings $settings): int
    {
        $days = $settings->integer(SettingKey::LISTING_VIEW_RETENTION_DAYS);

        $n = DatabaseActor::elevate('maintenance', fn () => DB::delete(
            'DELETE FROM listing_view WHERE day < ?::date',
            [CarbonImmutable::now('Africa/Cairo')->subDays($days)->toDateString()],
        ));

        $this->info("Deleted {$n} view row(s).");

        return self::SUCCESS;
    }
}
