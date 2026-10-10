<?php

namespace App\Models;

use App\Enums\PartyRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One party's rating of an order (spec 018 FR-030–FR-036): 1–5 stars and an
 * optional note about the experience with Dahab. One per party and order,
 * written once and never changed (guard DH016). A customer reads only their
 * own row (forced row-level security); staff read both with `rating.view`.
 *
 * @property string $rating_id
 * @property string $order_id
 * @property PartyRole $party_role
 * @property string $customer_id
 * @property int $stars
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class OrderRating extends Model
{
    use HasUuids;

    protected $table = 'order_rating';

    protected $primaryKey = 'rating_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['rating_id'];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'party_role' => PartyRole::class,
            'stars' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }
}
