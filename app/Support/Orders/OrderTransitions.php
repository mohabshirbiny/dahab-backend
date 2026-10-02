<?php

namespace App\Support\Orders;

use App\Enums\OrderState;
use Illuminate\Support\Facades\DB;

/**
 * The allowed order moves, read from `order_transition` (spec 012 FR-002).
 * `trg_order_transition` enforces the same table in the database (DH006).
 */
final class OrderTransitions
{
    /** @var array<string, true>|null "from>to" keys */
    private ?array $allowed = null;

    public function allows(OrderState $from, OrderState $to): bool
    {
        $this->allowed ??= DB::table('order_transition')->get(['from_state', 'to_state'])
            ->mapWithKeys(fn ($row) => [$row->from_state.'>'.$row->to_state => true])
            ->all();

        return isset($this->allowed[$from->value.'>'.$to->value]);
    }
}
