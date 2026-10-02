<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\InspectionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-012a, research R9; Part 3 §7.3: a correction is a new row that
// supersedes the latest result, allowed only before any decision or payment;
// the outcome is re-derived and its effects re-run. Nothing is edited.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->first = InspectionResult::query()->where('order_id', $this->order->order_id)->sole();
});

function correct($test, $order, string $supersedes, array $body)
{
    Orders::staff($test, SeedRole::IGI_BRANCH);

    return Orders::result($test, $order, $body + ['supersedes_id' => $supersedes]);
}

it('turns a pass into a weight adjustment', function () {
    correct($this, $this->order, $this->first->inspection_id, ['measured_karat' => 21, 'measured_weight_g' => '9.500'])
        ->assertCreated()->assertJsonPath('data.inspection.outcome', 'weight_adjust')
        ->assertJsonPath('data.order_state', 'weight_adjust_pending');

    $order = $this->order->refresh();
    expect($order->latestInspection()->inspection_id)->not->toBe($this->first->inspection_id)
        ->and($order->stateChanges()->reorder()->latest('change_id')->first()->note)->toBe('corrected_result');
});

it('turns a pass into a karat cancel, refunding and suspending', function () {
    correct($this, $this->order, $this->first->inspection_id, ['measured_karat' => 18, 'measured_weight_g' => '10.000'])
        ->assertCreated()->assertJsonPath('data.order_state', 'cancelled_inspection');

    expect(BuyRequests::balances(Orders::buyer($this->order))['held'])->toBe('0.0000')
        ->and(Customer::query()->find($this->order->seller_id)->status->value)->toBe('suspended');
});

it('keeps the state and the deadline when a pass is corrected to another pass', function () {
    $deadline = $this->order->balance_due_deadline;
    $this->travel(1)->hours();

    correct($this, $this->order, $this->first->inspection_id, ['measured_karat' => 21, 'measured_weight_g' => '9.950'])
        ->assertCreated()->assertJsonPath('data.order_state', 'awaiting_balance');

    expect($this->order->refresh()->balance_due_deadline->equalTo($deadline))->toBeTrue()
        ->and($this->first->refresh()->measured_weight_g)->toBe('10.000');
});

it('refuses a correction of a result that is not the latest, or after payment', function () {
    $second = correct($this, $this->order, $this->first->inspection_id, ['measured_karat' => 21, 'measured_weight_g' => '9.950'])->assertCreated();

    correct($this, $this->order, $this->first->inspection_id, ['measured_karat' => 21, 'measured_weight_g' => '9.900'])
        ->assertStatus(409)->assertJsonPath('code', 'inspection_correction_not_allowed');

    Orders::pay($this, Orders::buyer($this->order), $this->order)->assertOk();
    correct($this, $this->order, $second->json('data.inspection.inspection_id'), ['measured_karat' => 21, 'measured_weight_g' => '9.900'])
        ->assertStatus(409)->assertJsonPath('code', 'inspection_correction_not_allowed');
});
