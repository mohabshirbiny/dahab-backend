<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private photo attached to a dispute (spec 014 R13): encrypted on the
 * private disk, never served to a customer, opened by staff with an audit
 * row. Append-only.
 *
 * @property string $photo_id
 * @property string $dispute_id
 * @property string $storage_ref
 * @property string $mime
 * @property int $position
 */
class DisputePhoto extends Model
{
    use HasUuids;

    protected $table = 'dispute_photo';

    protected $primaryKey = 'photo_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['storage_ref'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class, 'dispute_id', 'dispute_id');
    }
}
