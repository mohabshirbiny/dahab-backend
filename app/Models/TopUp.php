<?php

namespace App\Models;

use App\Enums\TopUpMethod;
use App\Enums\TopUpOrigin;
use App\Enums\TopUpRejectReason;
use App\Enums\TopUpStatus;
use Database\Factories\TopUpFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transfer notice or a hand credit (spec 009, table `topup`). Amounts are
 * NUMERIC(18,4) strings; never floats. Final states are frozen by the
 * database (trg_topup_guard) and rows are never deleted.
 *
 * @property string $topup_id
 * @property int $topup_no
 * @property string $customer_id
 * @property TopUpOrigin $origin
 * @property TopUpMethod $method
 * @property string $reference
 * @property string|null $claimed_amount
 * @property string|null $notice_fee_percent
 * @property TopUpStatus $status
 * @property TopUpRejectReason|null $reject_reason
 * @property string|null $credited_amount
 * @property string|null $ledger_txn_id
 */
class TopUp extends Model
{
    /** @use HasFactory<TopUpFactory> */
    use HasFactory, HasUuids;

    protected $table = 'topup';

    protected $primaryKey = 'topup_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $guarded = ['topup_id', 'topup_no'];

    protected function casts(): array
    {
        return [
            'origin' => TopUpOrigin::class,
            'method' => TopUpMethod::class,
            'status' => TopUpStatus::class,
            'reject_reason' => TopUpRejectReason::class,
            'topup_no' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The number customers and staff quote: `TOP-{topup_no}`. */
    public function number(): string
    {
        return 'TOP-'.$this->topup_no;
    }

    /**
     * What should reach Dahab after the provider's fee, for display only
     * (spec 009, post-implementation decision 2026-09-30): the claim minus
     * the fee snapshot taken when the notice was filed (`notice_fee_percent`),
     * rounded half-up to piastres. Null when there is no claim, no fee, or
     * the notice is closed. A later change to the account's fee never moves
     * it. Staff still credit what actually arrived; the fee is a display
     * setting, not a Dahab rule.
     */
    public function expectedAmount(): ?string
    {
        $percent = $this->notice_fee_percent;

        if ($this->claimed_amount === null || $percent === null || bccomp((string) $percent, '0', 3) <= 0
            || in_array($this->status, [TopUpStatus::CREDITED, TopUpStatus::REJECTED, TopUpStatus::CANCELLED], true)) {
            return null;
        }

        // fee = claim × percent / 100, rounded half-up to 2 places (amounts are positive).
        $fee = bcdiv(bcadd(bcmul((string) $this->claimed_amount, (string) $percent, 6), '0.5', 6), '100', 2);

        return bcsub((string) $this->claimed_amount, $fee, 4);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function noticeAccount(): BelongsTo
    {
        return $this->belongsTo(ReceivingAccount::class, 'notice_account_id', 'receiving_account_id');
    }

    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(ReceivingAccount::class, 'receiving_account_id', 'receiving_account_id');
    }

    public function creditedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'credited_by', 'staff_id');
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'held_by', 'staff_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rejected_by', 'staff_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_txn_id', 'ledger_txn_id');
    }
}
