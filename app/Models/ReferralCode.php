<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer invitation code (spec 023 research R22); created on the first read of the Invite screen.
 *
 * @property string $customer_id
 * @property string $code
 * @property int $shares_count
 * @property CarbonImmutable $created_at
 */
class ReferralCode extends Model
{
    protected $table = 'referral_code';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shares_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
