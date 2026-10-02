<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Listing;
use App\Models\OrderStateChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US1, FR-003, FR-004, research R5: staff at the order's branch
// mark the piece received; the order and the listing move to at_inspection.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
});

it('receives the piece: order and listing at inspection, history, audit, both told', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $staff = Orders::staff($this, SeedRole::OPERATIONS);

    Orders::receive($this, $this->order)->assertOk()
        ->assertJsonPath('data.state', 'at_inspection')
        ->assertJsonPath('data.listing_state', 'at_inspection');

    $change = OrderStateChange::query()->where('order_id', $this->order->order_id)->where('to_state', 'at_inspection')->sole();
    expect($change->actor_staff_id)->toBe($staff->staff_id)
        ->and(Listing::query()->find($this->order->listing_id)->state->value)->toBe('at_inspection')
        ->and(AuditLog::query()->where('action', 'order.received')->where('actor_staff_id', $staff->staff_id)->exists())->toBeTrue();

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->order->buyer_id && $job->notification->event === OrderEvent::RECEIVED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->order->seller_id && $job->notification->event === OrderEvent::RECEIVED);
});

it('lets staff assigned to the order branch receive it', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);

    Orders::receive($this, $this->order)->assertOk()->assertJsonPath('data.task', 'inspect');
});

it('refuses, and records, a receive at another branch', function () {
    $other = Branch::factory()->create()->branch_id;
    $staff = Orders::staff($this, SeedRole::IGI_BRANCH, $other);

    Orders::receive($this, $this->order)->assertForbidden()->assertJsonPath('code', 'wrong_branch');

    expect($this->order->refresh()->state->value)->toBe('awaiting_delivery')
        ->and(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->exists())->toBeTrue();
});

it('refuses staff without the permission, a second receive, and replays a key once', function () {
    Orders::staff($this, SeedRole::FINANCE);
    Orders::receive($this, $this->order)->assertForbidden();

    Orders::staff($this, SeedRole::OPERATIONS);
    $key = (string) Str::uuid();
    Orders::receive($this, $this->order, $key)->assertOk();
    Orders::receive($this, $this->order, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    Orders::receive($this, $this->order)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');

    expect(OrderStateChange::query()->where('order_id', $this->order->order_id)->where('to_state', 'at_inspection')->count())->toBe(1);
});
