<?php

namespace App\Models;

use App\Models\Casts\PostgresTextArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One text of the customer app (spec 019, data-model §1): a readable, stable key with the
 * code's own English and Arabic as its default. Seeded by `app-texts:sync` from the app's
 * manifest; keys are never deleted, a key that left the source is `retired`.
 *
 * @property string $text_key
 * @property string $kind app | faq | notification
 * @property string $area
 * @property string $description
 * @property string $default_en
 * @property string $default_ar
 * @property list<string> $placeholders
 * @property list<string> $required_placeholders
 * @property bool $read_only
 * @property int $max_length
 */
class AppTextKey extends Model
{
    public const KIND_APP = 'app';

    public const KIND_FAQ = 'faq';

    public const KIND_NOTIFICATION = 'notification';

    protected $table = 'app_text_key';

    protected $primaryKey = 'text_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'placeholders' => PostgresTextArray::class,
            'required_placeholders' => PostgresTextArray::class,
            'read_only' => 'boolean',
            'inbox' => 'boolean',
            'max_length' => 'integer',
            'position' => 'integer',
            'default_changed_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AppTextVersion::class, 'text_key', 'text_key')->orderByDesc('version');
    }

    /** The live version: an override, or a default marker that ended one. */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(AppTextVersion::class, 'text_key', 'text_key')->where('state', AppTextVersion::PUBLISHED);
    }

    public function draftVersion(): HasOne
    {
        return $this->hasOne(AppTextVersion::class, 'text_key', 'text_key')->where('state', AppTextVersion::DRAFT);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * What customers see for this key right now: the published override, else the code's
     * default (a default marker ends the override). Needs `publishedVersion` loaded or loadable.
     *
     * @return array{en: string, ar: string, version: int|null, overridden: bool}
     */
    public function effective(): array
    {
        $live = $this->publishedVersion;
        if ($live !== null && ! $live->is_default_marker) {
            return ['en' => $live->text_en, 'ar' => $live->text_ar, 'version' => $live->version, 'overridden' => true];
        }

        return ['en' => $this->default_en, 'ar' => $this->default_ar, 'version' => $live?->version, 'overridden' => false];
    }

    /** Has the code's default changed since the live override was published (spec US1 scenario 10)? */
    public function defaultChangedSinceOverride(): bool
    {
        $live = $this->publishedVersion;

        return $live !== null && ! $live->is_default_marker
            && $this->default_changed_at !== null && $live->published_at !== null
            && $this->default_changed_at->greaterThan($live->published_at);
    }
}
