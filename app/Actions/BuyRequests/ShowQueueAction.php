<?php

namespace App\Actions\BuyRequests;

use App\Models\BuyRequest;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Support\Collection;

/**
 * The line on the seller's own listing (spec 011 US3, FR-013; Part 2 §4
 * "the seller's view of the queue"). Ownership is proven first in the
 * customer scope (the owner policy — 404 for anyone else's listing); only
 * then the queued requests and the buyers' display references are read in
 * the `queue` scope. The Resource returns nothing else about a buyer.
 */
final class ShowQueueAction
{
    /** @return array{listing: Listing, queue: Collection<int, BuyRequest>} */
    public function handle(string $sellerId, string $listingId): array
    {
        $listing = Listing::query()->where('seller_id', $sellerId)->with(['pieceType', 'media', 'branches', 'changes'])->findOrFail($listingId);

        $queue = DatabaseActor::queue(fn () => BuyRequest::query()->where('listing_id', $listing->listing_id)
            ->queued()->orderBy('queue_position')->with('buyer:customer_id,display_ref,status')
            ->limit((int) config('dahab-buy-requests.queue_max'))->get());

        return ['listing' => $listing, 'queue' => $queue];
    }
}
