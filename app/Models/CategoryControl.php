<?php

namespace App\Models;

use App\Enums\ControlLevel;
use App\Enums\PieceCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operating control on a category (schema §14, spec 019 US7). Written once with who set
 * it and why, cleared once with who and when; never edited or deleted (guard DH019).
 *
 * @property string $control_id
 * @property PieceCategory $category
 * @property ControlLevel $level
 * @property bool $is_active
 * @property string|null $message_en
 * @property string|null $message_ar
 * @property string $reason
 * @property string $set_by
 * @property CarbonImmutable $set_at
 * @property string|null $cleared_by
 * @property CarbonImmutable|null $cleared_at
 */
class CategoryControl extends Model
{
    use HasUuids;

    protected $table = 'category_control';

    protected $primaryKey = 'control_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => PieceCategory::class,
            'level' => ControlLevel::class,
            'is_active' => 'boolean',
            'set_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'set_by', 'staff_id');
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'cleared_by', 'staff_id');
    }

    /** The message customers see, in one language; falls back to the other when one is empty. */
    public function messageFor(bool $arabic): string
    {
        $primary = $arabic ? $this->message_ar : $this->message_en;
        $other = $arabic ? $this->message_en : $this->message_ar;

        return (string) (filled($primary) ? $primary : $other);
    }
}
