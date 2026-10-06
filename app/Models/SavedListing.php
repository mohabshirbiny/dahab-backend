<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece a customer saved (spec 017 FR-040). Never locks a price. The
 * summary (piece type, karat, stated weight) is taken at save time, so a
 * piece that left the market still reads as something; no media.
 *
 * @property string $customer_id
 * @property string $listing_id
 * @property array<string, mixed> $summary
 * @property CarbonImmutable $saved_at
 */
class SavedListing extends Model
{
    protected $table = 'saved_listing';

    public $incrementing = false;

    protected $primaryKey = 'listing_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'saved_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }
}
