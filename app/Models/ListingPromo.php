<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The code recorded on a listing, one per sale (spec 023 research R6). Changes only while no buyer is in line (DH027).
 *
 * @property string $listing_id
 * @property string|null $promo_code
 * @property string|null $referral_inviter_id
 * @property string $credit_id
 * @property CarbonImmutable $applied_at
 */
class ListingPromo extends Model
{
    protected $table = 'listing_promo';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applied_at' => 'immutable_datetime',
        ];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(CommissionCredit::class, 'credit_id', 'credit_id');
    }
}
