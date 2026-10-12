<?php

namespace App\Models;

use App\Enums\AdvanceBasis;
use App\Enums\AdvanceStatus;
use App\Enums\WriteOffReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The advance paid to a first-time gold seller before the buyer pays (spec 023 research R11-R14). One per seller, ever.
 *
 * @property string $advance_id
 * @property string $order_id
 * @property string $seller_id
 * @property string $listing_id
 * @property string $amount_egp
 * @property string $cap_applied_egp
 * @property string $provisional_proceeds_egp
 * @property AdvanceBasis $basis
 * @property string|null $promo_code
 * @property string|null $enablement_id
 * @property string $advance_txn_id
 * @property AdvanceStatus $status
 * @property string|null $recovered_txn_id
 * @property string|null $recovered_egp
 * @property string|null $shortfall_absorbed_egp
 * @property CarbonImmutable|null $written_off_at
 * @property WriteOffReason|null $written_off_reason
 * @property string|null $written_off_amount_egp
 * @property CarbonImmutable $created_at
 */
class FirstSaleAdvance extends Model
{
    use HasUuids;

    protected $table = 'first_sale_advance';

    protected $primaryKey = 'advance_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'basis' => AdvanceBasis::class,
            'status' => AdvanceStatus::class,
            'written_off_reason' => WriteOffReason::class,
            'written_off_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
