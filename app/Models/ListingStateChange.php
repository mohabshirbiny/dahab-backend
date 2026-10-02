<?php

namespace App\Models;

use App\Enums\ListingState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a listing's history (spec 010 FR-017, table
 * `listing_state_change`): who moved it, from what to what, and the message
 * or reason. Append-only; `from_state` null is the creation.
 *
 * @property int $change_id
 * @property string $listing_id
 * @property ListingState|null $from_state
 * @property ListingState $to_state
 * @property string|null $actor_customer_id
 * @property string|null $actor_staff_id
 * @property string|null $note
 * @property CarbonImmutable $changed_at
 */
class ListingStateChange extends Model
{
    /** Notes written by the suspension hold (spec 010 FR-037). */
    public const NOTE_SUSPENDED = 'account_suspended';

    public const NOTE_REINSTATED = 'account_reinstated';

    /** Notes written by the buy-request module (spec 011). */
    public const NOTE_BUY_REQUEST_QUEUED = 'buy_request_queued';

    public const NOTE_QUEUE_EMPTIED = 'queue_emptied';

    public const NOTE_BUY_REQUEST_ACCEPTED = 'buy_request_accepted';

    /** Notes written by the orders module (spec 012). */
    public const NOTE_PIECE_RECEIVED = 'piece_received';

    public const NOTE_SELLER_CANCELLED = 'seller_cancelled';

    public const NOTE_DEADLINE_MISSED = 'deadline_missed';

    public const NOTE_INSPECTED = 'inspected';

    public const NOTE_INSPECTION_CANCELLED = 'inspection_cancelled';

    public const NOTE_ADJUSTMENT_DECLINED = 'adjustment_declined';

    public const NOTE_BALANCE_PAID = 'balance_paid';

    public const NOTE_BUYER_DID_NOT_PAY = 'buyer_did_not_pay';

    public const NOTE_RETURN_COLLECTED = 'return_collected';

    public const NOTE_RETURN_WINDOW_PASSED = 'return_window_passed';

    public const NOTE_COLLECTION_WINDOW_PASSED = 'collection_window_passed';

    public const NOTE_COLLECTED = 'collected';

    public const NOTE_COLLECT_DEADLINE_EXTENDED = 'collect_deadline_extended';

    protected $table = 'listing_state_change';

    protected $primaryKey = 'change_id';

    public $timestamps = false;

    protected $guarded = ['change_id', 'txid'];

    protected function casts(): array
    {
        return [
            'from_state' => ListingState::class,
            'to_state' => ListingState::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id', 'staff_id');
    }
}
