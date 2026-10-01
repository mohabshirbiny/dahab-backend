<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Models\Staff;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;

/**
 * The seller did not reply in time (spec 011 US4, FR-018; Part 2 §11,
 * Part 3 §4.5): a queued request past its `seller_reply_deadline` is released
 * as `released_expired` and refunded, by the system actor, one request per
 * transaction; the others keep their places. Runs in the `system` scope
 * (ExpireBuyRequests command). A request answered meanwhile is skipped, so a
 * second run changes nothing. Deadlines are compared on the application clock
 * (the app and the database both run on Cairo time).
 */
final class ExpireBuyRequestAction
{
    use ReleasesRequests;

    /** @return list<string> ids of queued requests due now, oldest deadline first */
    public function due(int $limit = 1000): array
    {
        return BuyRequest::query()->where('state', BuyRequestState::QUEUED->value)
            ->where('seller_reply_deadline', '<=', now())
            ->orderBy('seller_reply_deadline')->limit($limit)->pluck('buy_request_id')->all();
    }

    /** @return bool whether this request was released now */
    public function handle(string $requestId): bool
    {
        $system = Staff::query()->findOrFail(SystemActor::id());

        return DB::transaction(function () use ($requestId, $system) {
            $listingId = BuyRequest::query()->whereKey($requestId)->value('listing_id');
            if ($listingId === null) {
                return false;
            }

            // Listing first, then the request: the same lock order as every queue operation.
            $listing = Listing::query()->whereKey($listingId)->lockForUpdate()->firstOrFail();
            $request = BuyRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($request->state !== BuyRequestState::QUEUED || $request->seller_reply_deadline->isFuture()) {
                return false;
            }

            $this->releaseRequest($request, BuyRequestState::RELEASED_EXPIRED, $listing, null, $system, BuyRequestEvent::EXPIRED);
            $this->syncListingAfterRelease($listing, null, $system);
            $this->flushAfterCommit();

            return true;
        });
    }
}
