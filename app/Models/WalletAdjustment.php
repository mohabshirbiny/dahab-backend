<?php

namespace App\Models;

use App\Enums\AdjustmentDirection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff correction of one customer's available balance (spec 015 FR-006):
 * one `reversal` entry with no reversed entry, against external equity. The
 * customer reads their own rows (forced RLS). Append-only.
 *
 * @property string $adjustment_id
 * @property string $customer_id
 * @property AdjustmentDirection $direction
 * @property string $amount
 * @property string $reason
 * @property string $customer_status
 * @property string $adjusted_by
 * @property string $ledger_txn_id
 * @property CarbonImmutable $adjusted_at
 */
class WalletAdjustment extends Model
{
    use HasUuids;

    protected $table = 'wallet_adjustment';

    protected $primaryKey = 'adjustment_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'direction' => AdjustmentDirection::class,
            'amount' => 'decimal:4',
            'adjusted_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'adjusted_by', 'staff_id');
    }
}
