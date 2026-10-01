<?php

namespace App\Jobs;

use App\Actions\BuyRequests\NotifyWhenFreeAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A listing went back to live with nobody in line (spec 011 FR-011, Part 2
 * §11 "Notify-when-free"). Runs as the system actor after the move commits.
 */
class NotifyWhenFreeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $listingId) {}

    public function handle(NotifyWhenFreeAction $notify): void
    {
        $notify->handle($this->listingId);
    }
}
