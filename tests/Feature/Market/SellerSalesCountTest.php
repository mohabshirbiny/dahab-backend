<?php

use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Seller notes 2026-10-27: the piece page tells buyers how many sales its seller completed — a count, never who.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('counts the seller\'s settled sales only', function () {
    $seller = Customer::factory()->verified()->create();
    $piece = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);
    $count = fn () => Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$piece->listing_id}")->assertOk()->json('data.seller_sales_count');

    expect($count())->toBe(0);

    // An accepted and inspected sale is not completed until the buyer pays.
    $order = Orders::inspected($this, Orders::accepted($this, Orders::ring($seller)));
    expect($count())->toBe(0);

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    expect($count())->toBe(1);

    // Another seller's sale does not count.
    $other = Orders::inspected($this, Orders::accepted($this, Orders::ring(Customer::factory()->verified()->create())));
    Orders::pay($this, Orders::buyer($other), $other)->assertOk();
    expect($count())->toBe(1);
});
