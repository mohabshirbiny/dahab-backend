<?php

namespace App\Models;

use App\Models\Casts\PostgresTextArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One *Publish changes* (spec 019): the numbered bundle the customer app downloads. Never
 * changes once written (database guard DH017).
 *
 * @property int $bundle_version
 * @property int $key_count
 * @property list<string> $kinds
 */
class AppTextBundle extends Model
{
    protected $table = 'app_text_bundle';

    protected $primaryKey = 'bundle_version';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'key_count' => 'integer',
            'kinds' => PostgresTextArray::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'published_by', 'staff_id');
    }
}
