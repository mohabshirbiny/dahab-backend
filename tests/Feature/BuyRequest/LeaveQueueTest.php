<?php

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\BuyRequestNotification;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 US2; FR-009–FR-012: the buyer follows their requests, leaves the
// line, and is told once when the piece is free again.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->first = BuyRequests::funded('40000');
    $this->second = BuyRequests::funded('40000');
    $this->a = BuyRequests::queued($this, $this->first, $this->listing);
    $this->b = BuyRequests::queued($this, $this->second, $this->listing);
});

it('lists only my own requests, newest first, with my place in line and no seller', function () {
    $other = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $later = BuyRequests::queued($this, $this->second, $other);

    $res = Listings::as($this, $this->second)->getJson(BuyRequests::BUYER_URL)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $later->buy_request_id)
        ->assertJsonPath('data.1.id', $this->b->buy_request_id)
        ->assertJsonPath('data.1.place_in_line', 2)
        ->assertJsonPath('data.1.ahead_count', 1)
        ->assertJsonPath('data.1.listing.state', 'reserved')
        ->assertJsonPath('data.1.listing.queue_count', 2);

    expect(json_encode($res->json()))->not->toContain($this->seller->customer_id)
        ->and(json_encode($res->json()))->not->toContain($this->first->customer_id);

    Listings::as($this, $this->second)->getJson(BuyRequests::BUYER_URL.'?listing_id='.$this->listing->listing_id.'&state=queued')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->b->buy_request_id);

    // The cursor pages through.
    $page = Listings::as($this, $this->second)->getJson(BuyRequests::BUYER_URL.'?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    Listings::as($this, $this->second)->getJson(BuyRequests::BUYER_URL.'?per_page=1&cursor='.$page->json('meta.next_cursor'))->assertOk()
        ->assertJsonPath('data.0.id', $this->b->buy_request_id)->assertJsonPath('meta.next_cursor', null);
    Listings::as($this, $this->second)->getJson(BuyRequests::BUYER_URL.'?cursor=nonsense')->assertStatus(422);
});

it('moves me up when someone ahead leaves, and refunds the one who left', function () {
    $before = BuyRequests::balances($this->first);

    BuyRequests::leave($this, $this->first, $this->a)->assertOk()
        ->assertJsonPath('data.state', 'withdrawn_by_buyer')
        ->assertJsonPath('data.place_in_line', null);

    $release = DB::table('ledger_transaction')->where('buy_request_id', $this->a->buy_request_id)->where('event_kind', 'deposit_release')->first();

    expect($this->a->fresh()->state)->toBe(BuyRequestState::WITHDRAWN_BY_BUYER)
        ->and($this->a->fresh()->resolved_at)->not->toBeNull()
        ->and($release->customer_id)->toBe($this->first->customer_id)
        ->and(BuyRequests::balances($this->first))->toBe(['available' => bcadd($before['available'], $before['held'], 4), 'held' => '0.0000'])
        ->and(BuyRequests::netHeld($this->a))->toBe('0.0000')
        ->and($this->listing->fresh()->state)->toBe(ListingState::RESERVED)
        ->and($this->listing->fresh()->active_queue_count)->toBe(1);

    Listings::as($this, $this->second)->getJson(BuyRequests::BUYER_URL."/{$this->b->buy_request_id}")->assertOk()
        ->assertJsonPath('data.place_in_line', 1)->assertJsonPath('data.ahead_count', 0);

    Notification::assertNothingSentTo($this->first);
    BuyRequests::checkNow();
});

it('puts the piece back on the market when the last buyer leaves', function () {
    BuyRequests::leave($this, $this->first, $this->a)->assertOk();
    BuyRequests::leave($this, $this->second, $this->b)->assertOk()->assertJsonPath('data.listing.state', 'live');

    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    expect($this->listing->fresh()->state)->toBe(ListingState::LIVE)
        ->and($this->listing->fresh()->active_queue_count)->toBe(0)
        ->and($change->from_state)->toBe('reserved')
        ->and($change->actor_customer_id)->toBe($this->second->customer_id)
        ->and($change->note)->toBe('queue_emptied');

    BuyRequests::checkNow();
});

it('refuses to leave a request that is no longer in line', function () {
    BuyRequests::leave($this, $this->first, $this->a)->assertOk();

    BuyRequests::leave($this, $this->first, $this->a)->assertStatus(409)->assertJsonPath('code', 'not_in_queue');
    expect(DB::table('ledger_transaction')->where('buy_request_id', $this->a->buy_request_id)->where('event_kind', 'deposit_release')->count())->toBe(1);
});

it('lets me join again at the back with a new price', function () {
    BuyRequests::leave($this, $this->first, $this->a)->assertOk();

    BuyRequests::send($this, $this->first, $this->listing)->assertCreated()
        ->assertJsonPath('data.queue_position', 3)
        ->assertJsonPath('data.place_in_line', 2);
});

it('lets a suspended buyer read and leave', function () {
    DB::table('customer')->where('customer_id', $this->first->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);

    Listings::as($this, $this->first)->getJson(BuyRequests::BUYER_URL)->assertOk()->assertJsonCount(1, 'data');
    BuyRequests::leave($this, $this->first, $this->a)->assertOk()->assertJsonPath('data.state', 'withdrawn_by_buyer');
});

it('takes effect once for the same idempotency key', function () {
    $key = (string) Str::uuid();

    BuyRequests::leave($this, $this->first, $this->a, false, $key)->assertOk();
    BuyRequests::leave($this, $this->first, $this->a, false, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(DB::table('ledger_transaction')->where('buy_request_id', $this->a->buy_request_id)->where('event_kind', 'deposit_release')->count())->toBe(1);
});

it('tells a buyer who asked, once, when the piece is free again', function () {
    BuyRequests::leave($this, $this->first, $this->a, notify: true)->assertOk()->assertJsonPath('data.notify_when_free', true);

    // Still someone in line: nobody is told.
    Notification::assertNothingSentTo($this->first);

    BuyRequests::leave($this, $this->second, $this->b, notify: true)->assertOk();

    Notification::assertSentTo($this->first, BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::FREE_AGAIN);
    Notification::assertSentTo($this->second, BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::FREE_AGAIN);
    expect(BuyRequest::query()->whereNotNull('free_notified_at')->count())->toBe(2);

    // Queue and empty again: nobody is told twice.
    $third = BuyRequests::funded('20000');
    $c = BuyRequests::queued($this, $third, $this->listing);
    BuyRequests::leave($this, $third, $c)->assertOk();

    Notification::assertSentToTimes($this->first, BuyRequestNotification::class, 1);
});

it('does not tell a buyer who is already back in line', function () {
    BuyRequests::leave($this, $this->first, $this->a, notify: true)->assertOk();
    $again = BuyRequests::queued($this, $this->first, $this->listing);
    BuyRequests::leave($this, $this->second, $this->b)->assertOk();
    BuyRequests::leave($this, $this->first, $again)->assertOk();

    // The listing emptied twice; the first leave's promise is kept only while they were not in line.
    Notification::assertSentToTimes($this->first, BuyRequestNotification::class, 1);
});
