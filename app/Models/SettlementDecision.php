<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The buyer's answer to an adjusted price (spec 012 FR-013; schema §10). One
 * per inspection result. An unanswered adjustment declined by the sweep has
 * no row: the row means the buyer decided (research R15).
 *
 * @property string $decision_id
 * @property string $order_id
 * @property string $inspection_id
 * @property bool $buyer_accepted
 * @property string $old_price
 * @property string $new_price
 * @property CarbonImmutable $decided_at
 */
class SettlementDecision extends Model
{
    use BelongsToOrder;

    protected $table = 'settlement_decision';

    protected $primaryKey = 'decision_id';

    protected function casts(): array
    {
        return [
            'buyer_accepted' => 'boolean',
            'old_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
