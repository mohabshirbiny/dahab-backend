<?php

namespace App\Models;

use App\Enums\CreditKind;
use App\Enums\CreditSource;
use App\Enums\CreditStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A promise of a lower commission on a later sale (spec 023 research R2): not wallet money. Counters and status move forward only, each with its event (guard DH024).
 *
 * @property string $credit_id
 * @property string $customer_id
 * @property CreditSource $source
 * @property CreditKind $kind
 * @property string|null $percent_off
 * @property string|null $amount_egp
 * @property string|null $amount_left_egp
 * @property int|null $uses_total
 * @property int|null $uses_left
 * @property string|null $listing_id
 * @property string|null $promo_code
 * @property string|null $referral_id
 * @property CreditStatus $status
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $ended_at
 */
class CommissionCredit extends Model
{
    use HasUuids;

    protected $table = 'commission_credit';

    protected $primaryKey = 'credit_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => CreditSource::class,
            'kind' => CreditKind::class,
            'status' => CreditStatus::class,
            'uses_total' => 'integer',
            'uses_left' => 'integer',
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
