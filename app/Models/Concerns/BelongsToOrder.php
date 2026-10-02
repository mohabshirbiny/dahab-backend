<?php

namespace App\Models\Concerns;

use App\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared shape of the spec 012 tables that hang off one order: a UUID key
 * chosen by the database, no Laravel timestamps, and the order relation.
 * Each table's row-level security follows its order (research R2).
 */
trait BelongsToOrder
{
    use HasUuids;

    public function initializeBelongsToOrder(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
        $this->timestamps = false;
        $this->guarded = [];
        // Keep the offset (and microseconds): a deadline must never be read back in another zone.
        $this->dateFormat = 'Y-m-d H:i:s.uO';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
