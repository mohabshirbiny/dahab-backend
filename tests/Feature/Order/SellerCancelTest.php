<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OrderStateChange;
use App\Models\SellerCancellation;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US2, FR-005, research R8, analysis C1/C2: the seller cancels an
// order awaiting delivery; the buyer is refunded at once; the cancellation
// counts; the piece is withdrawn; the request has one actor and never suspends.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
});

it('cancels: the buyer refunded, the cancellation counted, the piece withdrawn, one actor, audited', function () {
    Bus::fake([NotifyCustomerJob::class]);
    expect(BuyRequests::balances($this->buyer))->toBe(['available' => '48873.7500', 'held' => '11126.2500']);

    Orders::cancel($this, $this->seller, $this->order)->assertOk()
        ->assertJsonPath('data.state', 'cancelled_seller')
        ->assertJsonPath('data.cancel.reason_kind', 'seller')
        ->assertJsonPath('data.actions', []);

    $order = $this->order->refresh();
    expect(BuyRequests::balances($this->buyer))->toBe(['available' => '60000.0000', 'held' => '0.0000'])
        ->and($order->release_txn_id)->not->toBeNull()
        ->and(SellerCancellation::query()->where('order_id', $order->order_id)->sole()->by_sweep)->toBeFalse()
        ->and(Listing::query()->find($order->listing_id)->state->value)->toBe('withdrawn')
        ->and(OrderStateChange::query()->where('order_id', $order->order_id)->where('to_state', 'cancelled_seller')->sole()->actor_customer_id)->toBe($this->seller->customer_id);

    $audit = AuditLog::query()->where('action', 'order.seller_cancelled')->sole();
    expect($audit->actor_customer_id)->toBe($this->seller->customer_id)->and($audit->actor_staff_id)->toBeNull();

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id && $job->notification->event === OrderEvent::SELLER_CANCELLED);
    Bus::assertNotDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id);
});

it('never suspends inside the seller\'s own request, even at the threshold', function () {
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'suspension.cancellations_threshold')->update(['value_numeric' => 1]));

    Orders::cancel($this, $this->seller, $this->order)->assertOk();

    expect(Customer::query()->find($this->seller->customer_id)->status->value)->toBe('active')
        ->and(AuditLog::query()->where('action', 'auth.customer.suspended')->exists())->toBeFalse();
});

it('lets a suspended seller wind down', function () {
    Orders::staff($this, SeedRole::COO);
    $this->postJson("/api/v1/dashboard/customers/{$this->seller->customer_id}/suspend",
        ['reason' => 'other', 'note' => 'Testing the wind-down of open orders.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    Orders::cancel($this, $this->seller, $this->order)->assertOk()->assertJsonPath('data.state', 'cancelled_seller');
});

it('refuses the buyer, a stranger, a second cancel, and replays a key once', function () {
    Orders::cancel($this, $this->buyer, $this->order)->assertNotFound();
    Orders::cancel($this, BuyRequests::funded('10'), $this->order)->assertNotFound();

    $key = (string) Str::uuid();
    Orders::cancel($this, $this->seller, $this->order, $key)->assertOk();
    Orders::cancel($this, $this->seller, $this->order, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    Orders::cancel($this, $this->seller, $this->order)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');

    expect(SellerCancellation::query()->count())->toBe(1);
});

it('refuses a cancel once the piece reached the branch', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();

    Orders::cancel($this, $this->seller, $this->order)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');
});
