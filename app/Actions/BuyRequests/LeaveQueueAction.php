<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Enums\BuyRequestState;
use App\Exceptions\DomainApiException;
use App\Models\BuyRequest;
use App\Models\Customer;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The buyer leaves the line (spec 011 US2, FR-010; Part 2 §4 withdraw):
 * `queued → withdrawn_by_buyer`, the deposit back to their available balance,
 * the listing live again if the line is now empty — one transaction, listing
 * locked first. A suspended buyer may leave (verified gate).
 */
final class LeaveQueueAction
{
    use ReleasesRequests, RunsInQueue;

    public function handle(Customer $buyer, string $requestId, bool $notifyWhenFree): BuyRequest
    {
        // Their own request, under their own row isolation (404 for anyone else's).
        $own = BuyRequest::query()->where('buyer_id', $buyer->customer_id)->findOrFail($requestId);

        if ($own->state !== BuyRequestState::QUEUED) {
            throw DomainApiException::notInQueue();
        }

        return $this->inQueue(function () use ($buyer, $own, $notifyWhenFree) {
            try {
                // Only a live or reserved listing can be locked in this scope; anything
                // else means the line has already been answered.
                $listing = $this->lockListing($own->listing_id);
            } catch (ModelNotFoundException) {
                throw DomainApiException::notInQueue();
            }
            $request = BuyRequest::query()->whereKey($own->buy_request_id)->where('buyer_id', $buyer->customer_id)
                ->lockForUpdate()->firstOrFail();

            if ($request->state !== BuyRequestState::QUEUED) {
                throw DomainApiException::notInQueue();
            }

            $request = $this->releaseRequest($request, BuyRequestState::WITHDRAWN_BY_BUYER, $listing, $buyer, null, null, $notifyWhenFree);
            $this->syncListingAfterRelease($listing, $buyer, null);
            $this->flushAfterCommit();

            return $request;
        });
    }
}
