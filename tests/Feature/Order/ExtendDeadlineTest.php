<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\OrderDeadlineExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US3, FR-009, research R17; Part 3 §1.4: staff extend the running
// deadline — reach branch, balance or collection — forward only, audited.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
});

function extend($test, $order, string $which, $to, string $reason = 'The customer asked for more time.')
{
    return $test->postJson(Orders::STAFF_URL."/{$order->order_id}/extend-deadline",
        ['which' => $which, 'new_deadline' => $to->toIso8601String(), 'reason' => $reason], Listings::key());
}

it('extends the reach-branch deadline: recorded, audited, both told, and the sweep honours it', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $staff = Orders::staff($this, SeedRole::OPERATIONS);
    $old = $this->order->reach_branch_deadline;
    $new = $old->addDay();

    extend($this, $this->order, 'reach_branch', $new)->assertOk();

    $ext = OrderDeadlineExtension::query()->sole();
    expect($this->order->refresh()->reach_branch_deadline->equalTo($new))->toBeTrue()
        ->and($ext->old_deadline->equalTo($old))->toBeTrue()
        ->and($ext->granted_by)->toBe($staff->staff_id)
        ->and(AuditLog::query()->where('action', 'order.deadline_extended')->sole()->reason)->toBe('The customer asked for more time.');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->order->seller_id && $job->notification->event === OrderEvent::DEADLINE_EXTENDED);

    $this->travelTo($old->addMinute());
    Orders::sweep();
    expect($this->order->refresh()->state->value)->toBe('awaiting_delivery');
});

it('extends the balance deadline only while the balance is due', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    extend($this, $this->order, 'balance', now()->addDays(20))->assertStatus(409)->assertJsonPath('code', 'deadline_not_running');

    Orders::inspected($this, $this->order, '10.000');
    Orders::staff($this, SeedRole::OPERATIONS);
    extend($this, $this->order, 'balance', $this->order->refresh()->balance_due_deadline->addDays(3))->assertOk();
});

it('reopens a passed collection window, putting the piece back to sold', function () {
    Orders::inspected($this, $this->order, '10.000');
    Orders::pay($this, Orders::buyer($this->order), $this->order)->assertOk();
    $this->travelTo($this->order->refresh()->collect_deadline->addMinute());
    Orders::sweep();
    expect(Listing::query()->find($this->order->listing_id)->state->value)->toBe('uncollected_expired');

    Orders::staff($this, SeedRole::OPERATIONS);
    extend($this, $this->order, 'collect', now()->addWeek())->assertOk();

    expect(Listing::query()->find($this->order->listing_id)->state->value)->toBe('sold');
});

it('refuses a deadline that does not move forward, a bad kind, and staff without the permission', function () {
    Orders::staff($this, SeedRole::FINANCE);
    extend($this, $this->order, 'reach_branch', $this->order->reach_branch_deadline->addDay())->assertForbidden();

    Orders::staff($this, SeedRole::OPERATIONS);
    extend($this, $this->order, 'reach_branch', $this->order->reach_branch_deadline->subHour())
        ->assertStatus(422)->assertJsonPath('code', 'deadline_must_move_forward');
    extend($this, $this->order, 'decision', now()->addDay())->assertStatus(422);
    extend($this, $this->order, 'reach_branch', $this->order->reach_branch_deadline->addDay(), 'short')->assertStatus(422);
});
