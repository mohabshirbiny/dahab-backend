<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 015 FR-019 (the Customer App's "Held on open orders"): what each buy
// request and order holds now, from the ledger, on the customer's resources
// and as one list summing to held_on_orders.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

const HELD_URL = '/api/v1/customer/me/wallet/held';

it('lists each request and order holding money, summing to held_on_orders', function () {
    $buyer = BuyRequests::funded('300000');
    $a = BuyRequests::queued($this, $buyer, Orders::ring());
    $b = BuyRequests::queued($this, $buyer, Orders::ring());
    $order = Orders::accepted($this, buyer: $buyer);
    $left = BuyRequests::queued($this, $buyer, Orders::ring());
    BuyRequests::leave($this, $buyer, $left)->assertOk();

    $res = Listings::as($this, $buyer)->getJson(HELD_URL)->assertOk();
    $items = collect($res->json('data.items'));
    $wallet = Listings::as($this, $buyer)->getJson('/api/v1/customer/me/wallet')->assertOk();

    expect($items)->toHaveCount(3)
        ->and($items->firstWhere('id', $order->order_id))->toMatchArray(['type' => 'order', 'ref' => $order->order_ref,
            'amount' => BuyRequests::netHeld($order->buy_request_id)])
        ->and($items->firstWhere('id', $a->buy_request_id)['type'])->toBe('buy_request')
        ->and($items->firstWhere('id', $b->buy_request_id)['amount'])->toBe(bcadd((string) $b->deposit_amount, '0', 4))
        ->and($items->pluck('id'))->not->toContain($left->buy_request_id)
        ->and($res->json('data.total'))->toBe($wallet->json('data.held_on_orders'));
});

it('puts deposit_held on the request and the order, 0 once released, null for the seller', function () {
    $buyer = BuyRequests::funded('300000');
    $request = BuyRequests::queued($this, $buyer, Orders::ring());
    Listings::as($this, $buyer)->getJson("/api/v1/customer/me/buy-requests/{$request->buy_request_id}")->assertOk()
        ->assertJsonPath('data.deposit_held', bcadd((string) $request->deposit_amount, '0', 4));
    BuyRequests::leave($this, $buyer, $request)->assertOk();
    Listings::as($this, $buyer)->getJson('/api/v1/customer/me/buy-requests')->assertOk()
        ->assertJsonPath('data.0.deposit_held', '0.0000');

    $order = Orders::accepted($this);
    Listings::as($this, Orders::buyer($order))->getJson("/api/v1/customer/me/orders/{$order->order_id}")->assertOk()
        ->assertJsonPath('data.deposit_held', BuyRequests::netHeld($order->buy_request_id));
    Listings::as($this, Orders::seller($order))->getJson("/api/v1/customer/me/orders/{$order->order_id}")->assertOk()
        ->assertJsonPath('data.deposit_held', null);
    Listings::as($this, Orders::buyer($order))->getJson('/api/v1/customer/me/orders')->assertOk()
        ->assertJsonPath('data.0.deposit_held', BuyRequests::netHeld($order->buy_request_id));
});

it('holds nothing on a paid order, and never lists a withdrawal or another customer\'s request', function () {
    $order = Orders::inspected($this, Orders::accepted($this));
    $buyer = Orders::buyer($order);
    Orders::pay($this, $buyer, $order)->assertOk();
    $withdrawal = Withdrawals::requested($this, '1000', '1000');

    Listings::as($this, $buyer)->getJson(HELD_URL)->assertOk()->assertJsonPath('data.total', '0.0000')->assertJsonCount(0, 'data.items');
    Listings::as($this, Withdrawals::customer($withdrawal))->getJson(HELD_URL)->assertOk()->assertJsonCount(0, 'data.items');
    Listings::as($this, Customer::factory()->verified()->create())->getJson(HELD_URL)->assertOk()->assertJsonCount(0, 'data.items');
});

it('is for verified customers', function () {
    Listings::as($this, Customer::factory()->pendingVerification()->create())->getJson(HELD_URL)->assertForbidden();
});
