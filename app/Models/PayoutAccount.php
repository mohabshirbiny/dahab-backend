<?php

namespace App\Models;

use App\Enums\PayoutAccountState;
use App\Enums\PayoutRefusalReason;
use Database\Factories\PayoutAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account a customer's money may leave to (spec 013, table
 * `payout_account`). Only in the customer's own name, checked by staff. The
 * details never change (guard DH008): a change is a new account. Never deleted.
 *
 * @property string $payout_account_id
 * @property string $customer_id
 * @property string $account_name
 * @property string $bank_name
 * @property string $account_number_or_iban
 * @property PayoutAccountState $state
 * @property bool $is_in_use
 * @property PayoutRefusalReason|null $refusal_reason
 */
class PayoutAccount extends Model
{
    /** @use HasFactory<PayoutAccountFactory> */
    use HasFactory, HasUuids;

    protected $table = 'payout_account';

    protected $primaryKey = 'payout_account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['payout_account_id'];

    protected function casts(): array
    {
        return [
            'state' => PayoutAccountState::class,
            'refusal_reason' => PayoutRefusalReason::class,
            'is_in_use' => 'boolean',
            'name_checked_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'removal_requested_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** `iban` or `account_number`, from the stored shape. */
    public function kind(): string
    {
        return str_starts_with($this->account_number_or_iban, 'EG') ? 'iban' : 'account_number';
    }

    /** "•••• 4417": all a customer-facing message or a masked staff view shows. */
    public function masked(): string
    {
        return '•••• '.substr($this->account_number_or_iban, -4);
    }

    /** "CIB •••• 4417". */
    public function shortLabel(): string
    {
        return $this->bank_name.' '.$this->masked();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'name_checked_by', 'staff_id');
    }
}
