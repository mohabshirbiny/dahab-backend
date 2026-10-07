<?php

namespace App\Console\Commands;

use App\Actions\ListingReports\ListingReportsAction;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Console\Command;

/**
 * Close the reports of pieces that left the market (spec 017 FR-053, research
 * R9): taken off by the seller, sold, held… Runs as the system actor.
 */
class CloseGoneListingReports extends Command
{
    protected $signature = 'listing-reports:close-gone';

    protected $description = 'Close open listing reports whose piece is no longer on the market';

    public function handle(ListingReportsAction $reports): int
    {
        $closed = DatabaseActor::elevate('system', fn () => $reports->closeGone(), SystemActor::id());
        $this->info("Closed {$closed} report(s).");

        return self::SUCCESS;
    }
}
