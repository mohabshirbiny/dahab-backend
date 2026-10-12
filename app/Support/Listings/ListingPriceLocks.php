<?php

namespace App\Support\Listings;

use App\Enums\ListingState;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Whether a live piece's price is fixed (spec 022 C3), answered by the
 * database's `listing_price_locked()` so the customer scope never reads the
 * approval rows. One query for a whole page of listings.
 */
final class ListingPriceLocks
{
    public const ATTRIBUTE = 'price_locked';

    /**
     * Stamp `price_locked` on every live listing of the page; the others never need it.
     *
     * @param  iterable<Listing>  $listings
     */
    public static function mark(iterable $listings): void
    {
        $live = [];
        foreach ($listings as $listing) {
            if ($listing->state === ListingState::LIVE) {
                $live[$listing->listing_id] = $listing;
            }
        }

        if ($live === []) {
            return;
        }

        $locked = self::lockedIds(array_keys($live));

        foreach ($live as $id => $listing) {
            $listing->setAttribute(self::ATTRIBUTE, isset($locked[$id]));
            // Not a column: never part of a later save().
            $listing->syncOriginalAttribute(self::ATTRIBUTE);
        }
    }

    /**
     * Which of these listings are locked, as a set (`id => index`).
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public static function lockedIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_flip(DB::table('listing')
            ->whereIn('listing_id', $ids)
            ->whereRaw('listing_price_locked(listing_id)')
            ->pluck('listing_id')->all());
    }

    public static function isLocked(Listing $listing): bool
    {
        if ($listing->getAttribute(self::ATTRIBUTE) === null) {
            self::mark([$listing]);
        }

        return (bool) $listing->getAttribute(self::ATTRIBUTE);
    }
}
