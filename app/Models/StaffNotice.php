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
 * `staff_notice` channel or, for work notices, by App\Support\Notices\WorkNotices (spec 026).
 * Staff-facing English; `params` never carries a secret. `kind` is an App\Enums\StaffNoticeKind
 * value. A founder alert never changes except `read_at`; an unread work notice is grouped by
 * `group_key` and counts up (`item_count`, text, link, time) until it is read (spec 026 R2).
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
        'group_key',
        'item_count',
        'created_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'item_count' => 'integer',
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
