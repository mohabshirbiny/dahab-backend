<?php

namespace App\Actions\Listings;

use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Actions\BuyRequests\ReleaseQueueAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\BuyRequestEvent;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;

/**
 * The seller takes their own listing off the market (spec 010 US4, FR-014;
 * Part 2 §3 withdraw). Final: a withdrawn piece is sold again only as a new
 * listing. From `live`, and since spec 011 from `reserved`: then every buyer
 * in line is released and refunded in the same transaction (FR-019), which
 * needs the `queue` scope (the requests are other customers' rows).
 */
final class WithdrawListingAction
{
    use MovesListing, RunsInQueue;

    public function __construct(private readonly ReleaseQueueAction $queue) {}

    public function handle(Customer $seller, string $listingId): Listing
    {
        // Ownership under the seller's own row isolation: 404 for anyone else's listing.
        Listing::query()->where('seller_id', $seller->customer_id)->findOrFail($listingId);

        return $this->inQueue(function () use ($seller, $listingId) {
            $listing = $this->lockListing($listingId);
            $from = $listing->state;

            if ($from !== ListingState::LIVE && $from !== ListingState::RESERVED) {
                throw DomainApiException::illegalListingTransition();
            }

            $listing = $this->moveListing($listing, ListingState::WITHDRAWN, $seller, null);

            if ($from === ListingState::RESERVED) {
                $this->queue->releaseAll($listing, $seller, null, BuyRequestEvent::PIECE_WITHDRAWN);
            }

            return $listing->refresh();
        });
    }
}
