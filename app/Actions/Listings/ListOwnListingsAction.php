<?php

namespace App\Actions\Listings;

use App\Enums\ListingState;
use App\Models\Listing;
use App\Support\Listings\ListingCursor;
use App\Support\Listings\ListingPage;
use Illuminate\Support\Collection;

/**
 * The seller's own listings, newest first (spec 010 US4, FR-018). The
 * customer scope's row-level security returns only their rows (FR-019); the
 * explicit seller filter is there for the index, not for safety.
 */
final class ListOwnListingsAction
{
    public const RELATIONS = ['pieceType', 'media', 'branches', 'changes', 'order.branch', 'relistedFrom:order_id,order_ref'];

    /** @return array{rows: Collection<int, Listing>, next_cursor: string|null} */
    public function handle(string $customerId, ?ListingState $state, ?ListingCursor $cursor, int $perPage): array
    {
        $query = Listing::query()->where('listing.seller_id', $customerId)->with(self::RELATIONS);

        if ($state !== null) {
            $query->where('listing.state', $state->value);
        }

        return ListingPage::byTime($query, 'created_at', true, $cursor, $perPage);
    }

    /** One of the seller's own listings; 404 for anyone else's. */
    public function show(string $customerId, string $listingId): Listing
    {
        return Listing::query()->where('seller_id', $customerId)->with(self::RELATIONS)->findOrFail($listingId);
    }
}
