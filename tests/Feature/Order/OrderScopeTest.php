<?php

use App\Models\Customer;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 research R2, analysis C1/C2: the `order` scope is narrower than any
// elevation. A party reads the counterparty's request and display reference
// for their own order only; the buyer may move that listing only to sold /
// awaiting_seller_return; nobody else sees anything. It is pushed only by the
// customer order Actions, every write under it is audited, and no customer
// path elevates.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
    $this->stranger = BuyRequests::funded('1000');
});

/** Run `$work` in the order scope as `$customer`, in a savepoint. */
function inOrderAs(Customer $customer, Closure $work): mixed
{
    DatabaseActor::push('customer', $customer->customer_id);

    try {
        return DatabaseActor::order(fn () => DB::transaction($work));
    } finally {
        DatabaseActor::pop();
    }
}

it('is not an elevation', function () {
    expect(in_array('order', DatabaseActor::ELEVATED, true))->toBeFalse()
        ->and(DB::selectOne("SELECT dahab_queue_read_scope('order') AS s")->s)->toBe('queue');
});

it('lets a party read the counterparty\'s request and display reference, and nobody else', function () {
    $read = fn (Customer $c) => inOrderAs($c, fn () => [
        'request' => DB::table('buy_request')->where('buy_request_id', $this->order->buy_request_id)->count(),
        'counterparty' => DB::table('customer')->whereIn('customer_id', [$this->order->buyer_id, $this->order->seller_id])->count(),
        'listing' => DB::table('listing')->where('listing_id', $this->order->listing_id)->count(),
        'order' => DB::table('order')->count(),
    ]);

    expect($read($this->seller))->toBe(['request' => 1, 'counterparty' => 2, 'listing' => 1, 'order' => 1])
        ->and($read($this->buyer))->toBe(['request' => 1, 'counterparty' => 2, 'listing' => 1, 'order' => 1])
        ->and($read($this->stranger))->toBe(['request' => 0, 'counterparty' => 0, 'listing' => 0, 'order' => 0]);
});

it('lets the buyer move the listing only to sold or awaiting_seller_return, and only its state', function () {
    // Not to another state (the policy's WITH CHECK).
    expect(fn () => inOrderAs($this->buyer, fn () => DB::table('listing')
        ->where('listing_id', $this->order->listing_id)->update(['state' => 'live'])))
        ->toThrow(QueryException::class);

    // A buyer cannot change anything but the state of the seller's listing.
    expect(fn () => inOrderAs($this->buyer, fn () => DB::table('listing')
        ->where('listing_id', $this->order->listing_id)->update(['making_charge_per_g' => '1'])))
        ->toThrow(QueryException::class);

    // A stranger reaches nothing.
    expect(inOrderAs($this->stranger, fn () => DB::table('listing')->where('listing_id', $this->order->listing_id)
        ->update(['state_changed_at' => now()])))->toBe(0);
});

it('sees no other customer table', function () {
    $seen = inOrderAs($this->buyer, fn () => [
        'identity' => DB::table('identity_document')->where('customer_id', $this->order->seller_id)->count(),
        'topups' => DB::table('topup')->where('customer_id', $this->order->seller_id)->count(),
    ]);

    expect($seen)->toBe(['identity' => 0, 'topups' => 0]);
});

it('is pushed only by the customer order Actions, which never elevate and always audit their writes', function () {
    $files = collect(File::allFiles(app_path()))
        ->map(fn ($f) => [str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1)), (string) file_get_contents($f->getPathname())]);

    $pushers = $files->filter(fn ($f) => str_contains($f[1], 'DatabaseActor::order('))->map(fn ($f) => $f[0])->values()->all();
    foreach ($pushers as $path) {
        expect(str_starts_with($path, 'app/Actions/Orders/Customer/'))->toBeTrue("{$path} pushes the order scope");
    }

    foreach ($files->filter(fn ($f) => str_starts_with($f[0], 'app/Actions/Orders/Customer/')) as [$path, $code]) {
        expect(str_contains($code, 'DatabaseActor::elevate('))->toBeFalse("{$path} elevates in a customer path");
        if (str_contains($code, 'DatabaseActor::order(fn () => DB::transaction')) {
            expect(str_contains($code, 'AuditEvent::ORDER_'))->toBeTrue("{$path} writes under the order scope without an audit row");
        }
    }
});

it('commits a party\'s move with the outer frame in the plain customer scope', function () {
    $response = Orders::cancel($this, $this->seller, $this->order)->assertOk();

    expect($response->json('data.state'))->toBe('cancelled_seller')
        ->and(Order::query()->find($this->order->order_id)->state->value)->toBe('cancelled_seller');
});
