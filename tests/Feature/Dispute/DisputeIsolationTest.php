<?php

use App\Enums\SeedRole;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-029, analysis C2 (Constitution II): each new row is readable by
// the customer it belongs to only — never by the other party of the order —
// and customers write only their own rows, only in the 'order' scope.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();

    $this->order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($this->order);
    Disputes::open($this, $buyer, $this->order, ['photo_tokens' => [Disputes::photoToken($this, $buyer)]])->assertCreated();
    $this->dispute = Disputes::of($this->order);
    Orders::staff($this, SeedRole::CEO);
    Disputes::resolve($this, $this->dispute, ['compensation' => ['party' => 'buyer', 'amount' => '100', 'reason' => 'goodwill', 'note' => 'For the trouble caused.']])->assertOk();

    $this->accepted = Orders::accepted($this);
    Disputes::askMoreTime($this, $this->accepted)->assertCreated();
});

/** Counts of the five tables as one customer, in the given scope. */
function disputeRowsAs(string $customerId, string $scope = 'customer'): array
{
    DatabaseActor::push($scope, customerId: $customerId);
    try {
        return collect(['dispute', 'dispute_photo', 'dispute_change', 'compensation', 'order_extension_request'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    } finally {
        DatabaseActor::pop();
    }
}

it('shows each row only to the customer it belongs to', function () {
    expect(disputeRowsAs($this->order->buyer_id))->toBe(['dispute' => 1, 'dispute_photo' => 1, 'dispute_change' => 2, 'compensation' => 1, 'order_extension_request' => 0])
        // The seller is a party of the order but sees none of the buyer's dispute.
        ->and(disputeRowsAs($this->order->seller_id))->toBe(['dispute' => 0, 'dispute_photo' => 0, 'dispute_change' => 0, 'compensation' => 0, 'order_extension_request' => 0])
        ->and(disputeRowsAs($this->accepted->seller_id))->toMatchArray(['order_extension_request' => 1, 'dispute' => 0])
        ->and(disputeRowsAs($this->accepted->buyer_id))->toMatchArray(['order_extension_request' => 0])
        ->and(disputeRowsAs($this->order->seller_id, 'order'))->toMatchArray(['dispute' => 0, 'compensation' => 0]);

    DatabaseActor::push('', null);
    try {
        expect(DB::table('dispute')->count())->toBe(0)->and(DB::table('compensation')->count())->toBe(0);
    } finally {
        DatabaseActor::pop();
    }
});

it('refuses customer writes outside the order scope, for others, and to compensation', function () {
    $buyer = $this->order->buyer_id;
    $try = function (string $scope, Closure $write) use ($buyer): bool {
        DatabaseActor::push($scope, customerId: $buyer);
        try {
            DB::transaction($write);

            return true;
        } catch (QueryException) {
            return false;
        } finally {
            DatabaseActor::pop();
        }
    };

    $compensation = fn () => DB::table('compensation')->insert([
        'compensation_id' => (string) Str::uuid(), 'dispute_id' => $this->dispute->dispute_id, 'order_id' => $this->order->order_id,
        'customer_id' => $buyer, 'party' => 'buyer', 'amount' => '1', 'reason' => 'goodwill', 'note' => 'Paying myself money.',
        'paid_by' => SystemActor::id(), 'ledger_txn_id' => (string) Str::uuid(),
    ]);
    $request = fn () => DB::table('order_extension_request')->insert([
        'request_id' => (string) Str::uuid(), 'order_id' => $this->accepted->order_id, 'seller_id' => $this->accepted->seller_id,
        'reason' => 'other', 'detail' => 'Not my order to ask about.', 'deadline_at_request' => now(),
    ]);
    $change = fn () => DB::table('dispute_change')->insert([
        'dispute_id' => $this->dispute->dispute_id, 'kind' => 'opened', 'actor_customer_id' => $buyer,
    ]);

    expect($try('order', $compensation))->toBeFalse()
        ->and($try('order', $request))->toBeFalse()
        ->and($try('customer', $change))->toBeFalse();
});

it('never leaks staff notes, staff names or the other side to customers', function () {
    $coo = Orders::staff($this, SeedRole::COO);
    $order = Disputes::readyToCollect($this);
    $dispute = Disputes::opened($this, $order, Orders::seller($order));
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::passOn($this, $dispute, ['assignee_id' => $coo->staff_id, 'note' => 'Secret staff note about the seller.'])->assertOk();

    foreach ([Orders::seller($order), Orders::buyer($order)] as $customer) {
        $json = json_encode(Orders::show($this, $customer, $order)->json());
        expect($json)->not->toContain('Secret staff note')->not->toContain($coo->full_name);
    }
    expect(json_encode(Orders::show($this, Orders::buyer($order), $order)->json()))->not->toContain($dispute->dispute_ref)
        ->not->toContain('IGI weighed it lower');
});
