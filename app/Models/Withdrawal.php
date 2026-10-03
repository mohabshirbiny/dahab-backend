<?php

namespace App\Models;

use App\Enums\WithdrawalHoldReason;
use App\Enums\WithdrawalRejectReason;
use App\Enums\WithdrawalState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to take money out of the wallet to the customer's own bank
 * account (spec 013, table `withdrawal`). Its money moves only through the
 * money service: one hold, then one release or one return (the hold / release
 * / return txn ids, checked at commit by trg_withdrawal_money). Moves pass the
 * guard (SQLSTATE DH007); rows are never deleted.
 *
 * @property string $withdrawal_id
 * @property int $withdrawal_no
 * @property string $customer_id
 * @property string $payout_account_id
 * @property string $amount
 * @property WithdrawalState $state
 * @property string $hold_txn_id
 * @property string|null $release_txn_id
 * @property string|null $return_txn_id
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $held_at
 * @property WithdrawalHoldReason|null $hold_reason
 * @property string|null $hold_message
 * @property WithdrawalRejectReason|null $rejection_reason
 * @property bool $cancelled_by_change
 * @property CarbonImmutable $requested_at
 */
class Withdrawal extends Model
{
    // No factory: a withdrawal always has a hold in the ledger, so tests reach it through the Actions.
    use HasUuids;

    protected $table = 'withdrawal';

    protected $primaryKey = 'withdrawal_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['withdrawal_id', 'withdrawal_no'];

    protected function casts(): array
    {
        return [
            'state' => WithdrawalState::class,
            'hold_reason' => WithdrawalHoldReason::class,
            'rejection_reason' => WithdrawalRejectReason::class,
            'withdrawal_no' => 'integer',
            'cancelled_by_change' => 'boolean',
            'value_date' => 'immutable_date',
            'review_started_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** The number customers and staff quote: `WD-{withdrawal_no}`. */
    public function number(): string
    {
        return 'WD-'.$this->withdrawal_no;
    }

    /** "On hold" is under review with a hold recorded (analysis A1); the hold record stays after a reject or cancel. */
    public function isOnHold(): bool
    {
        return $this->state === WithdrawalState::UNDER_REVIEW && $this->held_at !== null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id', 'payout_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewed_by', 'staff_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'held_by', 'staff_id');
    }
}
