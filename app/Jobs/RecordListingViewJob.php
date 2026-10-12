<?php

namespace App\Jobs;

use App\Support\DatabaseActor;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Count one view of a piece (spec 023 research R18; D23-6): one row per viewer per piece per Cairo
 * day, the viewer a keyed hash of the account or the device, so the table cannot say who looked.
 * Written after the response, as the system actor, so the public `market` scope stays read-only and a
 * queue outage loses a count, never a page. `ON CONFLICT DO NOTHING` makes a repeat a no-op.
 */
class RecordListingViewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /** @param  string  $viewer  the customer id or the raw device id (hashed here, never stored) */
    public function __construct(public readonly string $listingId, public readonly string $viewer) {}

    public static function hashFor(string $listingId, string $viewer): string
    {
        return hash_hmac('sha256', 'listing-view|'.$listingId.'|'.$viewer, (string) config('app.key'));
    }

    public function handle(): void
    {
        DatabaseActor::elevate('system', fn () => DB::statement(
            'INSERT INTO listing_view (listing_id, day, viewer_hash)
             SELECT ?, ?::date, ?
              WHERE EXISTS (SELECT 1 FROM listing WHERE listing_id = ?)
             ON CONFLICT DO NOTHING',
            // The Cairo day, read from the application clock (the database's clock is not the app's).
            [$this->listingId, CarbonImmutable::now('Africa/Cairo')->toDateString(), self::hashFor($this->listingId, $this->viewer), $this->listingId],
        ));
    }
}
