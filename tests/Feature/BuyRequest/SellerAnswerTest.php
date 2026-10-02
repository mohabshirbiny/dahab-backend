<?php

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Models\Branch;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Notifications\BuyRequestNotification;
use App\Support\SystemActor;
use App\Support\WorkingHours\WorkingHoursResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 US3; FR-013–FR-017: the seller sees the line, accepts the head at
// a branch they named (the order is created, everyone else refunded) or
// declines the head.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create(['display_ref' => '9001']);
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->branch = BuyRequests::branchOf($this->listing);
    $this->buyers = collect(['4417', '6620', '5510'])->map(fn (string $ref) => BuyRequests::funded('20000', ['display_ref' => $ref]));
    $this->requests = $this->buyers->map(fn (Customer $b) => BuyRequests::queued($this, $b, $this->listing));
});

it('shows the seller the line in order, buyers by reference only', function () {
    $res = Listings::as($this, $this->seller)->getJson(BuyRequests::SELLER_URL."/{$this->listing->listing_id}/buy-requests")->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $this->requests[0]->buy_request_id)
        ->assertJsonPath('data.0.is_head', true)
        ->assertJsonPath('data.0.place_in_line', 1)
        ->assertJsonPath('data.0.buyer', ['display_ref' => '4417'])
        ->assertJsonPath('data.2.buyer', ['display_ref' => '5510'])
        ->assertJsonPath('data.1.is_head', false)
        ->assertJsonPath('meta.queue_count', 3);

    expect($res->json('meta.you_would_receive'))->not->toBeNull();

    $json = json_encode($res->json());
    foreach ($this->buyers as $buyer) {
        foreach (array_filter([$buyer->customer_id, $buyer->phone, $buyer->full_name, $buyer->email]) as $private) {
            expect($json)->not->toContain((string) $private);
        }
    }

    // Nobody else reads it.
    Listings::as($this, $this->buyers[0])->getJson(BuyRequests::SELLER_URL."/{$this->listing->listing_id}/buy-requests")->assertNotFound();
});

it('accepts the head: the order, the working-hours deadline, the others refunded, the piece off the market', function () {
    // Thursday 16:00 Cairo; the branch opens Sunday–Thursday 10:00–18:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 16:00', 'Africa/Cairo'));
    [$head, $second, $third] = $this->requests->all();
    $heldBefore = BuyRequests::balances($this->buyers[0])['held'];

    $res = BuyRequests::accept($this, $this->seller, $this->listing, $head, $this->branch)->assertCreated()
        ->assertJsonPath('data.order.state', 'awaiting_delivery')
        ->assertJsonPath('data.order.branch.id', $this->branch)
        ->assertJsonPath('data.order.locked_total_price', bcadd((string) $head->locked_total_price, '0', 4))
        ->assertJsonPath('data.listing.state', 'accepted')
        ->assertJsonPath('data.listing.order.order_ref', fn ($ref) => preg_match('/^DH-2026-\d{6}$/', $ref) === 1)
        ->assertJsonPath('data.released_count', 2);

    $order = Order::query()->sole();
    $expected = app(WorkingHoursResolver::class)->addWorkingMinutes(CarbonImmutable::now(), 12 * 60, $this->branch);

    expect($order->buy_request_id)->toBe($head->buy_request_id)
        ->and($order->buyer_id)->toBe($head->buyer_id)
        ->and($order->seller_id)->toBe($this->seller->customer_id)
        ->and($order->accepted_by)->toBe($this->seller->customer_id)
        ->and($order->state)->toBe(OrderState::AWAITING_DELIVERY)
        ->and($order->reach_branch_deadline->equalTo($expected))->toBeTrue()
        // Thu 16–18 (2 h) + Sun 10–18 (8 h) + Mon 10–12 (2 h).
        ->and($order->reach_branch_deadline->setTimezone('Africa/Cairo')->format('D H:i'))->toBe('Mon 12:00')
        ->and($res->json('data.order.order_ref'))->toBe($order->order_ref);

    expect($head->fresh()->state)->toBe(BuyRequestState::ACCEPTED)
        ->and(BuyRequests::balances($this->buyers[0])['held'])->toBe($heldBefore)
        ->and(BuyRequests::netHeld($head))->toBe(bcadd((string) $head->deposit_amount, '0', 4));

    foreach ([$second, $third] as $i => $other) {
        expect($other->fresh()->state)->toBe(BuyRequestState::RELEASED_NOT_CHOSEN)
            ->and(BuyRequests::netHeld($other))->toBe('0.0000')
            ->and(BuyRequests::balances($this->buyers[$i + 1]))->toBe(['available' => '20000.0000', 'held' => '0.0000'])
            ->and(DB::table('ledger_transaction')->where('buy_request_id', $other->buy_request_id)->where('event_kind', 'deposit_release')->value('customer_id'))
            ->toBe($this->seller->customer_id);
    }

    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    expect($this->listing->fresh()->state)->toBe(ListingState::ACCEPTED)
        ->and($this->listing->fresh()->active_queue_count)->toBe(0)
        ->and($change->to_state)->toBe('accepted')
        ->and($change->actor_customer_id)->toBe($this->seller->customer_id);

    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertNotFound();

    // The buyer sees the order on their request.
    Listings::as($this, $this->buyers[0])->getJson(BuyRequests::BUYER_URL."/{$head->buy_request_id}")->assertOk()
        ->assertJsonPath('data.state', 'accepted')
        ->assertJsonPath('data.order.order_ref', $order->order_ref)
        ->assertJsonPath('data.listing.state', 'accepted');

    Notification::assertSentTo($this->buyers[0], BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::ACCEPTED && $n->orderRef === $order->order_ref);
    Notification::assertSentTo($this->buyers[1], BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::NOT_CHOSEN);
    Notification::assertSentTo($this->buyers[2], BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::NOT_CHOSEN);

    BuyRequests::checkNow();
});

it('accepts only the head, at a named enabled branch, and never a suspended buyer', function () {
    [$head, $second] = $this->requests->all();

    BuyRequests::accept($this, $this->seller, $this->listing, $second, $this->branch)->assertStatus(409)->assertJsonPath('code', 'not_queue_head');

    $elsewhere = Branch::factory()->create()->branch_id;
    BuyRequests::accept($this, $this->seller, $this->listing, $head, $elsewhere)->assertStatus(409)->assertJsonPath('code', 'branch_not_in_options');

    Branch::query()->whereKey($this->branch)->update(['is_enabled' => false]);
    BuyRequests::accept($this, $this->seller, $this->listing, $head, $this->branch)->assertStatus(409)->assertJsonPath('code', 'branch_not_in_options');
    Branch::query()->whereKey($this->branch)->update(['is_enabled' => true]);

    DB::table('customer')->where('customer_id', $head->buyer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);
    BuyRequests::accept($this, $this->seller, $this->listing, $head, $this->branch)->assertStatus(409)->assertJsonPath('code', 'buyer_suspended');

    // The seller may decline them instead.
    BuyRequests::decline($this, $this->seller, $this->listing, $head)->assertOk();

    expect(Order::query()->count())->toBe(0)
        ->and($this->listing->fresh()->state)->toBe(ListingState::RESERVED);
});

it('refuses a branch whose hours cannot give a deadline', function () {
    DB::table('branch_hours')->where('branch_id', $this->branch)->delete();

    BuyRequests::accept($this, $this->seller, $this->listing, $this->requests[0], $this->branch)->assertStatus(409)
        ->assertJsonPath('code', 'branch_hours_unavailable');

    expect(Order::query()->count())->toBe(0)
        ->and($this->requests[0]->fresh()->state)->toBe(BuyRequestState::QUEUED);
});

it('declines the head only: refunded, the next moves up, empty means live', function () {
    [$head, $second, $third] = $this->requests->all();

    BuyRequests::decline($this, $this->seller, $this->listing, $second)->assertStatus(409)->assertJsonPath('code', 'not_queue_head');

    BuyRequests::decline($this, $this->seller, $this->listing, $head)->assertOk()
        ->assertJsonPath('data.state', 'reserved')
        ->assertJsonPath('data.queue_count', 2);

    expect($head->fresh()->state)->toBe(BuyRequestState::RELEASED_DECLINED)
        ->and(BuyRequests::balances($this->buyers[0]))->toBe(['available' => '20000.0000', 'held' => '0.0000']);
    Notification::assertSentTo($this->buyers[0], BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::DECLINED);

    Listings::as($this, $this->seller)->getJson(BuyRequests::SELLER_URL."/{$this->listing->listing_id}/buy-requests")->assertOk()
        ->assertJsonPath('data.0.id', $second->buy_request_id)->assertJsonPath('data.0.is_head', true);

    BuyRequests::decline($this, $this->seller, $this->listing, $second)->assertOk();
    BuyRequests::decline($this, $this->seller, $this->listing, $third)->assertOk()->assertJsonPath('data.state', 'live');
    BuyRequests::decline($this, $this->seller, $this->listing, $third)->assertStatus(409)->assertJsonPath('code', 'queue_empty');

    expect($this->listing->fresh()->state)->toBe(ListingState::LIVE);
    BuyRequests::checkNow();
});

it('lets only the seller answer, and only when they may trade', function () {
    $head = $this->requests[0];

    BuyRequests::accept($this, $this->buyers[1], $this->listing, $head, $this->branch)->assertNotFound();
    BuyRequests::decline($this, $this->buyers[1], $this->listing, $head)->assertNotFound();

    DB::table('customer')->where('customer_id', $this->seller->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);
    BuyRequests::accept($this, $this->seller, $this->listing, $head, $this->branch)->assertStatus(403)->assertJsonPath('code', 'account_suspended');
    // A suspended seller may still read the line.
    Listings::as($this, $this->seller)->getJson(BuyRequests::SELLER_URL."/{$this->listing->listing_id}/buy-requests")->assertOk();

    expect($head->fresh()->state)->toBe(BuyRequestState::QUEUED);
});

it('takes effect once for the same idempotency key', function () {
    $key = (string) Str::uuid();

    BuyRequests::accept($this, $this->seller, $this->listing, $this->requests[0], $this->branch, $key)->assertCreated();
    BuyRequests::accept($this, $this->seller, $this->listing, $this->requests[0], $this->branch, $key)->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(Order::query()->count())->toBe(1)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'deposit_release')->count())->toBe(2);
});

it('keeps the seller from editing the piece while buyers wait', function () {
    Listings::as($this, $this->seller)->patchJson(Listings::SELLER_URL."/{$this->listing->listing_id}", ['description' => str_repeat('b', 60)], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'listing_not_editable');

    expect(BuyRequest::query()->where('state', 'queued')->count())->toBe(3);
});
