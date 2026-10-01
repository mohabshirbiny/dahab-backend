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
