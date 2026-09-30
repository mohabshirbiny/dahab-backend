<?php

namespace App\Actions\Listings\Concerns;

use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Staff;
use App\Support\Listings\ListingTransitions;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only way a listing changes state (spec 010 FR-015, FR-017, research
 * R3). Inside the caller's transaction: lock the row, check the move against
 * `listing_transition`, change the state and write the history row naming
 * the actor. Two competing moves wait on the row lock; the loser finds the
 * state already changed and is refused with `illegal_listing_transition`.
 * The database repeats both checks (trg_listing_guard,
 * trg_listing_change_recorded).
 */
trait MovesListing
{
    /** The listing, locked for this transaction. 404 when the current scope cannot see it. */
    private function lockListing(string $listingId): Listing
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A listing is locked and moved inside a transaction.');
        }

        return Listing::query()->whereKey($listingId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Move a listing locked by lockListing(). Exactly one of `$byCustomer` /
     * `$byStaff` is the actor.
     */
    private function moveListing(Listing $listing, ListingState $to, ?Customer $byCustomer, ?Staff $byStaff, ?string $note = null): Listing
    {
        $from = $listing->state;

        if (! app(ListingTransitions::class)->allows($from, $to)) {
            throw DomainApiException::illegalListingTransition();
        }

        $listing->state = $to;
        $listing->save();

        ListingStateChange::query()->create([
            'listing_id' => $listing->listing_id,
            'from_state' => $from,
            'to_state' => $to,
            'actor_customer_id' => $byCustomer?->customer_id,
            'actor_staff_id' => $byStaff?->staff_id,
            'note' => $note,
        ]);

        // state_changed_at and listed_at are stamped by the guard trigger.
        return $listing->refresh();
    }

    /** The first history row: the seller created the draft. */
    private function recordListingCreated(Listing $listing, Customer $seller): void
    {
        ListingStateChange::query()->create([
            'listing_id' => $listing->listing_id,
            'from_state' => null,
            'to_state' => ListingState::DRAFT,
            'actor_customer_id' => $seller->customer_id,
        ]);
    }
}
