<?php

namespace App\Models;

use App\Enums\ReferralState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A friend who joined with an inviter code (spec 023 research R22). joined -> rewarded | void.
 *
 * @property string $referral_id
 * @property string $inviter_id
 * @property string $friend_id
 * @property string $code
 * @property string|null $listing_id
 * @property string|null $device_fingerprint
 * @property string|null $id_key
 * @property ReferralState $state
 * @property CarbonImmutable $joined_at
 * @property string|null $reward_order_id
 * @property CarbonImmutable|null $rewarded_at
 * @property string|null $void_reason
 * @property string|null $friend_credit_id
 * @property string|null $inviter_credit_id
 */
class Referral extends Model
{
    use HasUuids;

    protected $table = 'referral';

    protected $primaryKey = 'referral_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => ReferralState::class,
            'joined_at' => 'immutable_datetime',
            'rewarded_at' => 'immutable_datetime',
        ];
    }
}
