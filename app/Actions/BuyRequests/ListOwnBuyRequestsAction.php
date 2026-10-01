<?php

namespace App\Actions\BuyRequests;

use App\Enums\BuyRequestState;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Support\BuyRequests\BuyRequestCursor;
use App\Support\BuyRequests\PlaceInLine;
use App\Support\DatabaseActor;
use Illuminate\Support\Collection;

/**
 * A buyer's own requests, newest first (spec 011 US2, FR-009).
 *
 * Analysis C1: the request rows (and their orders) are loaded in the plain
 * customer scope, so `buy_request_isolation` alone decides which rows exist.
 * Only then, in the `queue` scope, the pieces behind those rows are loaded
 * (a buyer cannot otherwise read a listing that is not on the market any
 * more) and the places in line are counted — numbers, never other buyers'
 * rows. The explicit buyer filter is there for the index, not for safety.
 */
final class ListOwnBuyRequestsAction
{
    /** @return array{rows: Collection<int, BuyRequest>, next_cursor: string|null} */
    public function handle(string $buyerId, ?BuyRequestState $state, ?string $listingId, ?BuyRequestCursor $cursor, int $perPage): array
    {
        $key = "to_char(buy_request.requested_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')";

        $query = BuyRequest::query()->where('buy_request.buyer_id', $buyerId)
            ->select('buy_request.*')->selectRaw("{$key} AS sort_key")
            ->with('order.branch')
            ->orderByDesc('buy_request.requested_at')->orderByDesc('buy_request.buy_request_id');

        if ($state !== null) {
            $query->where('buy_request.state', $state->value);
        }
        if ($listingId !== null) {
            $query->where('buy_request.listing_id', $listingId);
        }
        if ($cursor !== null) {
            $query->whereRaw('(buy_request.requested_at, buy_request.buy_request_id) < (?::timestamptz, ?::uuid)', [$cursor->at, $cursor->id]);
        }

        $rows = $query->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        $this->decorate($rows);

        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null ? (new BuyRequestCursor($last->getAttribute('sort_key'), $last->buy_request_id))->encode() : null,
        ];
    }

    /** One of the buyer's own requests; 404 for anyone else's (row isolation). */
    public function show(string $buyerId, string $requestId): BuyRequest
    {
        $request = BuyRequest::query()->where('buyer_id', $buyerId)->with('order.branch')->findOrFail($requestId);

        $this->decorate(collect([$request]));

        return $request;
    }

    /**
     * Attach the piece summary and the place in line to rows the buyer already holds.
     *
     * @param  Collection<int, BuyRequest>  $rows
     */
    public function decorate(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $listingIds = $rows->pluck('listing_id')->unique()->values()->all();
        $listings = DatabaseActor::queue(fn () => Listing::query()->whereIn('listing_id', $listingIds)
            ->with(['pieceType', 'photos'])->get()->keyBy('listing_id'));

        $ahead = PlaceInLine::aheadOf($rows->where('state', BuyRequestState::QUEUED)->pluck('buy_request_id')->values()->all());

        foreach ($rows as $row) {
            $row->setRelation('listing', $listings->get($row->listing_id));
            $row->setAttribute('ahead_count', $ahead[$row->buy_request_id] ?? null);
        }
    }
}
