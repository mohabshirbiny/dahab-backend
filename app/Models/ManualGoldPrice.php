<?php

namespace App\Models;

use App\Enums\ManualPriceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual gold price request (spec 005). Only its status moves forward,
 * from pending; the database refuses anything else.
 */
class ManualGoldPrice extends Model
{
    protected $table = 'manual_gold_price';

    protected $primaryKey = 'manual_gold_price_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bid_24k' => 'decimal:4',
            'ask_24k' => 'decimal:4',
            'deviation_pct' => 'decimal:4',
            'requires_confirmation' => 'boolean',
            'status' => ManualPriceStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Pending, and still within its confirmation window (research R2: lapsing is computed on read). */
    public function isConfirmable(): bool
    {
        return $this->status === ManualPriceStatus::PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The status as staff should see it: a pending request past its window reads as lapsed. */
    public function displayStatus(): ManualPriceStatus
    {
        return $this->status === ManualPriceStatus::PENDING && ! $this->isConfirmable()
            ? ManualPriceStatus::LAPSED
            : $this->status;
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'entered_by', 'staff_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'confirmed_by', 'staff_id');
    }
}
