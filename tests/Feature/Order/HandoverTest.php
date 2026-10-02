<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Listing;
use App\Models\OrderCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US8, FR-020, research R10, R17: staff hand the paid piece to its
// buyer against the buyer's code; no money moves; wrong codes are counted,
// audited and lock the handover after five.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->buyer = Orders::buyer($this->order);
    $this->code = Orders::pay($this, $this->buyer, $this->order)->assertOk()->json('data.collection_code');
});

function handover($test, $order, string $code, ?string $key = null)
{
    return $test->postJson(Orders::STAFF_URL."/{$order->order_id}/handover", ['code' => $code], Listings::key($key));
}

it('completes the order against the right code, with no ledger entry, audited, both told', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $staff = Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);
    $entries = DB::table('ledger_transaction')->count();

    handover($this, $this->order, $this->code)->assertOk()->assertJsonPath('data.state', 'completed');

    $order = $this->order->refresh();
    $collection = OrderCollection::query()->where('order_id', $order->order_id)->sole();
    expect($order->completed_at)->not->toBeNull()
        ->and($collection->collected_at)->not->toBeNull()
        ->and($collection->handover_by)->toBe($staff->staff_id)
        ->and(DB::table('ledger_transaction')->count())->toBe($entries)
        ->and(Listing::query()->find($order->listing_id)->state->value)->toBe('sold')
        ->and(AuditLog::query()->where('action', 'order.handed_over')->sole()->actor_staff_id)->toBe($staff->staff_id);

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->buyer_id && $job->notification->event === OrderEvent::COLLECTED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->seller_id && $job->notification->event === OrderEvent::COLLECTED);

    Orders::show($this, $this->buyer, $order)->assertOk()
        ->assertJsonPath('data.stage', 'done')
        ->assertJsonPath('data.collection_code', null);
});

it('counts wrong codes, locks after five for fifteen minutes, audits each', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $wrong = $this->code === '000000' ? '111111' : '000000';

    for ($left = 4; $left >= 1; $left--) {
        handover($this, $this->order, $wrong)->assertStatus(422)
            ->assertJsonPath('code', 'invalid_collection_code')->assertJsonPath('details.attempts_left', $left);
    }
    handover($this, $this->order, $wrong)->assertStatus(429)->assertJsonPath('code', 'handover_locked');
    handover($this, $this->order, $this->code)->assertStatus(429);

    expect(AuditLog::query()->where('action', 'order.handover_failed')->count())->toBe(5);

    $this->travel(16)->minutes();
    handover($this, $this->order, $this->code)->assertOk();
});

it('refuses another branch, staff without the permission, a second handover; replays a key once', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH, Branch::factory()->create()->branch_id);
    handover($this, $this->order, $this->code)->assertForbidden()->assertJsonPath('code', 'wrong_branch');

    Orders::staff($this, SeedRole::FINANCE);
    handover($this, $this->order, $this->code)->assertForbidden();

    Orders::staff($this, SeedRole::IGI_BRANCH);
    $key = (string) Str::uuid();
    handover($this, $this->order, $this->code, $key)->assertOk();
    handover($this, $this->order, $this->code, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    handover($this, $this->order, $this->code)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');
});
