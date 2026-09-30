<?php

namespace App\Actions\Listings;

use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingState;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * The suspension hold (spec 010 FR-037, research R12). Suspending a customer
 * takes each of their live listings off the market (`live → suspended_hold`);
 * reinstating puts each held one back (`suspended_hold → live`, keeping its
 * original `listed_at`). Both run inside the suspend / reinstate transaction,
 * so the account change and the listings change together or not at all.
 * Listings in any other state are untouched. No message is sent: the
 * suspension itself tells the customer.
 *
 * Until category pauses exist, every held listing is held because its seller
 * is suspended, so a reinstatement may release all of them.
 */
final class HoldListingsOfCustomerAction
{
    use MovesListing;

    /** @return int how many listings were taken off the market */
    public function hold(Staff $actor, string $customerId): int
    {
        return $this->moveAll($actor, $customerId, ListingState::LIVE, ListingState::SUSPENDED_HOLD, ListingStateChange::NOTE_SUSPENDED);
    }

    /** @return int how many listings went back on the market */
    public function restore(Staff $actor, string $customerId): int
    {
        return $this->moveAll($actor, $customerId, ListingState::SUSPENDED_HOLD, ListingState::LIVE, ListingStateChange::NOTE_REINSTATED);
    }

    private function moveAll(Staff $actor, string $customerId, ListingState $from, ListingState $to, string $note): int
    {
        return DB::transaction(function () use ($actor, $customerId, $from, $to, $note) {
            $listings = Listing::query()->where('seller_id', $customerId)->where('state', $from->value)
                ->orderBy('listing_id')->lockForUpdate()->get();

            foreach ($listings as $listing) {
                $this->moveListing($listing, $to, null, $actor, $note);
            }

            return $listings->count();
        });
    }
}
