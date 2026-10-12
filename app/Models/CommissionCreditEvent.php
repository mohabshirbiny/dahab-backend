<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only event of a commission credit (spec 023 data-model 2.2): granted, spent, expired or voided.
 *
 * @property int $event_id
 * @property string $credit_id
 * @property string $kind
 * @property string|null $order_id
 * @property string|null $ordinary_commission_egp
 * @property string|null $charged_commission_egp
 * @property string|null $reduction_egp
 * @property string|null $amount_left_after_egp
 * @property int|null $uses_left_after
 * @property string|null $reason
 * @property string|null $actor_customer_id
 * @property string|null $actor_staff_id
 * @property CarbonImmutable $created_at
 */
class CommissionCreditEvent extends Model
{
    protected $table = 'commission_credit_event';

    protected $primaryKey = 'event_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
