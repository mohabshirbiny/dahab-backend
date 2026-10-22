<?php

use App\Enums\SeedRole;
use App\Models\BuyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Ledger;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// A buyer who owes the balance, or an answer to a new price, on an order won
// before settles it before asking for another piece: `balance_due_first`,
// nothing recorded and nothing held.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->other = Orders::ring();
});

function depositHolds(): int
{
    return DB::table('ledger_transaction')->where('event_kind', 'deposit_hold')->count();
}

it('refuses a new request while an order waits for the balance or a price answer', function (string $weight, string $state) {
    $order = Orders::inspected($this, Orders::accepted($this, funds: '120000'), $weight);
    expect($order->state->value)->toBe($state);
    $requests = BuyRequest::query()->count();
    $holds = depositHolds();

    BuyRequests::send($this, Orders::buyer($order), $this->other)->assertStatus(409)
        ->assertJsonPath('code', 'balance_due_first')
        ->assertJsonPath('details.order_id', $order->order_id)
        ->assertJsonPath('details.order_ref', $order->order_ref)
        ->assertJsonPath('details.order_state', $state);

    expect(BuyRequest::query()->count())->toBe($requests)
        ->and(depositHolds())->toBe($holds)
        ->and($this->other->fresh()->state->value)->toBe('live');
})->with([
    'waiting for the balance' => ['10.000', 'awaiting_balance'],
    'waiting for a price answer' => ['9.700', 'weight_adjust_pending'],
]);

it('lets the buyer ask again once the balance is paid', function () {
    $order = Orders::inspected($this, Orders::accepted($this, funds: '120000'), '10.000');
    $buyer = Orders::buyer($order);
    Orders::pay($this, $buyer, $order)->assertOk();

    BuyRequests::send($this, $buyer, $this->other)->assertCreated();
});

it('does not stop a buyer whose order needs nothing from them yet', function () {
    $waiting = Orders::accepted($this, funds: '120000');
    BuyRequests::send($this, Orders::buyer($waiting), $this->other)->assertCreated();

    $atInspection = Orders::accepted($this, funds: '120000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $atInspection)->assertOk();
    expect($atInspection->refresh()->state->value)->toBe('at_inspection');
    BuyRequests::send($this, Orders::buyer($atInspection), $this->other)->assertCreated();
});

it('does not stop the seller of an order that waits for the balance', function () {
    $order = Orders::inspected($this, Orders::accepted($this, funds: '120000'), '10.000');
    $seller = Orders::seller($order);
    Ledger::topUp($seller, '60000');

    BuyRequests::send($this, $seller, $this->other)->assertCreated();
});
