<?php

use App\Enums\AccountKind;
use App\Enums\OrderEvent;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\SellerReturn;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US7, FR-018, research R13; Part 3 §10.1: past the balance deadline
// the sweep forfeits the deposit — the seller's share half-up to the piastre,
// the rest to Dahab — and the piece goes back to the seller with a code.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
});

it('forfeits the deposit 50/50 and returns the piece with a code', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $sellerBefore = BuyRequests::balances($this->seller)['available'];
    $this->travelTo($this->order->balance_due_deadline->addMinute());

    Orders::sweep();

    $order = $this->order->refresh();
    $return = SellerReturn::query()->where('order_id', $order->order_id)->sole();
    expect($order->state->value)->toBe('cancelled_buyer_nopay')
        ->and($order->forfeit_txn_id)->not->toBeNull()
        ->and($order->release_txn_id)->toBeNull()
        ->and(BuyRequests::balances($this->buyer))->toBe(['available' => '48873.7500', 'held' => '0.0000'])
        ->and(bcsub(BuyRequests::balances($this->seller)['available'], $sellerBefore, 4))->toBe('5563.1300')
        ->and(Orders::internal(AccountKind::DAHAB_COMMISSION))->toBe('5563.1200')
        ->and(Listing::query()->find($order->listing_id)->state->value)->toBe('awaiting_seller_return')
        ->and($return->compensation_txn_id)->toBe($order->forfeit_txn_id)
        ->and($return->return_deadline->toDateString())->toBe(now()->setTimezone('Africa/Cairo')->addWeeks(3)->toDateString())
        ->and(AuditLog::query()->where('action', 'order.forfeited')->sole()->actor_staff_id)->toBe(SystemActor::id());

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id
        && $job->notification->event === OrderEvent::RETURN_WAITING && $job->notification->amount === '5563.1300');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id && $job->notification->event === OrderEvent::FORFEITED);

    Orders::show($this, $this->seller, $order)->assertOk()
        ->assertJsonPath('data.cancel.reason_kind', 'no_pay')
        ->assertJsonPath('data.seller_return.can_relist', true)
        ->assertJsonPath('data.actions', ['relist']);
    expect(Orders::show($this, $this->seller, $order)->json('data.return_code'))->toMatch('/^\d{6}$/');
});

it('reads the seller share live', function () {
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'deposit.seller_forfeit_share_pct')->update(['value_numeric' => 40]));
    $sellerBefore = BuyRequests::balances($this->seller)['available'];
    $this->travelTo($this->order->balance_due_deadline->addMinute());

    Orders::sweep();

    // 40% of 11,126.25 = 4,450.50; Dahab 6,675.75.
    expect(bcsub(BuyRequests::balances($this->seller)['available'], $sellerBefore, 4))->toBe('4450.5000')
        ->and(Orders::internal(AccountKind::DAHAB_COMMISSION))->toBe('6675.7500');
});

it('runs once: a second sweep changes nothing, and a payment in time leaves nothing to forfeit', function () {
    $this->travelTo($this->order->balance_due_deadline->addMinute());
    Orders::sweep();
    Orders::sweep();

    expect(count(Orders::lines($this->order, 'deposit_forfeit')))->toBe(3);

    $paid = Orders::inspected($this, Orders::accepted($this), '10.000');
    Orders::pay($this, Orders::buyer($paid), $paid)->assertOk();
    $this->travelTo($paid->refresh()->balance_due_deadline->addDay());
    Orders::sweep();

    expect($paid->refresh()->state->value)->toBe('ready_to_collect');
});
