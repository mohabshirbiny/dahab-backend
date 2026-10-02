<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;

/**
 * The seller declines the first in line, with no reason (spec 011 US3,
 * FR-015; Clarification: head only). The buyer is refunded, the next request
 * becomes the head, and with nobody left the piece is live again. One
 * transaction under the `queue` scope, listing locked first.
 */
final class DeclineBuyRequestAction
{
    use ReleasesRequests, RunsInQueue;

    public function handle(Customer $seller, string $listingId, string $requestId): Listing
    {
        Listing::query()->where('seller_id', $seller->customer_id)->findOrFail($listingId);

        return $this->inQueue(function () use ($seller, $listingId, $requestId) {
            $listing = $this->lockListing($listingId);
            if ($listing->state !== ListingState::RESERVED) {
                throw DomainApiException::queueEmpty();
            }

            $head = $this->lockQueue($listing)->first();
            if ($head === null) {
                throw DomainApiException::queueEmpty();
            }
            if ($head->buy_request_id !== $requestId) {
                throw DomainApiException::notQueueHead();
            }

            $this->releaseRequest($head, BuyRequestState::RELEASED_DECLINED, $listing, $seller, null, BuyRequestEvent::DECLINED);
            $listing = $this->syncListingAfterRelease($listing, $seller, null);
            $this->flushAfterCommit();

            return $listing;
        });
    }
}
