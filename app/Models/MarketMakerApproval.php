<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Approval of one piece for market-maker purchase, with the numbers the approver saw
 * (schema §15, FR-062). One per piece, append-only.
 *
 * @property string $approval_id
 * @property string $listing_id
 * @property string $approved_by
 * @property string $asking_price
 * @property string|null $gold_value
 * @property string|null $rapaport_guide
 * @property CarbonImmutable $approved_at
 */
class MarketMakerApproval extends Model
{
    use HasUuids;

    protected $table = 'market_maker_approval';

    protected $primaryKey = 'approval_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by', 'staff_id');
    }
}
