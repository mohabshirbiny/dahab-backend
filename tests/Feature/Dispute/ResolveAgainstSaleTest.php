<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\SellerReturn;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-013, FR-014, Clarification Q2: against the sale only before
// payment — cancelled at inspection, the deposit back in full, the piece
// returned; needs order.refund.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('cancels before payment: the deposit back to the buyer by the resolver, the piece returned', function (string $from) {
    Bus::fake([NotifyCustomerJob::class]);
    $order = match ($from) {
        'at_inspection' => Disputes::atInspection($this),
        'weight_adjust_pending' => Disputes::deciding($this),
        'awaiting_balance' => Disputes::awaitingBalance($this),
    };
    $buyer = Orders::buyer($order);
    $deposit = bcadd((string) $order->buyRequest->deposit_amount, '0', 4);
    $availableBefore = BuyRequests::balances($buyer)['available'];
    $dispute = Disputes::opened($this, $order);

    $finance = Orders::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $dispute, ['outcome' => 'against_sale', 'reply' => 'The piece did not match; your deposit is back.'])
        ->assertOk()->assertJsonPath('data.outcome', 'against_sale')->assertJsonPath('data.order.state', 'cancelled_inspection');

    $order->refresh();
    $lines = Orders::lines($order, 'deposit_release');
    expect($order->state->value)->toBe('cancelled_inspection')
        ->and(BuyRequests::balances($buyer)['available'])->toBe(bcadd($availableBefore, $deposit, 4))
        ->and($lines)->toHaveCount(2)
        ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')->where('order_id', $order->order_id)
            ->where('event_kind', 'deposit_release')->value('staff_id')))->toBe($finance->staff_id)
        ->and(Disputes::of($order)->release_txn_id)->toBe($order->release_txn_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => SellerReturn::query()->where('order_id', $order->order_id)->sole()->compensation_txn_id))->toBeNull()
        ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('listing')->where('listing_id', $order->listing_id)->value('state')))->toBe('awaiting_seller_return');

    Orders::show($this, $buyer, $order)->assertJsonPath('data.cancel.reason_kind', 'dispute')
        ->assertJsonPath('data.dispute_outcome', 'cancelled');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $order->seller_id && $j->notification->event === OrderEvent::DISPUTE_CANCELLED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $order->seller_id && $j->notification->event === OrderEvent::RETURN_WAITING);
})->with(['at_inspection', 'weight_adjust_pending', 'awaiting_balance']);

it('refuses against the sale on a paid order, changing nothing', function () {
    $order = Disputes::readyToCollect($this);
    $dispute = Disputes::opened($this, $order);
    Orders::staff($this, SeedRole::FINANCE);

    Disputes::resolve($this, $dispute, ['outcome' => 'against_sale'])->assertStatus(409)->assertJsonPath('code', 'dispute_outcome_not_allowed');
    expect($order->refresh()->state->value)->toBe('disputed')
        ->and(Disputes::of($order)->state->value)->toBe('open');
});

it('needs order.refund on top of dispute.handle', function (SeedRole $role) {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    Orders::staff($this, $role);

    Disputes::resolve($this, $dispute, ['outcome' => 'against_sale'])->assertForbidden()->assertJsonPath('code', 'permission_denied');
    expect($order->refresh()->state->value)->toBe('disputed');
})->with([SeedRole::COO, SeedRole::OPERATIONS]);
