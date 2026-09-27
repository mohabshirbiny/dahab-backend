<?php

namespace App\Models;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gold price that took effect: the 24K bid and ask per gram (spec 005).
 * Append-only — the database refuses updates and deletes.
 *
 * @property string $bid_24k
 * @property string $ask_24k
 */
class GoldPrice extends Model
{
    protected $table = 'gold_price';

    protected $primaryKey = 'gold_price_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => PriceSource::class,
            'bid_24k' => 'decimal:4',
            'ask_24k' => 'decimal:4',
            'effective_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Newest first: the first row is the current price. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('effective_at')->orderByDesc('gold_price_id');
    }

    public static function current(): ?self
    {
        return self::query()->latestFirst()->first();
    }

    public function manual(): BelongsTo
    {
        return $this->belongsTo(ManualGoldPrice::class, 'manual_gold_price_id', 'manual_gold_price_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recorded_by', 'staff_id');
    }
}
