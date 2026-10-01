<?php

use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\ListingState;
use App\Models\AgreementAcceptance;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Setting;
use App\Notifications\BuyRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 US1; FR-001–FR-008: a buyer sends a request, the deposit is held
// on the ledger, the listing is reserved, the seller is told.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->buyer = BuyRequests::funded('20000');
});

it('holds the deposit, queues the buyer and reserves the piece in one operation', function () {
    $price = BuyRequests::price($this, $this->listing);
    $deposit = bcadd(bcadd(bcdiv(bcmul($price, '20', 8), '100', 8), '0.005', 8), '0', 2);

    $res = BuyRequests::send($this, $this->buyer, $this->listing)->assertCreated()
        ->assertJsonPath('data.state', 'queued')
        ->assertJsonPath('data.queue_position', 1)
        ->assertJsonPath('data.place_in_line', 1)
        ->assertJsonPath('data.ahead_count', 0)
        ->assertJsonPath('data.locked_total_price', $price)
        ->assertJsonPath('data.deposit_amount', $deposit.'00')
        ->assertJsonPath('data.listing.id', $this->listing->listing_id)
        ->assertJsonPath('data.listing.state', 'reserved')
        ->assertJsonPath('data.listing.queue_count', 1)
        ->assertJsonPath('data.order', null);

    expect($res->json('data'))->not->toHaveKey('seller')
        ->and($res->json('data.listing'))->not->toHaveKey('seller_id');

    $request = BuyRequest::query()->findOrFail($res->json('data.id'));
    $hold = DB::table('ledger_transaction')->where('ledger_txn_id', $request->deposit_hold_txn_id)->first();

    expect($request->state)->toBe(BuyRequestState::QUEUED)
        ->and($request->locked_unit_rate)->not->toBeNull()
        ->and($request->seller_reply_deadline->diffInHours($request->requested_at, true))->toEqualWithDelta(48, 0.01)
        ->and($hold->event_kind)->toBe('deposit_hold')
        ->and($hold->buy_request_id)->toBe($request->buy_request_id)
        ->and($hold->listing_id)->toBe($this->listing->listing_id)
        ->and($hold->customer_id)->toBe($this->buyer->customer_id)
        ->and(BuyRequests::balances($this->buyer))->toBe(['available' => bcsub('20000', $deposit, 4), 'held' => bcadd($deposit, '0', 4)])
        ->and(BuyRequests::netHeld($request))->toBe(bcadd($deposit, '0', 4));

    $acceptance = AgreementAcceptance::query()->findOrFail($request->deposit_acceptance_id);
    expect($acceptance->context)->toBe('buy_request')
        ->and($acceptance->customer_id)->toBe($this->buyer->customer_id)
        ->and($acceptance->legal_doc_id)->toBe(BuyRequests::termsId());

    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    expect($this->listing->fresh()->state)->toBe(ListingState::RESERVED)
        ->and($this->listing->fresh()->active_queue_count)->toBe(1)
        ->and($change->from_state)->toBe('live')
        ->and($change->to_state)->toBe('reserved')
        ->and($change->actor_customer_id)->toBe($this->buyer->customer_id)
        ->and($change->note)->toBe('buy_request_queued');

    // Still on the market, with the line.
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.queue_count', 1)
        ->assertJsonPath('data.deposit_amount', $deposit.'00');

    Notification::assertSentTo($this->seller, BuyRequestNotification::class, fn (BuyRequestNotification $n) => $n->event === BuyRequestEvent::NEW_REQUEST && $n->deadline !== null);
    Notification::assertNotSentTo($this->buyer, BuyRequestNotification::class);

    BuyRequests::checkNow();
});

it('gives the next buyer the next place and keeps the listing reserved', function () {
    BuyRequests::queued($this, $this->buyer, $this->listing);
    $second = BuyRequests::funded('20000');

    BuyRequests::send($this, $second, $this->listing)->assertCreated()
        ->assertJsonPath('data.queue_position', 2)
        ->assertJsonPath('data.place_in_line', 2)
        ->assertJsonPath('data.ahead_count', 1);

    expect($this->listing->fresh()->active_queue_count)->toBe(2)
        ->and(DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->where('to_state', 'reserved')->count())->toBe(1);

    BuyRequests::checkNow();
});

it('locks the fresh price when the confirmed one is within the tolerance', function () {
    $price = BuyRequests::price($this, $this->listing);
    $near = bcmul($price, '1.004', 4); // 0.4% above; the tolerance is 0.5%

    BuyRequests::send($this, $this->buyer, $this->listing, ['confirm_locked_price' => $near])->assertCreated()
        ->assertJsonPath('data.locked_total_price', $price);
});

it('reads the deposit percent and the reply window live', function () {
    Setting::query()->whereKey('deposit.buyer_pct')->update(['value_numeric' => 10]);
    Setting::query()->whereKey('deadline.seller_reply_hours')->update(['value_numeric' => 6]);
    $price = BuyRequests::price($this, $this->listing);

    $request = BuyRequests::queued($this, $this->buyer, $this->listing);

    expect((string) $request->deposit_amount)->toBe(bcadd(bcadd(bcdiv($price, '10', 8), '0.005', 8), '0', 2).'00')
        ->and($request->seller_reply_deadline->diffInHours($request->requested_at, true))->toEqualWithDelta(6, 0.01);
});

it('holds a stone piece at its asking price, with no rate', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $rich = BuyRequests::funded('50000');

    $res = BuyRequests::send($this, $rich, $diamond)->assertCreated()
        ->assertJsonPath('data.locked_total_price', '120000.0000')
        ->assertJsonPath('data.deposit_amount', '24000.0000')
        ->assertJsonPath('data.locked_unit_rate', null);

    expect(BuyRequests::balances($rich)['held'])->toBe('24000.0000');
    BuyRequests::checkNow();
});

it('takes effect once for the same idempotency key', function () {
    $key = (string) Str::uuid();

    $first = BuyRequests::send($this, $this->buyer, $this->listing, [], $key)->assertCreated();
    $again = BuyRequests::send($this, $this->buyer, $this->listing, [], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(BuyRequest::query()->count())->toBe(1)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'deposit_hold')->count())->toBe(1);
});
