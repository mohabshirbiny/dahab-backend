<?php

namespace App\Actions\BuyRequests;

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\InboxLinkKind;
use App\Enums\ListingState;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\BuyRequestNotification;
use Illuminate\Support\Facades\DB;

/**
 * Tell the buyers who left a piece with "tell me if it is free again" that it
 * is (spec 011 FR-011, research R12): only while the listing is live with
 * nobody in line, at most once per leave (`free_notified_at`), and not a buyer
 * who is already back in line. Runs as the system actor (NotifyWhenFreeJob).
 */
final class NotifyWhenFreeAction
{
    /** @return int how many buyers were told */
    public function handle(string $listingId): int
    {
        return DB::transaction(function () use ($listingId) {
            $listing = Listing::query()->with('pieceType')->whereKey($listingId)->lockForUpdate()->first();

            if ($listing === null || $listing->state !== ListingState::LIVE || $listing->active_queue_count > 0) {
                return 0;
            }

            $waiting = BuyRequest::query()->where('listing_id', $listingId)
                ->where('state', BuyRequestState::WITHDRAWN_BY_BUYER->value)
                ->where('notify_when_free', true)->whereNull('free_notified_at')
                ->whereNotExists(fn ($q) => $q->from('buy_request as active')
                    ->whereColumn('active.listing_id', 'buy_request.listing_id')
                    ->whereColumn('active.buyer_id', 'buy_request.buyer_id')
                    ->whereIn('active.state', [BuyRequestState::QUEUED->value, BuyRequestState::ACCEPTED->value]))
                ->orderBy('queue_position')->lockForUpdate()->get();

            $told = [];
            foreach ($waiting as $request) {
                $request->free_notified_at = now();
                $request->save();

                // One message per buyer, even if they left this piece more than once.
                if (! isset($told[$request->buyer_id])) {
                    $told[$request->buyer_id] = true;
                    $notification = new BuyRequestNotification(BuyRequestEvent::FREE_AGAIN, $listing->title(), $listing->title(arabic: true));
                    $notification->linkTo(InboxLinkKind::LISTING, $listing->listing_id);
                    DB::afterCommit(fn () => Customer::query()->find($request->buyer_id)?->notifyNow($notification));
                }
            }

            return count($told);
        });
    }
}
