<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tunable number (spec 005, schema §3). Read through
 * App\Support\Pricing\Settings; changed only by ChangeSettingAction.
 */
class Setting extends Model
{
    protected $table = 'setting';

    protected $primaryKey = 'setting_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value_numeric' => 'decimal:4',
            'value_bool' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by', 'staff_id');
    }
}
