<?php

namespace App\Models;

use App\Enums\OrderState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted buyer's purchase (schema §9, table `"order"`). Spec 011
 * creates it when the seller accepts the head of the queue and lets staff
 * cancel it (`cancelled_staff`); everything after acceptance belongs to the
 * orders module. Moves follow `order_transition` (trg_order_transition).
 *
 * @property string $order_id
 * @property string $order_ref
 * @property string $listing_id
 * @property string $buy_request_id
 * @property string $seller_id
 * @property string $buyer_id
 * @property OrderState $state
 * @property int $branch_id
 * @property string $accepted_by
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable $reach_branch_deadline
 * @property string $locked_total_price
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 */
class Order extends Model
{
    use HasUuids;

    protected $table = 'order';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** Keep microseconds: requests are ordered by the moment they arrived. */
    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'branch_id' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'reach_branch_deadline' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    public function buyRequest(): BelongsTo
    {
        return $this->belongsTo(BuyRequest::class, 'buy_request_id', 'buy_request_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_id', 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'seller_id', 'customer_id');
    }
}
