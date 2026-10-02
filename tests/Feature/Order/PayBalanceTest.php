<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Ledger;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US6, FR-015–FR-016, research R10, R16: the buyer pays from the
// wallet, in full, before the deadline; the order is ready to collect with a
// code only the buyer sees; the seller is told they were paid.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
});

it('pays: ready to collect, the piece sold, a 6-digit code to the buyer only, the seller told', function () {
    Bus::fake([NotifyCustomerJob::class]);

    $res = Orders::pay($this, $this->buyer, $this->order)->assertOk()
        ->assertJsonPath('data.state', 'ready_to_collect')
        ->assertJsonPath('data.stage', 'collect')
        ->assertJsonPath('data.final_total', '55631.2500');

    $code = $res->json('data.collection_code');
    $collection = OrderCollection::query()->where('order_id', $this->order->order_id)->sole();
    $order = $this->order->refresh();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($collection->code_hash)->not->toContain($code)
        ->and($order->collect_deadline->toDateString())->toBe(now()->setTimezone('Africa/Cairo')->addWeeks(3)->toDateString())
        ->and(Listing::query()->find($order->listing_id)->state->value)->toBe('sold')
        ->and(AuditLog::query()->where('action', 'order.paid')->sole()->actor_customer_id)->toBe($this->buyer->customer_id);

    // The list never carries the code; the seller never sees it.
    expect(Listings::as($this, $this->buyer)->getJson(Orders::CUSTOMER_URL)->assertOk()->getContent())->not->toContain($code);
    Orders::show($this, $this->seller, $order)->assertOk()
        ->assertJsonPath('data.collection_code', null)
        ->assertJsonPath('data.seller_proceeds', '54684.7500');

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id
        && $job->notification->event === OrderEvent::PAID && $job->notification->amount === '54684.7500');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id
        && $job->notification->event === OrderEvent::COLLECTION_CODE && $job->notification->code === $code);
});

it('refuses too little money with the figures, changing nothing', function () {
    $poor = BuyRequests::funded('11126.25');
    $order = Orders::inspected($this, Orders::accepted($this, null, '11126.25', $poor), '10.000');

    Orders::pay($this, $poor, $order)->assertStatus(409)
        ->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('details.amount_due', '44505.0000')
        ->assertJsonPath('details.available', '0.0000')
        ->assertJsonPath('details.shortfall', '44505.0000');

    expect($order->refresh()->state->value)->toBe('awaiting_balance');

    Ledger::topUp($poor, '44505');
    Orders::pay($this, $poor, $order)->assertOk();
});

it('refuses after the deadline, the seller, a stranger, a second payment, and replays a key once', function () {
    Orders::pay($this, $this->seller, $this->order)->assertNotFound();
    Orders::pay($this, BuyRequests::funded('1'), $this->order)->assertNotFound();

    $key = (string) Str::uuid();
    $first = Orders::pay($this, $this->buyer, $this->order, $key)->assertOk();
    Orders::pay($this, $this->buyer, $this->order, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.collection_code', $first->json('data.collection_code'));
    Orders::pay($this, $this->buyer, $this->order)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');

    expect(count(Orders::lines($this->order, 'balance_payment')))->toBe(8);
});

it('refuses once the balance deadline passed', function () {
    $this->travelTo($this->order->balance_due_deadline->addMinute());

    Orders::pay($this, $this->buyer, $this->order)->assertStatus(409)->assertJsonPath('code', 'balance_deadline_passed');
});

it('lets a suspended buyer pay', function () {
    Orders::staff($this, SeedRole::COO);
    $this->postJson("/api/v1/dashboard/customers/{$this->buyer->customer_id}/suspend",
        ['reason' => 'other', 'note' => 'Testing the wind-down of open orders.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    Orders::pay($this, $this->buyer, $this->order)->assertOk();
});

it('refuses a settlement whose proceeds would not be positive', function () {
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'commission.minimum_egp')->update(['value_numeric' => 99999]));

    Orders::pay($this, $this->buyer, $this->order)->assertStatus(409)->assertJsonPath('code', 'settlement_not_possible');
});
