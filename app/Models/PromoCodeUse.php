<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted use of a code, allowed or blocked with a reason (schema §15, FR-061).
 * Append-only; read by staff only.
 *
 * @property int $use_id
 * @property string $code
 * @property string|null $order_id
 * @property string $customer_id
 * @property bool $allowed
 * @property string|null $block_reason
 * @property string|null $buy_request_id
 * @property string|null $amount_egp
 * @property string|null $device_fingerprint
 * @property string|null $listing_id
 * @property string|null $id_key
 * @property string|null $credit_id
 * @property CarbonImmutable $used_at
 */
class PromoCodeUse extends Model
{
    protected $table = 'promo_code_use';

    protected $primaryKey = 'use_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allowed' => 'boolean', 'used_at' => 'immutable_datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
}
