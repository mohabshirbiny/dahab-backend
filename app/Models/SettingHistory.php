<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of one setting change, with its reason (spec 005). */
class SettingHistory extends Model
{
    protected $table = 'setting_history';

    protected $primaryKey = 'setting_history_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_numeric' => 'decimal:4',
            'new_numeric' => 'decimal:4',
            'old_bool' => 'boolean',
            'new_bool' => 'boolean',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'changed_by', 'staff_id');
    }
}
