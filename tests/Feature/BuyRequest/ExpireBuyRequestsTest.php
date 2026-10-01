<?php

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\BuyRequestNotification;
use App\Support\SystemActor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 US4; FR-018: the seller-reply sweep. The command has no HTTP entry
// point, so every effect is read back through the API (analysis C2).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->buyer = BuyRequests::funded('20000');
    $this->request = BuyRequests::queued($this, $this->buyer, $this->listing);
});

it('releases a request past its reply deadline and refunds it, as the system actor', function () {
    $this->travel(47)->hours();
    $this->artisan('buy-requests:expire')->assertSuccessful();
    expect($this->request->fresh()->state)->toBe(BuyRequestState::QUEUED);

    $this->travel(2)->hours();
    $this->artisan('buy-requests:expire')->assertSuccessful();

    // Read back through the API.
    Listings::as($this, $this->buyer)->getJson(BuyRequests::BUYER_URL."/{$this->request->buy_request_id}")->assertOk()
        ->assertJsonPath('data.state', 'released_expired')
        ->assertJsonPath('data.place_in_line', null);
    Listings::as($this, $this->buyer)->getJson('/api/v1/customer/me/wallet')->assertOk()
        ->assertJsonPath('data.available', '20000.0000')
        ->assertJsonPath('data.held', '0.0000');
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.queue_count', 0);
    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.state', 'live');

    $release = DB::table('ledger_transaction')->where('buy_request_id', $this->request->buy_request_id)->where('event_kind', 'deposit_release')->first();
    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    expect($release->staff_id)->toBe(SystemActor::id())
        ->and($change->actor_staff_id)->toBe(SystemActor::id())
        ->and($change->to_state)->toBe('live');

    Notification::assertSentTo($this->buyer, BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::EXPIRED);

    // A second run changes nothing.
    $this->artisan('buy-requests:expire')->assertSuccessful();
    expect(DB::table('ledger_transaction')->where('event_kind', 'deposit_release')->count())->toBe(1);
    BuyRequests::checkNow();
});

it('releases per request, keeping the others in line', function () {
    $this->travel(30)->hours();
    $later = BuyRequests::funded('20000');
    $second = BuyRequests::queued($this, $later, $this->listing);

    $this->travel(19)->hours(); // the first is past 48 h, the second is not
    $this->artisan('buy-requests:expire')->assertSuccessful();

    expect($this->request->fresh()->state)->toBe(BuyRequestState::RELEASED_EXPIRED)
        ->and($second->fresh()->state)->toBe(BuyRequestState::QUEUED)
        ->and($this->listing->fresh()->state->value)->toBe('reserved');

    Listings::as($this, $later)->getJson(BuyRequests::BUYER_URL."/{$second->buy_request_id}")->assertOk()
        ->assertJsonPath('data.place_in_line', 1);
});

it('skips a request the seller already answered', function () {
    BuyRequests::decline($this, $this->seller, $this->listing, $this->request)->assertOk();
    $this->travel(49)->hours();

    $this->artisan('buy-requests:expire')->assertSuccessful();

    expect($this->request->fresh()->state)->toBe(BuyRequestState::RELEASED_DECLINED)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'deposit_release')->count())->toBe(1);
});

it('runs every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'buy-requests:expire'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
