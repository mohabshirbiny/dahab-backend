<?php

namespace App\Actions\Listings;

use App\Actions\BuyRequests\ReleaseQueueAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\BuyRequestEvent;
use App\Enums\ListingState;
use App\Jobs\NotifyWhenFreeJob;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * The suspension hold (spec 010 FR-037, research R12). Suspending a customer
 * takes each of their live listings off the market (`live → suspended_hold`)
 * and, since spec 011 (FR-020), each reserved one too — its line released and
 * refunded, the buyers told. Reinstating puts each held one back
 * (`suspended_hold → live`, keeping its original `listed_at`) with an empty
 * line, and tells anyone who asked to know when it is free. Both run inside
 * the suspend / reinstate transaction, so the account change and the listings
 * change together or not at all. Listings in any other state are untouched.
 *
 * Until category pauses exist, every held listing is held because its seller
 * is suspended, so a reinstatement may release all of them.
 */
final class HoldListingsOfCustomerAction
{
    use MovesListing;

    public function __construct(private readonly ReleaseQueueAction $queue) {}

    /** @return int how many listings were taken off the market */
    public function hold(Staff $actor, string $customerId): int
    {
        return DB::transaction(function () use ($actor, $customerId) {
            $listings = Listing::query()->where('seller_id', $customerId)
                ->whereIn('state', [ListingState::LIVE->value, ListingState::RESERVED->value])
                ->orderBy('listing_id')->lockForUpdate()->get();

            foreach ($listings as $listing) {
                $from = $listing->state;
                $listing = $this->moveListing($listing, ListingState::SUSPENDED_HOLD, null, $actor, ListingStateChange::NOTE_SUSPENDED);

                if ($from === ListingState::RESERVED) {
                    $this->queue->releaseAll($listing, null, $actor, BuyRequestEvent::SELLER_SUSPENDED);
                }
            }

            return $listings->count();
        });
    }

    /** @return int how many listings went back on the market */
    public function restore(Staff $actor, string $customerId): int
    {
        return DB::transaction(function () use ($actor, $customerId) {
            $listings = Listing::query()->where('seller_id', $customerId)->where('state', ListingState::SUSPENDED_HOLD->value)
                ->orderBy('listing_id')->lockForUpdate()->get();

            foreach ($listings as $listing) {
                $this->moveListing($listing, ListingState::LIVE, null, $actor, ListingStateChange::NOTE_REINSTATED);
            }

            $ids = $listings->pluck('listing_id')->all();
            DB::afterCommit(function () use ($ids) {
                foreach ($ids as $id) {
                    NotifyWhenFreeJob::dispatch($id);
                }
            });

            return $listings->count();
        });
    }
}
