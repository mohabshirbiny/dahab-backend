<?php

namespace App\Models;

use App\Enums\ListingMediaKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo, the video, the original invoice or the stone certificate of a
 * listing (spec 010, table `listing_media`). `storage_ref` names a
 * chunk-encrypted object on the private disk and never leaves the Backend.
 * Only the invoice is private.
 *
 * @property string $media_id
 * @property string $listing_id
 * @property ListingMediaKind $kind
 * @property string $storage_ref
 * @property bool $is_private
 * @property string $mime
 * @property int $position
 */
class ListingMedia extends Model
{
    use HasUuids;

    protected $table = 'listing_media';

    protected $primaryKey = 'media_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = ['media_id'];

    protected $hidden = ['storage_ref'];

    protected function casts(): array
    {
        return [
            'kind' => ListingMediaKind::class,
            'is_private' => 'boolean',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }
}
