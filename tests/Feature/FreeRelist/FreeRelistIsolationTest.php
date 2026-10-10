<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 T020, T030, FR-060, FR-061: another customer — and the seller of
// the original sale — cannot see or use the buyer's offer; ratings stay each
// party's own; the public market shows a relisted piece like any other.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

it('hides the buyer\'s offer, the relist and the new listing from everyone else', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);
    $stranger = Customer::factory()->verified()->create();

    FreeRelists::ac018Relist($this, $buyer, $order)->assertCreated();
    $new = Listing::query()->where('relisted_from_order_id', $order->order_id)->sole();

    foreach ([$seller, $stranger] as $other) {
        Orders::show($this, $other, $order)->assertStatus($other->customer_id === $seller->customer_id ? 200 : 404);
        Listings::as($this, $other)->getJson(Listings::SELLER_URL."/{$new->listing_id}")->assertNotFound();
        FreeRelists::ac018Relist($this, $other, $order, null, (string) Str::uuid())->assertNotFound();
    }

    // The seller of the original sale sees no offer of the buyer's, and nothing of the new listing.
    $view = Orders::show($this, $seller, $order)->assertOk();
    expect($view->json('data.free_relist.status'))->toBe('none')
        ->and($view->json('data.free_relist.listing_id'))->toBeNull()
        ->and(json_encode($view->json()))->not->toContain($new->listing_id);

    // The public market shows the relisted piece like any live one: no seller, no private media.
    $public = Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$new->listing_id}")->assertOk();
    $text = json_encode($public->json());
    expect($text)->not->toContain($buyer->customer_id)->not->toContain('seller_id')->not->toContain('relisted_from_order');
});

it('lets a customer read only their own rating row', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    FreeRelists::ac018Rate($this, Orders::buyer($order), $order, ['stars' => 5, 'note' => 'A'])->assertCreated();
    FreeRelists::ac018Rate($this, Orders::seller($order), $order, ['stars' => 2, 'note' => 'B'])->assertCreated();
    $buyer = Orders::buyer($order);

    // Under the buyer's own scope only their row exists to the engine.
    $rows = Orders::show($this, $buyer, $order)->json('data.rating');
    expect($rows['given']['note'])->toBe('A');

    DatabaseActor::push('customer', customerId: $buyer->customer_id);
    try {
        $visible = DB::table('order_rating')->pluck('stars')->all();
    } finally {
        DatabaseActor::pop();
    }
    expect($visible)->toBe([5]);
});
