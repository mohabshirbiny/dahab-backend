<?php

namespace App\Models;

use App\Enums\CompensationReason;
use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compensation paid to a party's wallet inside a dispute resolution (spec 014
 * FR-015, research R8): one balanced `compensation` ledger entry, the payer,
 * the reason. Readable by the customer paid and by staff. Append-only.
 *
 * @property string $compensation_id
 * @property string $dispute_id
 * @property string $order_id
 * @property string $customer_id
 * @property string $party
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
}
