<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InspectionResult;
use App\Models\Listing;
use App\Models\SettlementDecision;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-013, research R14–R15: an adjustment unanswered for
// deadline.buyer_pay_days counts as a decline — by the sweep, as the system
// actor, with no decision row and no forfeiture.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '9.700');
    $this->buyer = Orders::buyer($this->order);
});

it('declines an unanswered adjustment as the system actor, refunding in full', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $this->travelTo($this->order->refresh()->decision_due_deadline->addMinute());

    Orders::sweep();

    $order = $this->order->refresh();
    expect($order->state->value)->toBe('cancelled_inspection')
        ->and(SettlementDecision::query()->count())->toBe(0)
        ->and($order->stateChanges()->reorder()->latest('change_id')->first()->note)->toBe('no_answer')
        ->and(BuyRequests::balances($this->buyer))->toBe(['available' => '60000.0000', 'held' => '0.0000'])
        ->and(Listing::query()->find($order->listing_id)->state->value)->toBe('awaiting_seller_return')
        ->and(AuditLog::query()->where('action', 'order.decided')->sole()->actor_staff_id)->toBe(SystemActor::id());

    Orders::show($this, $this->buyer, $order)->assertOk()->assertJsonPath('data.cancel.reason_kind', 'no_answer');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id && $job->notification->event === OrderEvent::DECISION_EXPIRED);
});

it('never sweeps a regrade still waiting for its price, nor an answered one', function () {
    $seller = Customer::factory()->verified()->create();
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $seller->customer_id]);
    $regrade = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $regrade)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $regrade, ['measured_stone_grade' => 'VS2 G', 'stone_below_claim' => true])->assertCreated();

    $result = InspectionResult::query()->where('order_id', $this->order->order_id)->sole();
    Listings::as($this, $this->buyer)->postJson(Orders::CUSTOMER_URL."/{$this->order->order_id}/decision",
        ['accept' => true, 'inspection_id' => $result->inspection_id], Listings::key())->assertOk();

    $this->travel(30)->days();
    Orders::sweep();

    expect($regrade->refresh()->state->value)->toBe('weight_adjust_pending')
        ->and($this->order->refresh()->state->value)->not->toBe('cancelled_inspection');
});
