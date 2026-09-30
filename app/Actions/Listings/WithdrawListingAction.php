<?php

namespace App\Actions\Listings;

use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * The seller takes their own live listing off the market (spec 010 US4,
 * FR-014; Part 2 §3 withdraw). Final: a withdrawn piece is sold again only
 * as a new listing. From `live` only for now — `reserved → withdrawn`, with
 * its releases and refunds, belongs to the buy-request module.
 */
final class WithdrawListingAction
{
    use MovesListing;

    public function handle(Customer $seller, string $listingId): Listing
    {
        return DB::transaction(function () use ($seller, $listingId) {
            $listing = $this->lockListing($listingId);

            if ($listing->state !== ListingState::LIVE) {
                throw DomainApiException::illegalListingTransition();
            }

            return $this->moveListing($listing, ListingState::WITHDRAWN, $seller, null);
        });
    }
}
