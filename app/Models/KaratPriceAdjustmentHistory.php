<?php

namespace App\Models;

use App\Enums\AdjustmentKind;
use App\Enums\AdjustmentSide;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of one adjustment change, with its reason (spec 005). */
class KaratPriceAdjustmentHistory extends Model
{
    protected $table = 'karat_price_adjustment_history';

    protected $primaryKey = 'history_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'karat_code' => 'integer',
            'side' => AdjustmentSide::class,
            'old_kind' => AdjustmentKind::class,
            'new_kind' => AdjustmentKind::class,
            'old_value' => 'decimal:4',
            'new_value' => 'decimal:4',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'changed_by', 'staff_id');
    }
}
