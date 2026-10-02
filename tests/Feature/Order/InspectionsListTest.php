<?php

use App\Enums\SeedRole;
use App\Models\Branch;
use App\Models\InspectionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US9, FR-022, research R18: the inspection results list —
// newest first, the last 30 days by default, corrections flagged, branch and
// outcome filters; inspectors see their branch only and never money.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->passed = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->adjusted = Orders::inspected($this, Orders::accepted($this), '9.500');
});

it('lists results newest first with the order, the piece, the seller ref and the receipt', function () {
    Orders::staff($this, SeedRole::OPERATIONS);

    $res = $this->getJson('/api/v1/dashboard/inspections')->assertOk();

    expect(collect($res->json('data'))->pluck('order_ref')->all())->toBe([$this->adjusted->order_ref, $this->passed->order_ref])
        ->and($res->json('data.0.outcome'))->toBe('weight_adjust')
        ->and($res->json('data.0.measured_weight_g'))->toBe('9.500')
        ->and($res->json('data.0.received_at'))->not->toBeNull()
        ->and($res->json('data.0.seller_ref'))->not->toBeNull()
        ->and($res->json('data.0.superseded'))->toBeFalse();

    expect(collect($this->getJson('/api/v1/dashboard/inspections?outcome=pass')->assertOk()->json('data'))->pluck('order_ref')->all())
        ->toBe([$this->passed->order_ref]);
});

it('flags a corrected result', function () {
    $first = InspectionResult::query()->where('order_id', $this->passed->order_id)->sole();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $this->passed, ['measured_karat' => 21, 'measured_weight_g' => '9.950', 'supersedes_id' => $first->inspection_id])->assertCreated();

    Orders::staff($this, SeedRole::OPERATIONS);
    $rows = collect($this->getJson('/api/v1/dashboard/inspections')->assertOk()->json('data'))->keyBy('inspection_id');

    expect($rows[$first->inspection_id]['superseded'])->toBeTrue();
});

it('limits an inspector to their branch and to the date range', function () {
    $other = Branch::factory()->create();
    Orders::staff($this, SeedRole::IGI_BRANCH, $other->branch_id);
    expect($this->getJson('/api/v1/dashboard/inspections')->assertOk()->json('data'))->toBe([]);

    Orders::staff($this, SeedRole::IGI_BRANCH, $this->passed->branch_id);
    expect($this->getJson('/api/v1/dashboard/inspections')->assertOk()->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/dashboard/inspections?from=2020-01-01&to=2020-01-31')->assertOk()->json('data'))->toBe([]);

    $body = $this->getJson('/api/v1/dashboard/inspections')->getContent();
    expect($body)->not->toContain('price')->not->toContain('55631');
});

it('needs inspection.enter or order.view', function () {
    Orders::staff($this, SeedRole::VERIFICATION);
    $this->getJson('/api/v1/dashboard/inspections')->assertForbidden();

    Orders::staff($this, SeedRole::FINANCE);
    $this->getJson('/api/v1/dashboard/inspections')->assertOk();
    $this->getJson('/api/v1/dashboard/inspections?to=2020-01-01&from=2020-02-01')->assertStatus(422);
});
