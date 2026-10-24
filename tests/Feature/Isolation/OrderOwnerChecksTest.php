<?php

use App\Actions\Disputes\Customer\OpenDisputeAction;
use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Actions\Orders\Customer\DecideAdjustmentAction;
use App\Actions\Orders\Customer\FreeRelistAction;
use App\Actions\Orders\Customer\ListOwnOrdersAction;
use App\Actions\Orders\Customer\NameProxyAction;
use App\Actions\Orders\Customer\PayBalanceAction;
use App\Actions\Orders\Customer\RateOrderAction;
use App\Actions\Orders\Customer\RelistReturnedPieceAction;
use App\Actions\Orders\Customer\RemoveProxyAction;
use App\Actions\Orders\Customer\RequestMoreTimeAction;
use App\Enums\DisputeReason;
use App\Enums\ExtensionRequestReason;
use App\Enums\IdentityDocumentKind;
use App\Models\Customer;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 003 FR-002 defence in depth: if the database role ever bypassed row security, every
// order would be returned. Each customer order read and action must still refuse a
// stranger on its own. The elevated scope below sees every row, as such a role would.
// (Over HTTP, with row security working, the stranger cases are in CustomerOrdersReadTest,
// OrderLeakTest, PayBalanceTest, SellerCancelTest and RateOrderTest.)

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->stranger = Customer::factory()->verified()->create();
});

it('lists and opens only the caller\'s orders, collection code included', function () {
    $list = app(ListOwnOrdersAction::class);

    DatabaseActor::elevate('maintenance', function () use ($list) {
        expect(Order::query()->count())->toBe(1) // the scope really sees the order
            ->and($list->handle($this->stranger->customer_id, null, null, null, 100)['rows'])->toBeEmpty()
            ->and(fn () => $list->show($this->stranger->customer_id, $this->order->order_id))->toThrow(ModelNotFoundException::class);
    });
});

it('refuses a stranger every order action and leaves the order as it was', function (Closure $act) {
    $before = $this->order->fresh()->state;

    DatabaseActor::elevate('maintenance', fn () => expect(fn () => $act($this->stranger, $this->order->order_id))
        ->toThrow(ModelNotFoundException::class));

    expect($this->order->fresh()->state)->toBe($before);
})->with([
    'cancel' => [fn (Customer $c, string $id) => app(CancelOrderBySellerAction::class)->bySeller($c, $id)],
    'decide a new price' => [fn (Customer $c, string $id) => app(DecideAdjustmentAction::class)->byBuyer($c, $id, true, '01a0df6f-0000-7000-8000-000000000001')],
    'pay the balance' => [fn (Customer $c, string $id) => app(PayBalanceAction::class)->handle($c, $id)],
    'relist a returned piece' => [fn (Customer $c, string $id) => app(RelistReturnedPieceAction::class)->handle($c, $id)],
    'free relist' => [fn (Customer $c, string $id) => app(FreeRelistAction::class)->handle($c, $id, [])],
    'rate' => [fn (Customer $c, string $id) => app(RateOrderAction::class)->handle($c, $id, 5, null)],
    'ask for more time' => [fn (Customer $c, string $id) => app(RequestMoreTimeAction::class)->handle($c, $id, ExtensionRequestReason::TRAVELLING, 'away')],
    'name a collector' => [fn (Customer $c, string $id) => app(NameProxyAction::class)->handle($c, $id, 'Someone', '+201000000000', IdentityDocumentKind::PASSPORT, 'token', null, 1)],
    'remove the collector' => [fn (Customer $c, string $id) => app(RemoveProxyAction::class)->handle($c, $id)],
    'report a problem' => [fn (Customer $c, string $id) => app(OpenDisputeAction::class)->handle($c, $id, DisputeReason::OTHER, 'detail', [])],
]);
