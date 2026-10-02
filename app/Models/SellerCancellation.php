<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One cancellation counted against a seller (spec 012 FR-005–FR-007; schema
 * §9): an explicit cancel, or a missed reach-branch deadline (`by_sweep`).
 *
 * @property string $cancellation_id
 * @property string $order_id
 * @property string $seller_id
 * @property bool $by_sweep
 * @property CarbonImmutable $cancelled_at
 */
class SellerCancellation extends Model
{
    use BelongsToOrder;

    protected $table = 'seller_cancellation';

    protected $primaryKey = 'cancellation_id';

    protected function casts(): array
    {
        return [
            'by_sweep' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
