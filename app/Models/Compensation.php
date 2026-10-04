<?php

namespace App\Models;

use App\Enums\CompensationReason;
use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compensation paid to a customer's wallet (spec 014 FR-015, research R8):
 * one balanced `compensation` ledger entry, the payer, the reason. Inside a
 * dispute resolution it names the dispute, the order and the party; since
 * spec 015 it may also be paid outside a dispute, with or without an order
 * (then no party). Readable by the customer paid and by staff. Append-only.
 *
 * @property string $compensation_id
 * @property string|null $dispute_id
 * @property string|null $order_id
 * @property string $customer_id
 * @property string|null $party
 * @property string $amount
 * @property CompensationReason $reason
 * @property string $note
 * @property string $paid_by
 * @property string $ledger_txn_id
 * @property CarbonImmutable $paid_at
 */
class Compensation extends Model
{
    use BelongsToOrder;

    protected $table = 'compensation';

    protected $primaryKey = 'compensation_id';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'reason' => CompensationReason::class,
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'paid_by', 'staff_id');
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class, 'dispute_id', 'dispute_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
}
