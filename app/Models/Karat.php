<?php

namespace App\Models;

use Database\Factories\KaratFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A karat sellers can choose (schema §2, spec 004). Code and purity are
 * fixed once created — prices and listings depend on them; only the
 * enabled flag and display order change.
 *
 * @property int $karat_code
 * @property string $purity_ratio
 * @property bool $is_enabled
 * @property int $sort_order
 */
class Karat extends Model
{
    /** @use HasFactory<KaratFactory> */
    use HasFactory;

    protected $table = 'karat';

    protected $primaryKey = 'karat_code';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = ['karat_code', 'purity_ratio', 'is_enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'karat_code' => 'integer',
            'purity_ratio' => 'decimal:5',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Its buy-side and sell-side price adjustments (spec 005). */
    public function adjustments(): HasMany
    {
        return $this->hasMany(KaratPriceAdjustment::class, 'karat_code', 'karat_code');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('karat_code');
    }
}
