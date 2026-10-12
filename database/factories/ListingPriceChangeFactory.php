<?php

namespace Database\Factories;

use App\Enums\ListingPriceField;
use App\Models\Listing;
use App\Models\ListingPriceChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A recorded price change (spec 022) for staff-detail and migration tests. The row is only
 * history: it does not move the listing's own figure. Insert it in the elevated test scope.
 *
 * @extends Factory<ListingPriceChange>
 */
class ListingPriceChangeFactory extends Factory
{
    protected $model = ListingPriceChange::class;

    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory()->live(),
            'field' => ListingPriceField::MAKING_CHARGE_PER_G->value,
            'old_value' => '250.00',
            'new_value' => '220.00',
            'actor_customer_id' => fn (array $a) => Listing::query()->findOrFail($a['listing_id'])->seller_id,
        ];
    }
}
