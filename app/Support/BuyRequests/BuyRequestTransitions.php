<?php

namespace App\Support\BuyRequests;

use App\Enums\BuyRequestState;
use Illuminate\Support\Facades\DB;

/**
 * The allowed request moves, read from `buy_request_transition` (spec 011
 * FR-021). `trg_buy_request_guard` enforces the same table in the database.
 */
final class BuyRequestTransitions
{
    /** @var array<string, true>|null "from>to" keys */
    private ?array $allowed = null;

    public function allows(BuyRequestState $from, BuyRequestState $to): bool
    {
        $this->allowed ??= DB::table('buy_request_transition')->get(['from_state', 'to_state'])
            ->mapWithKeys(fn ($row) => [$row->from_state.'>'.$row->to_state => true])
            ->all();

        return isset($this->allowed[$from->value.'>'.$to->value]);
    }
}
