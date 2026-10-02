<?php

namespace App\Models;

use App\Enums\BuyRequestState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One buyer's place in one listing's line (spec 011, table `buy_request`).
 * Money is NUMERIC(18,4), read as strings. The state leaves `queued` once,
 * along `buy_request_transition`, and every deposit hold and release is a
 * ledger entry tied to the request (trg_buy_request_guard,
 * trg_buy_request_money). Change it only through App\Actions\BuyRequests.
 *
 * @property string $buy_request_id
 * @property string $listing_id
 * @property string $buyer_id
 * @property BuyRequestState $state
 * @property int $queue_position
 * @property string|null $locked_unit_rate
 * @property string $locked_total_price
 * @property string $deposit_amount
 * @property string $deposit_hold_txn_id
 * @property string $deposit_acceptance_id
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable $seller_reply_deadline
 * @property CarbonImmutable|null $resolved_at
 * @property bool $notify_when_free
 * @property CarbonImmutable|null $free_notified_at
 */
class BuyRequest extends Model
{
    use HasUuids;

    protected $table = 'buy_request';

    protected $primaryKey = 'buy_request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** Keep microseconds: requests are ordered by the moment they arrived. */
    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected $guarded = ['resolved_at'];

    protected function casts(): array
    {
        return [
            'state' => BuyRequestState::class,
            'queue_position' => 'integer',
            'notify_when_free' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'seller_reply_deadline' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'free_notified_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_id', 'customer_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'buy_request_id', 'buy_request_id');
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('buy_request.state', BuyRequestState::QUEUED->value);
    }
}
