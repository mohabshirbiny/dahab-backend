<?php

namespace App\Support\Listings;

use App\Enums\ListingState;
use Illuminate\Support\Facades\DB;

/**
 * The allowed listing moves, read from `listing_transition` (spec 010
 * FR-015). The table is the rule — a new move is a reviewed row, not a code
 * change — and `trg_listing_guard` enforces it again in the database.
 */
final class ListingTransitions
{
    /** @var array<string, true>|null "from>to" keys */
    private ?array $allowed = null;

    public function allows(ListingState $from, ListingState $to): bool
    {
        $this->allowed ??= DB::table('listing_transition')->get(['from_state', 'to_state'])
            ->mapWithKeys(fn ($row) => [$row->from_state.'>'.$row->to_state => true])
            ->all();

        return isset($this->allowed[$from->value.'>'.$to->value]);
    }
}
