<?php

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Notifications\BuyRequestNotification;
use App\Support\BuyRequests\DepositLedger;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 FR-020a, research R22 (analysis H3): staff with order.cancel cancel
// an acceptance — cancelled_staff, full refund, relist or withdraw, audited,
// both sides told.

const CANCEL_REASON = 'The seller reported the piece damaged before delivery.';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->buyer = BuyRequests::funded('20000');
    $this->request = BuyRequests::queued($this, $this->buyer, $this->listing);
    BuyRequests::accept($this, $this->seller, $this->listing, $this->request, BuyRequests::branchOf($this->listing))->assertCreated();
    $this->order = Order::query()->sole();
});

function cancelOrder($test, Order $order, array $body, ?string $key = null)
{
    return $test->postJson(BuyRequests::ORDERS_URL."/{$order->order_id}/cancel", $body, Listings::key($key));
}

it('cancels the acceptance, refunds the buyer in full and relists the piece', function () {
    $staff = Listings::actAsStaff($this, SeedRole::OPERATIONS);

    $this->getJson(Listings::STAFF_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.order.order_ref', $this->order->order_ref)
        ->assertJsonPath('data.order.can_cancel', true);

    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => true])->assertOk()
        ->assertJsonPath('data.order.state', 'cancelled_staff')
        ->assertJsonPath('data.order.cancel_reason', CANCEL_REASON)
        ->assertJsonPath('data.listing.state', 'live')
        ->assertJsonPath('data.listing.order.can_cancel', false)
        ->assertJsonPath('data.refunded', bcadd((string) $this->request->deposit_amount, '0', 4));

    $order = $this->order->fresh();
    $release = DB::table('ledger_transaction')->where('buy_request_id', $this->request->buy_request_id)->where('event_kind', 'deposit_release')->first();
    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    $audit = AuditLog::query()->where('action', 'order.cancelled')->sole();

    expect($order->state)->toBe(OrderState::CANCELLED_STAFF)
        ->and($order->cancelled_by)->toBe($staff->staff_id)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($this->request->fresh()->state)->toBe(BuyRequestState::ACCEPTED)
        ->and($release->staff_id)->toBe($staff->staff_id)
        ->and($release->order_id)->toBe($order->order_id)
        ->and(BuyRequests::balances($this->buyer))->toBe(['available' => '20000.0000', 'held' => '0.0000'])
        ->and(BuyRequests::netHeld($this->request))->toBe('0.0000')
        ->and($this->listing->fresh()->state)->toBe(ListingState::LIVE)
        ->and($this->listing->fresh()->active_queue_count)->toBe(0)
        ->and($change->from_state)->toBe('accepted')
        ->and($change->note)->toBe(CANCEL_REASON)
        ->and($change->actor_staff_id)->toBe($staff->staff_id)
        ->and($audit->reason)->toBe(CANCEL_REASON)
        ->and($audit->actor_staff_id)->toBe($staff->staff_id)
        ->and($audit->before_json)->toBe(['state' => 'awaiting_delivery', 'listing_state' => 'accepted'])
        ->and($audit->after_json['relist'])->toBeTrue();

    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertOk()->assertJsonPath('data.queue_count', 0);

    Notification::assertSentTo($this->buyer, BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::ORDER_CANCELLED && $n->amount !== null);
    Notification::assertSentTo($this->seller, BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::ORDER_CANCELLED && $n->amount === null);

    // Buyer and seller see it.
    Listings::as($this, $this->buyer)->getJson(BuyRequests::BUYER_URL."/{$this->request->buy_request_id}")->assertOk()
        ->assertJsonPath('data.state', 'accepted')->assertJsonPath('data.order.state', 'cancelled_staff')
        ->assertJsonPath('data.order.cancel_reason', CANCEL_REASON);
    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.state', 'live')->assertJsonPath('data.order.state', 'cancelled_staff');

    BuyRequests::checkNow();
});

it('can withdraw the piece for good instead', function () {
    Listings::actAsStaff($this, SeedRole::COO);

    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => false])->assertOk()->assertJsonPath('data.listing.state', 'withdrawn');

    expect($this->listing->fresh()->state)->toBe(ListingState::WITHDRAWN)
        ->and(BuyRequests::balances($this->buyer)['held'])->toBe('0.0000');
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertNotFound();
    BuyRequests::checkNow();
});

it('is for staff holding order.cancel only, and the denial is audited', function () {
    Listings::actAsStaff($this, SeedRole::FINANCE);

    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => true])->assertForbidden()->assertJsonPath('code', 'permission_denied');

    expect($this->order->fresh()->state)->toBe(OrderState::AWAITING_DELIVERY)
        ->and(AuditLog::query()->where('action', 'auth.staff.permission_denied')->count())->toBeGreaterThan(0);
});

it('seeds order.cancel to the CEO, the COO and Operations', function () {
    Listings::actAsStaff($this, SeedRole::CEO);
    $holders = DB::table('role_has_permissions as rp')
        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('p.name', 'order.cancel')->pluck('r.name')->sort()->values()->all();

    expect($holders)->toBe(['ceo', 'coo', 'operations']);
});

it('validates the reason and the choice, and cancels only an order awaiting delivery', function () {
    Listings::actAsStaff($this, SeedRole::OPERATIONS);

    cancelOrder($this, $this->order, ['reason' => 'short', 'relist' => true])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON])->assertStatus(422);

    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => true])->assertOk();
    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => true])->assertStatus(409)->assertJsonPath('code', 'order_not_cancellable');

    $this->postJson(BuyRequests::ORDERS_URL.'/'.Str::uuid().'/cancel', ['reason' => CANCEL_REASON, 'relist' => true], Listings::key())->assertNotFound();

    expect(DB::table('ledger_transaction')->where('event_kind', 'deposit_release')->count())->toBe(1);
});

it('takes effect once for the same idempotency key', function () {
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $key = (string) Str::uuid();

    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => true], $key)->assertOk();
    cancelOrder($this, $this->order, ['reason' => CANCEL_REASON, 'relist' => true], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(AuditLog::query()->where('action', 'order.cancelled')->count())->toBe(1);
});

it('refuses a refund on an accepted request whose order is not cancelled', function () {
    // The engine backstop: releasing an accepted deposit without cancelling its order fails at commit.
    $this->expectException(QueryException::class);

    DB::transaction(function () {
        app(DepositLedger::class)->release($this->request->fresh(), null, SystemActor::id());
        BuyRequests::checkNow();
    });
});
