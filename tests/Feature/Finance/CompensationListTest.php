<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Finance;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 015 FR-001–FR-003: every compensation paid, newest first, with the
// period and month totals, the caps and what the viewer has left today; the
// filtered CSV export, audited.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

const COMP_URL = '/api/v1/dashboard/compensation';

it('lists every payment newest first, with the dispute and order of each, the totals and the caps', function () {
    Orders::workedPrices();
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    $finance = Finance::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $dispute, ['compensation' => ['party' => 'seller', 'amount' => '1500', 'reason' => 'igi_delay', 'note' => 'IGI kept the piece too long.']])->assertOk();

    $customer = Finance::customer('0');
    Finance::pay($this, $customer, '800')->assertCreated();

    $this->getJson(COMP_URL)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.amount', '800.0000')->assertJsonPath('data.0.dispute', null)
        ->assertJsonPath('data.1.amount', '1500.0000')->assertJsonPath('data.1.dispute.ref', Disputes::of($order)->dispute_ref)
        ->assertJsonPath('data.1.order.ref', $order->order_ref)->assertJsonPath('data.1.party', 'seller')
        ->assertJsonPath('data.1.paid_by.id', $finance->staff_id)
        ->assertJsonPath('meta.totals.period', '2300.0000')->assertJsonPath('meta.totals.this_month', '2300.0000')
        ->assertJsonPath('meta.caps.per_payment', '2000.0000')->assertJsonPath('meta.caps.per_day', '5000.0000')
        ->assertJsonPath('meta.caps.uncapped', false)->assertJsonPath('meta.caps.left_today', '2700.0000');

    Finance::staff($this, SeedRole::CEO);
    $this->getJson(COMP_URL)->assertOk()->assertJsonPath('meta.caps.uncapped', true)->assertJsonPath('meta.caps.left_today', null);
});

it('filters by period, reason, payer and customer, and pages by keyset', function () {
    $a = Finance::customer('0');
    $b = Finance::customer('0');
    $first = Finance::staff($this, SeedRole::FINANCE);
    // One paid 40 days ago, outside the default 30 days.
    $this->travelTo(now()->subDays(40));
    Finance::pay($this, $a, '100', ['reason' => 'goodwill'])->assertCreated();
    $this->travelBack();
    Finance::pay($this, $b, '200')->assertCreated();
    $second = Finance::staff($this, SeedRole::FINANCE);
    Finance::pay($this, $a, '300')->assertCreated();

    $this->getJson(COMP_URL)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.totals.period', '500.0000');
    $this->getJson(COMP_URL.'?from='.now('Africa/Cairo')->subDays(60)->toDateString())->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(COMP_URL.'?from='.now('Africa/Cairo')->subDays(60)->toDateString().'&reason=goodwill')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(COMP_URL.'?paid_by='.$first->staff_id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '200.0000');
    $this->getJson(COMP_URL.'?from='.now('Africa/Cairo')->subDays(60)->toDateString().'&paid_by='.$first->staff_id)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(COMP_URL.'?customer_id='.$a->customer_id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.paid_by.id', $second->staff_id);

    $page = $this->getJson(COMP_URL.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '300.0000');
    $this->getJson(COMP_URL.'?per_page=1&cursor='.$page->json('meta.next_cursor'))->assertOk()->assertJsonPath('data.0.amount', '200.0000');

    $this->getJson(COMP_URL.'?from=2026-01-01&to=2027-06-01')->assertStatus(422)->assertJsonValidationErrors('to');
    $this->getJson(COMP_URL.'?reason=because')->assertStatus(422)->assertJsonValidationErrors('reason');
});

it('exports the filtered rows as CSV with a BOM, formulas neutralised, audited', function () {
    $customer = Finance::customer('0', ['full_name' => '=HYPERLINK("x")']);
    $finance = Finance::staff($this, SeedRole::FINANCE);
    Finance::pay($this, $customer, '250')->assertCreated();

    $response = $this->get(COMP_URL.'/export')->assertOk()->assertHeader('X-Export-Truncated', 'false');
    $csv = (string) $response->getContent();
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('250.0000')->toContain("'=HYPERLINK");

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'compensation.list_exported')->sole());
    expect($audit->actor_staff_id)->toBe($finance->staff_id)->and($audit->after_json['rows'])->toBe(1);
});

it('opens with compensation.pay or wallet.view, never for the COO or Operations', function () {
    foreach ([SeedRole::COO, SeedRole::OPERATIONS, SeedRole::VERIFICATION, SeedRole::IGI_BRANCH] as $role) {
        Finance::staff($this, $role);
        $this->getJson(COMP_URL)->assertForbidden();
        $this->get(COMP_URL.'/export', ['Accept' => 'application/json'])->assertForbidden();
    }
    $viewer = Finance::staff($this, SeedRole::OPERATIONS);
    $viewer->givePermissionTo('wallet.view');
    $this->getJson(COMP_URL)->assertOk();
});
