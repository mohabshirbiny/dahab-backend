<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InspectionResult;
use App\Models\Listing;
use App\Models\Order;
use App\Models\SellerReturn;
use App\Models\SettlementDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US5, FR-013, research R15: the buyer accepts or declines an
// adjusted price; a decline refunds in full, does not suspend the seller and
// returns the piece with no compensation.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '9.700');
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
    $this->result = InspectionResult::query()->where('order_id', $this->order->order_id)->sole();
});

function decideAdjustment($test, Customer $buyer, Order $order, bool $accept, string $inspectionId, ?string $key = null)
{
    return Listings::as($test, $buyer)->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/decision",
        ['accept' => $accept, 'inspection_id' => $inspectionId], Listings::key($key));
}

it('accepts: awaiting the balance on the new price, the decision recorded, the seller told', function () {
    Bus::fake([NotifyCustomerJob::class]);

    decideAdjustment($this, $this->buyer, $this->order, true, $this->result->inspection_id)->assertOk()
        ->assertJsonPath('data.state', 'awaiting_balance')
        ->assertJsonPath('data.amount_due', '42836.0625')
        ->assertJsonPath('data.actions', ['pay', 'report_problem']);

    $decision = SettlementDecision::query()->sole();
    expect($decision->buyer_accepted)->toBeTrue()
        ->and((string) $decision->old_price)->toBe('55631.2500')
        ->and((string) $decision->new_price)->toBe('53962.3125')
        ->and($this->order->refresh()->balance_due_deadline)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'order.decided')->sole()->actor_customer_id)->toBe($this->buyer->customer_id);

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id && $job->notification->event === OrderEvent::DECISION_ACCEPTED);
    Bus::assertNotDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id);

    Orders::pay($this, $this->buyer, $this->order)->assertOk();
    expect((string) $this->order->refresh()->final_buyer_total)->toBe('53962.3125');
});

it('declines: refunded in full, the seller not suspended, the piece returned without compensation', function () {
    Bus::fake([NotifyCustomerJob::class]);

    decideAdjustment($this, $this->buyer, $this->order, false, $this->result->inspection_id)->assertOk()
        ->assertJsonPath('data.state', 'cancelled_inspection')
        ->assertJsonPath('data.cancel.reason_kind', 'declined');

    $return = SellerReturn::query()->where('order_id', $this->order->order_id)->sole();
    expect(BuyRequests::balances($this->buyer))->toBe(['available' => '60000.0000', 'held' => '0.0000'])
        ->and(Customer::query()->find($this->seller->customer_id)->status->value)->toBe('active')
        ->and(Listing::query()->find($this->order->listing_id)->state->value)->toBe('awaiting_seller_return')
        ->and($return->compensation_txn_id)->toBeNull()
        ->and(SettlementDecision::query()->sole()->buyer_accepted)->toBeFalse();

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id && $job->notification->event === OrderEvent::DECISION_DECLINED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id && $job->notification->event === OrderEvent::RETURN_WAITING);
    Bus::assertNotDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id);
});

it('refuses a stale result, the seller, a second answer, and replays a key once', function () {
    decideAdjustment($this, $this->buyer, $this->order, true, (string) Str::uuid())->assertStatus(409)->assertJsonPath('code', 'inspection_correction_not_allowed');
    decideAdjustment($this, $this->seller, $this->order, true, $this->result->inspection_id)->assertNotFound();

    $key = (string) Str::uuid();
    decideAdjustment($this, $this->buyer, $this->order, true, $this->result->inspection_id, $key)->assertOk();
    decideAdjustment($this, $this->buyer, $this->order, true, $this->result->inspection_id, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    decideAdjustment($this, $this->buyer, $this->order, false, $this->result->inspection_id)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');

    expect(SettlementDecision::query()->count())->toBe(1);
});

it('lets a suspended buyer decide', function () {
    Orders::staff($this, SeedRole::COO);
    $this->postJson("/api/v1/dashboard/customers/{$this->buyer->customer_id}/suspend",
        ['reason' => 'other', 'note' => 'Testing the wind-down of open orders.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    decideAdjustment($this, $this->buyer, $this->order, true, $this->result->inspection_id)->assertOk();
});

it('waits for staff on a regrade, then settles on the accepted proposed price', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $order = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $resultId = Orders::result($this, $order, ['measured_stone_grade' => 'VS2 G', 'stone_below_claim' => true])->json('data.inspection.inspection_id');
    $buyer = Orders::buyer($order);

    decideAdjustment($this, $buyer, $order, true, $resultId)->assertStatus(409)->assertJsonPath('code', 'price_not_set');

    Orders::staff($this, SeedRole::OPERATIONS);
    $this->postJson(Orders::STAFF_URL."/{$order->order_id}/propose-price", ['price' => '108000', 'reason' => 'Grade VS2 per IGI result.'], Listings::key())->assertOk();

    decideAdjustment($this, $buyer, $order, true, $resultId)->assertOk()->assertJsonPath('data.amount_due', '84000.0000');
    Orders::pay($this, $buyer, $order)->assertOk();
    expect((string) $order->refresh()->final_buyer_total)->toBe('108000.0000');
});
