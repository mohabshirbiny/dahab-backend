<?php

use App\Enums\BuyRequestState;
use App\Enums\OrderState;
use App\Enums\SeedRole;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 FR-022, SC-002, SC-003, SC-007: after a mix of every way a request
// can end, each request's deposit on the ledger is exactly what its state says,
// each buyer's held balance is the sum of their live deposits, and positions
// on a listing never repeat.

it('reconciles every deposit with its request', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $seller = Customer::factory()->verified()->create();
    $listings = collect(range(1, 4))->map(fn () => Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]));
    $buyers = collect(range(1, 5))->map(fn () => BuyRequests::funded('100000'));

    // Every buyer joins every listing.
    $requests = $listings->mapWithKeys(fn (Listing $l, int $i) => [$i => $buyers->map(fn (Customer $b) => BuyRequests::queued($this, $b, $l))]);

    // Listing 0: two leave (one rejoins at the back), the seller declines the head, accepts the next.
    BuyRequests::leave($this, $buyers[1], $requests[0][1])->assertOk();
    BuyRequests::leave($this, $buyers[2], $requests[0][2], notify: true)->assertOk();
    $rejoined = BuyRequests::queued($this, $buyers[1], $listings[0]);
    BuyRequests::decline($this, $seller, $listings[0], $requests[0][0])->assertOk();
    BuyRequests::accept($this, $seller, $listings[0], $requests[0][3], BuyRequests::branchOf($listings[0]))->assertCreated();

    // Listing 1: accepted, then Dahab cancels the acceptance.
    BuyRequests::accept($this, $seller, $listings[1], $requests[1][0], BuyRequests::branchOf($listings[1]))->assertCreated();
    $order = Order::query()->where('listing_id', $listings[1]->listing_id)->sole();
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(BuyRequests::ORDERS_URL."/{$order->order_id}/cancel", ['reason' => 'The piece was damaged before delivery.', 'relist' => true], Listings::key())->assertOk();

    // Listing 2: taken down by staff. Listing 3: the line expires.
    $this->postJson(Listings::STAFF_URL."/{$listings[2]->listing_id}/takedown", ['reason' => 'The photos are taken from another website.'], Listings::key())->assertOk();
    $this->travel(49)->hours();
    $this->artisan('buy-requests:expire')->assertSuccessful();

    BuyRequests::checkNow();

    DatabaseActor::push('maintenance');
    try {
        foreach (BuyRequest::query()->with('order')->get() as $request) {
            $expected = ($request->state === BuyRequestState::QUEUED
                || ($request->state === BuyRequestState::ACCEPTED && $request->order?->state !== OrderState::CANCELLED_STAFF))
                ? bcadd((string) $request->deposit_amount, '0', 4)
                : '0.0000';

            expect(BuyRequests::netHeld($request))->toBe($expected, "request {$request->buy_request_id} ({$request->state->value})");
        }

        foreach ($buyers as $buyer) {
            $live = BuyRequest::query()->where('buyer_id', $buyer->customer_id)->with('order')->get()
                ->filter(fn (BuyRequest $r) => $r->state === BuyRequestState::QUEUED
                    || ($r->state === BuyRequestState::ACCEPTED && $r->order?->state !== OrderState::CANCELLED_STAFF))
                ->reduce(fn (string $sum, BuyRequest $r) => bcadd($sum, (string) $r->deposit_amount, 4), '0.0000');

            expect(BuyRequests::balances($buyer)['held'])->toBe($live);
        }

        // Positions strictly increase per listing, never reused.
        foreach ($listings as $listing) {
            $positions = BuyRequest::query()->where('listing_id', $listing->listing_id)->orderBy('requested_at')->pluck('queue_position')->all();
            expect($positions)->toBe(range(1, count($positions)));
        }

        // Every ledger transaction balances.
        expect((int) DB::selectOne('SELECT count(*) AS n FROM (SELECT ledger_txn_id FROM ledger_posting GROUP BY ledger_txn_id HAVING SUM(amount) <> 0) unbalanced')->n)->toBe(0);
    } finally {
        DatabaseActor::pop();
    }

    expect($rejoined->fresh()->queue_position)->toBe(6);
});
