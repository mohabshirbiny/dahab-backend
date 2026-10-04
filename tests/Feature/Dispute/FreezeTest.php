<?php

use App\Enums\SeedRole;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-004, research R3: a frozen order refuses every action but the
// dispute's own, with order_frozen, and nothing changes.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

/** The order's state, ledger entries and collection, to prove nothing moved. */
function frozenSnapshot($order): array
{
    return DatabaseActor::elevate('maintenance', fn () => [
        'state' => DB::table('order')->where('order_id', $order->order_id)->value('state'),
        'entries' => DB::table('ledger_transaction')->where('order_id', $order->order_id)->count(),
        'collection' => DB::table('collection')->where('order_id', $order->order_id)->first(),
    ]);
}

it('refuses the buyer\'s payment, the seller\'s cancel and every staff action on an order frozen at awaiting balance', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    $before = frozenSnapshot($order);

    // The raiser is told which dispute holds it; the other party is not.
    Orders::pay($this, Orders::buyer($order), $order)->assertStatus(409)
        ->assertJsonPath('code', 'order_frozen')->assertJsonPath('details.dispute_ref', $dispute->dispute_ref);
    $res = Orders::cancel($this, Orders::seller($order), $order)->assertStatus(409)->assertJsonPath('code', 'order_frozen');
    expect($res->json('details.dispute_ref'))->toBeNull();

    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertStatus(409)->assertJsonPath('code', 'order_frozen');
    $this->postJson(Orders::STAFF_URL."/{$order->order_id}/cancel", ['reason' => 'Testing a frozen order.', 'relist' => true], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'order_frozen');
    $this->postJson(Orders::STAFF_URL."/{$order->order_id}/extend-deadline",
        ['which' => 'balance', 'new_deadline' => now()->addDays(30)->toIso8601String(), 'reason' => 'Testing a frozen order.'], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'order_frozen')->assertJsonPath('details.dispute_ref', $dispute->dispute_ref);

    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $order, ['measured_karat' => 21, 'measured_weight_g' => '9.000'])->assertStatus(409)->assertJsonPath('code', 'order_frozen');

    expect(frozenSnapshot($order))->toEqual($before);
});

it('refuses the buyer\'s decision on an order frozen while deciding', function () {
    $order = Disputes::deciding($this);
    $inspection = $order->latestInspection();
    Disputes::opened($this, $order, Orders::seller($order));

    Listings::as($this, Orders::buyer($order))->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/decision",
        ['accept' => true, 'inspection_id' => $inspection->inspection_id], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'order_frozen');

    expect($order->refresh()->state->value)->toBe('disputed');
});

it('refuses the handover (counting no attempt) and naming a proxy on an order frozen when ready to collect', function () {
    $order = Disputes::readyToCollect($this);
    Disputes::opened($this, $order);
    $before = frozenSnapshot($order);

    Orders::staff($this, SeedRole::IGI_BRANCH);
    $this->postJson(Orders::STAFF_URL."/{$order->order_id}/handover", ['code' => '123456'], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'order_frozen');

    Disputes::nameProxy($this, $order)->assertStatus(409)->assertJsonPath('code', 'order_frozen');

    expect(frozenSnapshot($order))->toEqual($before)
        ->and(DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $order->order_id)->sole()->failed_attempts))->toBe(0);
});
