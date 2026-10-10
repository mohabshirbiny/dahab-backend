<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a text (spec 019, data-model §1). A key has at most one draft and one
 * published version. A published or superseded row never changes except published →
 * superseded (database guard DH017), so the history of every customer-visible wording is kept.
 *
 * @property int $version_id
 * @property string $text_key
 * @property int $version
 * @property string $state draft | published | superseded
 * @property bool $is_default_marker ends the override: the app shows its own default again
 * @property string $text_en
 * @property string $text_ar
 * @property bool $is_hidden FAQ only
 * @property int $draft_version optimistic check while a draft
 */
class AppTextVersion extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const SUPERSEDED = 'superseded';

    protected $table = 'app_text_version';

    protected $primaryKey = 'version_id';

    public $timestamps = false;

    protected $guarded = ['version_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_default_marker' => 'boolean',
            'is_hidden' => 'boolean',
            'draft_version' => 'integer',
            'bundle_version' => 'integer',
            'edited_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(AppTextKey::class, 'text_key', 'text_key');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'edited_by', 'staff_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'published_by', 'staff_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('state', self::DRAFT);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('state', self::PUBLISHED);
    }
}
