<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A market-maker promo code (schema §15, spec 019 FR-060): tied to exactly one customer of
 * type `market_maker`. Only its cap and its on/off ever change (guard DH020); never deleted.
 *
 * @property string $code
 * @property string $kind
 * @property string|null $tied_customer_id
 * @property bool $commission_waived
 * @property bool $gives_spread
 * @property string|null $monthly_cap_egp
 * @property bool $is_active
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property string|null $deactivated_by
 * @property CarbonImmutable|null $deactivated_at
 */
class PromoCode extends Model
{
    protected $table = 'promo_code';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'commission_waived' => 'boolean',
            'gives_spread' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'tied_customer_id', 'customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by', 'staff_id');
    }
}
