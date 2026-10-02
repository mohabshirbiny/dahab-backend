<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\OrderStateChange;
use App\Models\SellerCancellation;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US2, FR-006, research R14: a missed reach-branch deadline cancels
// the order as the system actor, exactly as a seller's cancel — results read
// back through the API.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
});

it('cancels an order past its reach-branch deadline as the system actor', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $this->travelTo($this->order->reach_branch_deadline->addMinute());

    expect(Orders::sweep())->toBe(0);

    Orders::show($this, $this->buyer, $this->order)->assertOk()
        ->assertJsonPath('data.state', 'cancelled_seller')
        ->assertJsonPath('data.cancel.reason_kind', 'deadline_missed');
    expect(BuyRequests::balances($this->buyer))->toBe(['available' => '60000.0000', 'held' => '0.0000']);

    expect(SellerCancellation::query()->sole()->by_sweep)->toBeTrue()
        ->and(OrderStateChange::query()->where('to_state', 'cancelled_seller')->sole()->actor_staff_id)->toBe(SystemActor::id())
        ->and(AuditLog::query()->where('action', 'order.seller_cancelled')->sole()->actor_staff_id)->toBe(SystemActor::id());

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id && $job->notification->event === OrderEvent::DEADLINE_MISSED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id && $job->notification->event === OrderEvent::DEADLINE_MISSED);
});

it('leaves an order before its deadline, or received, alone; a second run changes nothing', function () {
    Orders::sweep();
    expect($this->order->refresh()->state->value)->toBe('awaiting_delivery');

    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();
    $this->travelTo($this->order->reach_branch_deadline->addHour());
    Orders::sweep();
    Orders::sweep();

    expect($this->order->refresh()->state->value)->toBe('at_inspection')
        ->and(SellerCancellation::query()->count())->toBe(0);
});
