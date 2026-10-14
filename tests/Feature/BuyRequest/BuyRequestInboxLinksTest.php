<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Testing fixes item 7: each buy-request message opens the right screen. The stored
// customer_notification link: the seller's new request → their listing; the buyer's
// "accepted" → the new order; refunds → wallet; a cancelled sale → the order (both sides).

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

/** @return array{kind: string, id: ?string} the link of the customer's newest inbox item of this type */
function inboxLink(Customer $customer, string $type): array
{
    $row = DatabaseActor::elevate('maintenance', fn () => CustomerNotification::query()
        ->where('customer_id', $customer->customer_id)->where('type', $type)->latest('created_at')->firstOrFail());

    return ['kind' => $row->link_kind->value, 'id' => $row->link_id];
}

it('links the seller\'s new request to their own listing', function () {
    BuyRequests::queued($this, BuyRequests::funded('20000'), $this->listing);

    expect(inboxLink($this->seller, 'buy_request.new_request'))->toBe(['kind' => 'listing', 'id' => $this->listing->listing_id]);
});

it('links the accepted buyer to the new order and the others to their wallet', function () {
    $first = BuyRequests::funded('20000');
    $second = BuyRequests::funded('20000');
    $request = BuyRequests::queued($this, $first, $this->listing);
    BuyRequests::queued($this, $second, $this->listing);

    BuyRequests::accept($this, $this->seller, $this->listing, $request, BuyRequests::branchOf($this->listing))->assertCreated();
    $order = Order::query()->sole();

    expect(inboxLink($first, 'buy_request.accepted'))->toBe(['kind' => 'order', 'id' => $order->order_id])
        ->and(inboxLink($second, 'buy_request.not_chosen'))->toBe(['kind' => 'wallet', 'id' => null]);
});

it('links a declined buyer to their wallet', function () {
    $buyer = BuyRequests::funded('20000');
    $request = BuyRequests::queued($this, $buyer, $this->listing);

    BuyRequests::decline($this, $this->seller, $this->listing, $request)->assertOk();

    expect(inboxLink($buyer, 'buy_request.declined'))->toBe(['kind' => 'wallet', 'id' => null]);
});

it('links both sides of a cancelled sale to the order', function () {
    $buyer = BuyRequests::funded('20000');
    $request = BuyRequests::queued($this, $buyer, $this->listing);
    BuyRequests::accept($this, $this->seller, $this->listing, $request, BuyRequests::branchOf($this->listing))->assertCreated();
    $order = Order::query()->sole();

    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(BuyRequests::ORDERS_URL."/{$order->order_id}/cancel",
        ['reason' => 'The seller reported the piece damaged before delivery.', 'relist' => true], Listings::key())->assertOk();

    expect(inboxLink($buyer, 'buy_request.order_cancelled'))->toBe(['kind' => 'order', 'id' => $order->order_id])
        ->and(inboxLink($this->seller, 'buy_request.order_cancelled'))->toBe(['kind' => 'order', 'id' => $order->order_id]);
});
