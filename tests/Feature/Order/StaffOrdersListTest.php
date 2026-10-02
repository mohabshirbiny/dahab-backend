<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US9, FR-022, research R18: the staff Orders page — groups and
// counts, past deadline, branch, search; the detail's history and ledger.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->waiting = Orders::accepted($this);
    $this->atIgi = Orders::accepted($this);
    $this->paying = Orders::inspected($this, Orders::accepted($this), '10.000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->atIgi)->assertOk();
});

it('lists open orders newest first with a count per group', function () {
    Orders::staff($this, SeedRole::FINANCE);

    $res = $this->getJson(Orders::STAFF_URL)->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$this->paying->order_id, $this->atIgi->order_id, $this->waiting->order_id])
        ->and($res->json('meta.counts'))->toMatchArray([
            'open' => 3, 'waiting_seller' => 1, 'at_igi' => 1, 'needs_decision' => 0,
            'waiting_balance' => 1, 'ready_to_collect' => 0, 'returns' => 0, 'past_deadline' => 0,
        ])
        ->and($res->json('data.0.group'))->toBe('waiting_balance')
        ->and($res->json('data.0.value'))->toBe('55631.2500')
        ->and($res->json('data.0.held'))->toBe('11126.2500')
        ->and($res->json('data.0.deadline.kind'))->toBe('balance')
        ->and($res->json('data.0.buyer.display_ref'))->not->toBeNull();
});

it('filters by group, past deadline, branch and search, a page at a time', function () {
    Orders::staff($this, SeedRole::OPERATIONS);

    expect(collect($this->getJson(Orders::STAFF_URL.'?group=waiting_seller')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$this->waiting->order_id]);

    $this->travelTo($this->waiting->reach_branch_deadline->addMinute());
    $past = $this->getJson(Orders::STAFF_URL.'?past_deadline=1')->assertOk();
    expect(collect($past->json('data'))->pluck('id')->all())->toContain($this->waiting->order_id)
        ->and($past->json('meta.counts.past_deadline'))->toBeGreaterThanOrEqual(1);

    expect($this->getJson(Orders::STAFF_URL.'?q='.strtolower($this->atIgi->order_ref))->assertOk()->json('data.0.id'))->toBe($this->atIgi->order_id);
    $buyerRef = DB::table('customer')->where('customer_id', $this->paying->buyer_id)->value('display_ref');
    expect(collect($this->getJson(Orders::STAFF_URL.'?q='.$buyerRef)->assertOk()->json('data'))->pluck('id')->all())->toBe([$this->paying->order_id]);

    $other = Branch::factory()->create();
    expect($this->getJson(Orders::STAFF_URL.'?branch_id='.$other->branch_id)->assertOk()->json('data'))->toBe([]);

    $first = $this->getJson(Orders::STAFF_URL.'?per_page=2')->assertOk();
    $next = $this->getJson(Orders::STAFF_URL.'?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();
    expect(count($first->json('data')))->toBe(2)->and(count($next->json('data')))->toBe(1)->and($next->json('meta.next_cursor'))->toBeNull();
});

it('shows the detail with the timeline, the ledger and what the caller may do; never a code', function () {
    $this->travelTo(now());
    Orders::pay($this, Orders::buyer($this->paying), $this->paying)->assertOk();
    $code = Orders::show($this, Orders::buyer($this->paying), $this->paying)->json('data.collection_code');
    Orders::staff($this, SeedRole::OPERATIONS);

    $res = $this->getJson(Orders::STAFF_URL."/{$this->paying->order_id}")->assertOk();

    expect(collect($res->json('data.timeline'))->pluck('event')->all())->toContain('accepted', 'received', 'result', 'paid')
        ->and(collect($res->json('data.ledger'))->pluck('event_kind')->all())->toBe(['deposit_hold', 'balance_payment'])
        ->and($res->json('data.settlement.seller_proceeds'))->toBe('54684.7500')
        ->and($res->json('data.can.receive'))->toBeFalse()
        ->and($res->json('data.can.extend'))->toBeTrue()
        ->and($res->json('data.can.handover'))->toBeFalse()
        ->and($res->getContent())->not->toContain('"'.$code.'"');
});

it('refuses staff without order.view and audits it', function () {
    $staff = Orders::staff($this, SeedRole::VERIFICATION);

    $this->getJson(Orders::STAFF_URL)->assertForbidden();
    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->exists())->toBeTrue();
});
