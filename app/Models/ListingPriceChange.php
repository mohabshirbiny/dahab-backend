<?php

namespace App\Models;

use App\Enums\ListingPriceField;
use Carbon\CarbonImmutable;
use Database\Factories\ListingPriceChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a live piece's price (spec 022, table `listing_price_change`):
 * which figure, from what to what, who and when. Append-only (guard DH023);
 * there is no update or delete path in code.
 *
 * @property int $change_id
 * @property string $listing_id
 * @property ListingPriceField $field
 * @property string $old_value
 * @property string $new_value
 * @property string|null $actor_customer_id
 * @property string|null $actor_staff_id
 * @property CarbonImmutable $changed_at
 */
class ListingPriceChange extends Model
{
    /** @use HasFactory<ListingPriceChangeFactory> */
    use HasFactory;

    protected $table = 'listing_price_change';

    protected $primaryKey = 'change_id';

    public $timestamps = false;

    protected $guarded = ['change_id', 'txid'];

    protected function casts(): array
    {
        return [
            'field' => ListingPriceField::class,
            'old_value' => 'decimal:4',
            'new_value' => 'decimal:4',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    /** The seller who made the change. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'actor_customer_id', 'customer_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id', 'staff_id');
    }
}
