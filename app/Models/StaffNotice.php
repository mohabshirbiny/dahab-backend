<?php

namespace App\Models;

use Database\Factories\StaffNoticeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Dashboard notice to one staff member (spec 021 research R11/R12), written by the
 * `staff_notice` channel. Staff-facing English; `params` never carries a secret. Only `read_at`
 * ever changes. `kind` is an App\Enums\StaffNoticeKind value (spec 024 adds its own).
 */
class StaffNotice extends Model
{
    /** @use HasFactory<StaffNoticeFactory> */
    use HasFactory, HasUuids;

    protected $table = 'staff_notice';

    protected $primaryKey = 'notice_id';

    public $timestamps = false;

    protected $fillable = [
        'staff_id',
        'kind',
        'severity',
        'title',
        'body',
        'params',
        'link',
        'audit_id',
        'dedupe_key',
        'created_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'created_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['notice_id'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
