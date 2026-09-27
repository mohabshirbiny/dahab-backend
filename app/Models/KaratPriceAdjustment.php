<?php

namespace App\Models;

use App\Enums\AdjustmentKind;
use App\Enums\AdjustmentSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A karat's buy-side (sellers get) or sell-side (buyers pay) adjustment:
 * fixed EGP per gram, or a percentage (spec 005). Keyed by (karat, side).
 */
class KaratPriceAdjustment extends Model
{
    protected $table = 'karat_price_adjustment';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'karat_code' => 'integer',
            'side' => AdjustmentSide::class,
            'kind' => AdjustmentKind::class,
            'value' => 'decimal:4',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Both sides at fixed 0, for a karat created after the seed (research R3). Idempotent. */
    public static function seedZero(int $karatCode): void
    {
        foreach (AdjustmentSide::cases() as $side) {
            self::query()->insertOrIgnore([
                'karat_code' => $karatCode,
                'side' => $side->value,
                'kind' => AdjustmentKind::FIXED->value,
                'value' => '0',
            ]);
        }
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by', 'staff_id');
    }
}
