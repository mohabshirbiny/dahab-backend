<?php

use App\Actions\BuyRequests\ListOwnBuyRequestsAction;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 FR-012, analysis C1: a buyer's requests and an order's parties are
// isolated by the database. A buyer's own reads run under plain row isolation,
// never under the wider `queue` scope.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->alice = BuyRequests::funded('20000');
    $this->bob = BuyRequests::funded('20000');
    $this->aliceRequest = BuyRequests::queued($this, $this->alice, $this->listing);
    $this->bobRequest = BuyRequests::queued($this, $this->bob, $this->listing);
});

it('answers not found for another buyer\'s request', function () {
    Listings::as($this, $this->bob)->getJson(BuyRequests::BUYER_URL."/{$this->aliceRequest->buy_request_id}")->assertNotFound();
    BuyRequests::leave($this, $this->bob, $this->aliceRequest)->assertNotFound();

    expect($this->aliceRequest->fresh()->state->value)->toBe('queued');
});

it('isolates requests in the database, whatever the application asks', function () {
    $rows = fn (string $customerId) => (function () use ($customerId) {
        DatabaseActor::push('customer', $customerId);
        try {
            return BuyRequest::query()->pluck('buy_request_id')->all();
        } finally {
            DatabaseActor::pop();
        }
    })();

    expect($rows($this->bob->customer_id))->toBe([$this->bobRequest->buy_request_id])
        ->and($rows($this->seller->customer_id))->toBe([]);

    // No scope at all: nothing.
    DatabaseActor::push('');
    try {
        expect(BuyRequest::query()->count())->toBe(0);
    } finally {
        DatabaseActor::pop();
    }
});

it('never returns another buyer\'s row from the own-requests reader, even with a foreign id', function () {
    DatabaseActor::push('customer', $this->bob->customer_id);
    try {
        $page = app(ListOwnBuyRequestsAction::class)->handle($this->bob->customer_id, null, null, null, 50);
        expect($page['rows']->pluck('buy_request_id')->all())->toBe([$this->bobRequest->buy_request_id]);

        // Even when the reader is pointed at someone else, the rows come from row isolation.
        $foreign = app(ListOwnBuyRequestsAction::class)->handle($this->alice->customer_id, null, null, null, 50);
        expect($foreign['rows'])->toHaveCount(0);

        expect(fn () => app(ListOwnBuyRequestsAction::class)->show($this->bob->customer_id, $this->aliceRequest->buy_request_id))
            ->toThrow(ModelNotFoundException::class);
    } finally {
        DatabaseActor::pop();
    }
});

it('shows an order to its buyer and seller only', function () {
    BuyRequests::accept($this, $this->seller, $this->listing, $this->aliceRequest, BuyRequests::branchOf($this->listing))->assertCreated();

    $orders = function (string $customerId) {
        DatabaseActor::push('customer', $customerId);
        try {
            return Order::query()->count();
        } finally {
            DatabaseActor::pop();
        }
    };

    expect($orders($this->alice->customer_id))->toBe(1)
        ->and($orders($this->seller->customer_id))->toBe(1)
        ->and($orders($this->bob->customer_id))->toBe(0);
});

it('protects both new tables with forced row-level security', function () {
    $forced = DB::table('pg_class')->whereIn('relname', ['buy_request', 'order'])
        ->pluck('relforcerowsecurity', 'relname')->all();

    expect($forced)->toEqual(['buy_request' => true, 'order' => true]);
});
