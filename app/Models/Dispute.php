<?php

namespace App\Models;

use App\Enums\DisputeOutcome;
use App\Enums\DisputeReason;
use App\Enums\DisputeState;
use App\Enums\OrderState;
use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A party's complaint on one order (spec 014; schema 05 §16). Opening it froze
 * the order into `disputed`; it ends with a reply, resuming the sale or —
 * before payment — ending it. Readable only by its raiser and by staff
 * (row-level security, analysis C2). Moves along `dispute_transition` (DH009).
 *
 * @property string $dispute_id
 * @property int $dispute_no
 * @property string $dispute_ref
 * @property string $order_id
 * @property string $raised_by
 * @property string $raised_as
 * @property DisputeReason $reason
 * @property string $detail
 * @property DisputeState $state
 * @property string|null $assigned_to
 * @property CarbonImmutable|null $passed_on_at
 * @property OrderState $frozen_from
 * @property CarbonImmutable $frozen_at
 * @property DisputeOutcome|null $outcome
 * @property string|null $resolution_reply
 * @property string|null $resolved_by
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $release_txn_id
 */
class Dispute extends Model
{
    use BelongsToOrder;

    protected $table = 'dispute';

    protected $primaryKey = 'dispute_id';

    protected function casts(): array
    {
        return [
            'dispute_no' => 'integer',
            'reason' => DisputeReason::class,
            'state' => DisputeState::class,
            'frozen_from' => OrderState::class,
            'outcome' => DisputeOutcome::class,
            'passed_on_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'raised_by', 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_to', 'staff_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'resolved_by', 'staff_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DisputePhoto::class, 'dispute_id', 'dispute_id')->orderBy('position');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(DisputeChange::class, 'dispute_id', 'dispute_id')->orderBy('change_id');
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(Compensation::class, 'dispute_id', 'dispute_id')->orderBy('paid_at');
    }

    public function isResolved(): bool
    {
        return $this->state === DisputeState::RESOLVED;
    }
}
