<?php

namespace App\Models;

use App\Enums\OrderState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an order's history (spec 012, table `order_state_change`): who
 * moved it, from what to what, and why. Append-only; `from_state` null is the
 * creation. trg_order_change_recorded refuses a move without its row.
 *
 * @property int $change_id
 * @property string $order_id
 * @property OrderState|null $from_state
 * @property OrderState $to_state
 * @property string|null $actor_customer_id
 * @property string|null $actor_staff_id
 * @property string|null $note
 * @property CarbonImmutable $changed_at
 */
class OrderStateChange extends Model
{
    public const NOTE_ACCEPTED = 'accepted';

    public const NOTE_DEADLINE_MISSED = 'deadline_missed';

    public const NOTE_NO_ANSWER = 'no_answer';

    public const NOTE_DID_NOT_PAY = 'did_not_pay';

    public const NOTE_CORRECTED = 'corrected_result';

    protected $table = 'order_state_change';

    protected $primaryKey = 'change_id';

    public $timestamps = false;

    protected $guarded = ['change_id', 'txid'];

    protected function casts(): array
    {
        return [
            'from_state' => OrderState::class,
            'to_state' => OrderState::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id', 'staff_id');
    }
}
