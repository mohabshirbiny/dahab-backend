<?php

namespace App\Actions\Listings;

use App\Enums\ListingState;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Support\Listings\ListingCursor;
use App\Support\Listings\ListingPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff review queue (spec 010 US2, FR-027, research R14/R15): listings
 * of every seller in one state — waiting ones oldest first, the rest newest
 * first — with the counts the screen's chips show. Staff scope; gated by any
 * listing permission. Relations are eager-loaded (no N+1).
 */
final class ListListingsForReviewAction
{
    public const RELATIONS = ['pieceType', 'media', 'branches', 'seller'];

    /** @return array{rows: Collection<int, Listing>, next_cursor: string|null, counts: array<string, int>} */
    public function handle(ListingState $state, ?ListingCursor $cursor, int $perPage): array
    {
        $query = Listing::query()->where('listing.state', $state->value)->with(self::RELATIONS);

        // "Oldest first" for the waiting queue; everything else most recent first.
        $page = ListingPage::byTime($query, 'state_changed_at', $state !== ListingState::IN_REVIEW, $cursor, $perPage);

        return $page + ['counts' => $this->counts()];
    }

    /**
     * One listing with its history and the "sent back" counters of the
     * design's review panel.
     *
     * @return array{listing: Listing, counters: array{sent_back_count: int, seller_listings_sent_back: int, seller_listings_submitted: int, seller_listing_number: int}}
     */
    public function show(string $listingId): array
    {
        $listing = Listing::query()->with([...self::RELATIONS, 'changes.staff', 'karat', 'queuedRequests.buyer', 'order.branch', 'order.buyer', 'order.buyRequest'])->findOrFail($listingId);

        $sellerListings = Listing::query()->where('seller_id', $listing->seller_id);
        $sentBack = fn () => ListingStateChange::query()->where('to_state', ListingState::CHANGES_REQUESTED->value);

        return [
            'listing' => $listing,
            'counters' => [
                'sent_back_count' => $listing->changes->where('to_state', ListingState::CHANGES_REQUESTED)->count(),
                'seller_listings_sent_back' => (int) $sentBack()
                    ->whereIn('listing_id', (clone $sellerListings)->select('listing_id'))
                    ->distinct()->count('listing_id'),
                'seller_listings_submitted' => (int) ListingStateChange::query()
                    ->where('to_state', ListingState::IN_REVIEW->value)
                    ->whereIn('listing_id', (clone $sellerListings)->select('listing_id'))
                    ->distinct()->count('listing_id'),
                // Which of the seller's listings this is, by creation order ("3rd listing").
                'seller_listing_number' => (clone $sellerListings)
                    ->whereRaw('(created_at, listing_id) <= (?::timestamptz, ?::uuid)', [$listing->getRawOriginal('created_at'), $listing->listing_id])
                    ->count(),
            ],
        ];
    }

    /** @return array{in_review: int, changes_requested: int, approved_today: int, rejected: int, live: int, reserved: int, accepted: int} */
    private function counts(): array
    {
        $byState = DB::table('listing')->selectRaw('state, count(*) AS n')
            ->whereIn('state', [ListingState::IN_REVIEW->value, ListingState::CHANGES_REQUESTED->value, ListingState::REJECTED->value, ListingState::LIVE->value, ListingState::RESERVED->value, ListingState::ACCEPTED->value])
            ->groupBy('state')->pluck('n', 'state');

        return [
            'in_review' => (int) ($byState[ListingState::IN_REVIEW->value] ?? 0),
            'changes_requested' => (int) ($byState[ListingState::CHANGES_REQUESTED->value] ?? 0),
            // Approvals since midnight, Cairo time (the app's timezone).
            'approved_today' => (int) DB::table('listing_state_change')
                ->where('from_state', ListingState::IN_REVIEW->value)->where('to_state', ListingState::LIVE->value)
                ->where('changed_at', '>=', Carbon::now()->startOfDay())->count(),
            'rejected' => (int) ($byState[ListingState::REJECTED->value] ?? 0),
            'live' => (int) ($byState[ListingState::LIVE->value] ?? 0),
            // Spec 011: pieces with buyers in line, and pieces a seller accepted.
            'reserved' => (int) ($byState[ListingState::RESERVED->value] ?? 0),
            'accepted' => (int) ($byState[ListingState::ACCEPTED->value] ?? 0),
        ];
    }
}
