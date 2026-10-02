<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 research R2 (analysis H2): the `queue` scope is narrower than any
// elevation. It reads what a line needs, moves only the caller's own request
// or the requests on the caller's own listing, flips a listing only between
// live and reserved, and sees no other customer table. It is pushed only by
// the buy-request Actions and the seller's withdrawal.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->buyer = BuyRequests::funded('20000');
    $this->request = BuyRequests::queued($this, $this->buyer, $this->listing);
    $this->stranger = BuyRequests::funded('20000');
});

/** Run `$sql` in the queue scope as `$customer` and return the affected / counted rows. */
function inQueueAs(Customer $customer, Closure $work): mixed
{
    DatabaseActor::push('customer', $customer->customer_id);

    try {
        // A savepoint, so a refused statement does not abort the test's transaction.
        return DatabaseActor::queue(fn () => DB::transaction($work));
    } finally {
        DatabaseActor::pop();
    }
}

it('is not an elevation', function () {
    expect(in_array('queue', DatabaseActor::ELEVATED, true))->toBeFalse()
        ->and(DB::selectOne("SELECT dahab_queue_read_scope('customer') AS s")->s)->toBe('queue')
        ->and(DB::selectOne("SELECT dahab_queue_read_scope('staff') AS s")->s)->toBe('staff');
});

it('lets a stranger read a line but move nothing in it', function () {
    $result = inQueueAs($this->stranger, fn () => [
        'read' => DB::table('buy_request')->count(),
        'moved' => DB::table('buy_request')->where('buy_request_id', $this->request->buy_request_id)->update(['notify_when_free' => true]),
    ]);

    expect($result)->toBe(['read' => 1, 'moved' => 0]);
});

it("lets a stranger change nothing on another seller's listing but its state and count", function () {
    $failed = null;
    try {
        inQueueAs($this->stranger, fn () => DB::table('listing')->where('listing_id', $this->listing->listing_id)->update(['description' => str_repeat('x', 50)]));
    } catch (QueryException $e) {
        $failed = $e->errorInfo[0] ?? null;
    }

    expect($failed)->toBe('DH004')
        ->and($this->listing->fresh()->description)->not->toBe(str_repeat('x', 50));
});

it('flips a listing only between live and reserved', function () {
    $failed = null;
    try {
        inQueueAs($this->stranger, fn () => DB::table('listing')->where('listing_id', $this->listing->listing_id)->update(['state' => 'withdrawn']));
    } catch (QueryException $e) {
        $failed = $e->errorInfo[0] ?? null;
    }

    // WITH CHECK (state in live/reserved) refuses the new row.
    expect($failed)->toBe('42501');
});

it("lets the seller reach the requests on their own listing, but never the buyer's choices", function () {
    $locked = inQueueAs($this->seller, fn () => DB::table('buy_request')->where('buy_request_id', $this->request->buy_request_id)->lockForUpdate()->get()->count());

    $failed = null;
    try {
        inQueueAs($this->seller, fn () => DB::table('buy_request')->where('buy_request_id', $this->request->buy_request_id)->update(['notify_when_free' => true]));
    } catch (QueryException $e) {
        $failed = $e->errorInfo[0] ?? null;
    }

    expect($locked)->toBe(1)->and($failed)->toBe('DH005');
});

it('sees no other customer table', function () {
    $counts = inQueueAs($this->stranger, fn () => [
        'identity' => DB::table('identity_document')->count(),
        'topups' => DB::table('topup')->count(),
        'accounts of others' => DB::table('account')->where('customer_id', '!=', $this->stranger->customer_id)->count(),
        'declarations' => DB::table('listing_ownership_declaration')->count(),
        'private media' => DB::table('listing_media')->where('is_private', true)->count(),
        'orders' => DB::table('order')->count(),
        'customers without a request' => DB::table('customer')->where('customer_id', $this->seller->customer_id)->count(),
    ]);

    expect($counts)->each->toBe(0);
});

it('writes a history row only in the caller\'s own name', function () {
    $failed = null;
    try {
        inQueueAs($this->stranger, fn () => DB::table('listing_state_change')->insert([
            'listing_id' => $this->listing->listing_id, 'from_state' => 'reserved', 'to_state' => 'reserved',
            'actor_staff_id' => SystemActor::id(),
        ]));
    } catch (QueryException $e) {
        $failed = $e->errorInfo[0] ?? null;
    }

    expect($failed)->toBe('42501');
});

it('is pushed only by the buy-request Actions and the seller\'s withdrawal', function () {
    $allowed = [
        'app/Support/DatabaseActor.php',
        'app/Actions/BuyRequests/Concerns/RunsInQueue.php',
        'app/Actions/BuyRequests/ListOwnBuyRequestsAction.php',
        'app/Actions/BuyRequests/ShowQueueAction.php',
        'app/Support/BuyRequests/PlaceInLine.php',
    ];

    $callers = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => str_contains((string) file_get_contents($f->getPathname()), 'DatabaseActor::queue(')
            || str_contains((string) file_get_contents($f->getPathname()), 'RunsInQueue'))
        ->map(fn ($f) => str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1)))
        ->values()->all();

    foreach ($callers as $path) {
        expect(
            in_array($path, $allowed, true)
            || str_starts_with($path, 'app/Actions/BuyRequests/')
            || $path === 'app/Actions/Listings/WithdrawListingAction.php'
        )->toBeTrue("{$path} pushes the queue scope");
    }
});
