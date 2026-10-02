<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InspectionResult;
use App\Models\Listing;
use App\Models\SellerReturn;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US4, FR-011, FR-012, research R9; Part 3 §7: the inspector records
// what IGI measured; the server derives the outcome and applies its effects.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();
    $this->inspector = Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);
});

it('passes within tolerance: awaiting the balance in 10 calendar days, figures on the measured weight', function () {
    Bus::fake([NotifyCustomerJob::class]);

    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '9.900', 'certificate_number' => 'IGI-EG-1'])
        ->assertCreated()
        ->assertJsonPath('data.inspection.outcome', 'pass')
        ->assertJsonPath('data.inspection.weight_diff_pct', '-1.0000')
        ->assertJsonPath('data.inspection.karat_mismatch', false)
        ->assertJsonPath('data.inspection.stated_weight_g', '10.000')
        ->assertJsonPath('data.order_state', 'awaiting_balance');

    $order = $this->order->refresh();
    expect($order->balance_due_deadline->toDateString())->toBe(now()->setTimezone('Africa/Cairo')->addDays(10)->toDateString())
        ->and(Listing::query()->find($order->listing_id)->state->value)->toBe('settling')
        ->and($order->stateChanges()->pluck('to_state')->map->value->all())
        ->toBe(['awaiting_delivery', 'at_inspection', 'inspection_passed', 'awaiting_balance'])
        ->and(AuditLog::query()->where('action', 'inspection.result_recorded')->sole()->actor_staff_id)->toBe($this->inspector->staff_id);

    Orders::show($this, $this->buyer, $order)->assertOk()
        ->assertJsonPath('data.stage', 'pay')
        ->assertJsonPath('data.amount_due', '43948.6875')
        ->assertJsonPath('data.final_total', '55074.9375')
        ->assertJsonPath('data.actions', ['pay']);

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->buyer->customer_id
        && $job->notification->event === OrderEvent::RESULT_PASSED && $job->notification->amount === '55074.9375');
});

it('asks the buyer on a weight outside tolerance, at the price on the measured weight', function () {
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '9.700'])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'weight_adjust')
        ->assertJsonPath('data.order_state', 'weight_adjust_pending');

    $order = $this->order->refresh();
    expect($order->decision_due_deadline)->not->toBeNull();

    // 5,263.125 × 9.7 + 300 × 9.7 = 51,052.3125 + 2,910 = 53,962.3125
    Orders::show($this, $this->buyer, $order)->assertOk()
        ->assertJsonPath('data.stage', 'decide')
        ->assertJsonPath('data.inspection.new_price', '53962.3125')
        ->assertJsonPath('data.inspection.decision_needed', true)
        ->assertJsonPath('data.actions', ['decide']);
});

it('cancels on any karat difference: the buyer refunded, the seller suspended, the piece returned', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $other = Orders::ring(Orders::seller($this->order));

    Orders::result($this, $this->order, ['measured_karat' => 18, 'measured_weight_g' => '10.000', 'is_counterfeit' => true])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'karat_cancel')
        ->assertJsonPath('data.inspection.karat_mismatch', true)
        ->assertJsonPath('data.order_state', 'cancelled_inspection');

    $seller = Customer::query()->find($this->seller->customer_id);
    $return = SellerReturn::query()->where('order_id', $this->order->order_id)->sole();
    expect(BuyRequests::balances($this->buyer))->toBe(['available' => '60000.0000', 'held' => '0.0000'])
        ->and($seller->status->value)->toBe('suspended')
        ->and($seller->suspended_reason->value)->toBe('piece_misrepresented')
        ->and($seller->suspended_by)->toBe($this->inspector->staff_id)
        ->and(Listing::query()->find($other->listing_id)->state->value)->toBe('suspended_hold')
        ->and(Listing::query()->find($this->order->listing_id)->state->value)->toBe('awaiting_seller_return')
        ->and($return->compensation_txn_id)->toBeNull();

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id
        && $job->notification->event === OrderEvent::RETURN_WAITING && strlen((string) $job->notification->code) === 6);
});

it('cancels a counterfeit with the right karat as fake_cancel', function () {
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '10.000', 'is_counterfeit' => true])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'fake_cancel');
});

it('waits for staff to price a stone regrade', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $order = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);

    Orders::result($this, $order, ['measured_stone_grade' => 'VS2 G', 'stone_below_claim' => true])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'stone_regrade')
        ->assertJsonPath('data.order_state', 'weight_adjust_pending');

    Orders::show($this, Orders::buyer($order), $order->refresh())->assertOk()
        ->assertJsonPath('data.inspection.price_pending', true)
        ->assertJsonPath('data.actions', []);
});

it('needs the measured karat and weight for gold, and never takes an outcome from the client', function () {
    Orders::result($this, $this->order, ['measured_karat' => 21])->assertStatus(422);
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '10.000', 'outcome' => 'karat_cancel'])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'pass');
});

it('refuses another branch (audited), the wrong state, and replays a key once', function () {
    $staff = Orders::staff($this, SeedRole::IGI_BRANCH, Branch::factory()->create()->branch_id);
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '10.000'])
        ->assertForbidden()->assertJsonPath('code', 'wrong_branch');
    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->exists())->toBeTrue();

    Orders::staff($this, SeedRole::IGI_BRANCH);
    $key = (string) Str::uuid();
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '10.000'], $key)->assertCreated();
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '10.000'], $key)->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');
    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '10.000'])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');

    expect(InspectionResult::query()->count())->toBe(1);
});

it('reads the weight tolerance live', function () {
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'inspection.weight_tolerance_pct')->update(['value_numeric' => 5]));

    Orders::result($this, $this->order, ['measured_karat' => 21, 'measured_weight_g' => '9.700'])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'pass');
});
