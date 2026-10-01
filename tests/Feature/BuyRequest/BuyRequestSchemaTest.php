<?php

use App\Models\Branch;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\BuyRequests\DepositLedger;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 FR-004, FR-005, FR-021, FR-022, research R10: what the database
// itself refuses, whatever the application does. Direct SQL in the
// maintenance scope; each attempt runs in a savepoint so the test goes on.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->buyer = BuyRequests::funded('20000');
    $this->request = BuyRequests::queued($this, $this->buyer, $this->listing);
});

/** The SQLSTATE `$work` fails with (deferred checks forced), or null. */
function brSqlState(Closure $work): ?string
{
    try {
        DB::transaction(function () use ($work) {
            $work();
            BuyRequests::checkNow();
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

it('allows only the moves in buy_request_transition', function () {
    $id = $this->request->buy_request_id;

    expect(brSqlState(fn () => DB::table('buy_request')->insert([
        'listing_id' => $this->listing->listing_id, 'buyer_id' => $this->buyer->customer_id, 'state' => 'accepted',
        'queue_position' => 99, 'locked_total_price' => 1, 'deposit_amount' => 1,
        'deposit_hold_txn_id' => $this->request->deposit_hold_txn_id, 'deposit_acceptance_id' => $this->request->deposit_acceptance_id,
        'seller_reply_deadline' => now(),
    ])))->toBe('DH005');

    // Released without a refund: refused at commit (a second buyer keeps the listing reserved,
    // so only the money check can fire).
    BuyRequests::queued($this, BuyRequests::funded('20000'), $this->listing);
    expect(brSqlState(fn () => DB::table('buy_request')->where('buy_request_id', $id)->update(['state' => 'released_declined'])))->toBe('DH005');

    // accepted -> queued is not a move.
    expect(brSqlState(function () use ($id) {
        DB::table('buy_request')->where('buy_request_id', $id)->update(['state' => 'accepted']);
        DB::table('buy_request')->where('buy_request_id', $id)->update(['state' => 'queued']);
    }))->toBe('DH005');
});

it('freezes the locked figures and never deletes a request', function (string $column, mixed $value) {
    expect(brSqlState(fn () => DB::table('buy_request')->where('buy_request_id', $this->request->buy_request_id)->update([$column => $value])))->toBe('DH005');
})->with([
    'price' => ['locked_total_price', '1.0000'],
    'deposit' => ['deposit_amount', '1.0000'],
    'position' => ['queue_position', 42],
    'deadline' => ['seller_reply_deadline', '2030-01-01 00:00:00+02'],
    'resolved_at by hand' => ['resolved_at', '2030-01-01 00:00:00+02'],
]);

it('refuses a delete', function () {
    expect(brSqlState(fn () => DB::table('buy_request')->where('buy_request_id', $this->request->buy_request_id)->delete()))->toBe('DH005');
});

it('keeps one active request per buyer and listing, and one position per place', function () {
    $again = fn () => DB::table('buy_request')->insert([
        'listing_id' => $this->listing->listing_id, 'buyer_id' => $this->buyer->customer_id, 'state' => 'queued',
        'queue_position' => 2, 'locked_total_price' => 1, 'deposit_amount' => 1,
        'deposit_hold_txn_id' => $this->request->deposit_hold_txn_id, 'deposit_acceptance_id' => $this->request->deposit_acceptance_id,
        'seller_reply_deadline' => now(),
    ]);

    expect(brSqlState($again))->toBe('23505');
});

it('refuses a second hold or a second release for one request', function () {
    $second = fn () => DB::table('ledger_transaction')->insert([
        'event_kind' => 'deposit_hold', 'customer_id' => $this->buyer->customer_id, 'buy_request_id' => $this->request->buy_request_id,
    ]);

    expect(brSqlState($second))->toBe('23505');
});

it('refuses a release while the request is still queued', function () {
    $early = function () {
        app(DepositLedger::class)->release($this->request->fresh(), null, SystemActor::id());
    };

    expect(brSqlState($early))->toBe('DH005');
});

it('keeps the queue count in step and never changes the listing state itself', function () {
    $other = BuyRequests::funded('20000');
    BuyRequests::queued($this, $other, $this->listing);

    $listing = $this->listing->fresh();
    expect($listing->active_queue_count)->toBe(2)
        ->and($listing->state->value)->toBe('reserved')
        ->and(DB::selectOne("SELECT pg_get_functiondef('sync_listing_queue'::regproc) AS def")->def)->not->toContain('SET state');
});

it('guards the order: branch among the options, moves along order_transition, the cancel shape', function () {
    BuyRequests::accept($this, $this->seller, $this->listing, $this->request, BuyRequests::branchOf($this->listing))->assertCreated();
    $order = DB::table('order')->first();
    $foreign = Branch::factory()->create()->branch_id;

    expect(brSqlState(fn () => DB::table('order')->where('order_id', $order->order_id)->update(['branch_id' => $foreign])))->toBe('DH005')
        ->and(brSqlState(fn () => DB::table('order')->where('order_id', $order->order_id)->update(['state' => 'completed'])))->toBe('DH005')
        ->and(brSqlState(fn () => DB::table('order')->where('order_id', $order->order_id)->update(['state' => 'cancelled_staff'])))->toBe('23514')
        ->and(brSqlState(fn () => DB::table('order')->where('order_id', $order->order_id)->delete()))->toBe('DH005')
        ->and(brSqlState(fn () => DB::table('order')->where('order_id', $order->order_id)->update(['order_ref' => 'DH-1999-000001'])))->toBe('DH005');

    // Cancelling without refunding the buyer: refused at commit.
    expect(brSqlState(fn () => DB::table('order')->where('order_id', $order->order_id)->update([
        'state' => 'cancelled_staff', 'cancelled_by' => SystemActor::id(), 'cancelled_at' => now(), 'cancel_reason' => 'Staff cancelled for testing only.',
    ])))->toBe('DH005');
});

it('refuses accepted -> live without the staff reason in the history', function () {
    BuyRequests::accept($this, $this->seller, $this->listing, $this->request, BuyRequests::branchOf($this->listing))->assertCreated();

    expect(brSqlState(fn () => DB::table('listing_state_change')->insert([
        'listing_id' => $this->listing->listing_id, 'from_state' => 'accepted', 'to_state' => 'live', 'actor_staff_id' => SystemActor::id(),
    ])))->toBe('23514');
});

it('has the allowed moves the spec lists', function () {
    $moves = DB::table('buy_request_transition')->get()->map(fn ($r) => "{$r->from_state}>{$r->to_state}")->sort()->values()->all();

    expect($moves)->toBe([
        'queued>accepted', 'queued>released_declined', 'queued>released_expired', 'queued>released_not_chosen', 'queued>withdrawn_by_buyer',
    ])->and(DB::table('listing_transition')->where('from_state', 'accepted')->pluck('to_state')->sort()->values()->all())
        ->toBe(['at_inspection', 'awaiting_seller_return', 'live', 'withdrawn'])
        ->and(DB::table('order_transition')->where('to_state', 'cancelled_staff')->pluck('from_state')->all())->toBe(['awaiting_delivery']);
});

it('refuses to roll back while requests exist', function () {
    $migration = require base_path('database/migrations/2026_10_04_000010_create_buy_requests.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Refusing to roll back spec 011');
    expect(BuyRequest::query()->count())->toBe(1);
});
