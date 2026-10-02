<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OrderBranchChange;
use App\Models\OrderDeadlineExtension;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US3, FR-008, research R17; Part 3 §5.2: staff move an open order to
// another branch the seller named; the clock keeps running unless extended.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    // A second branch the seller named, open every day (the factory's hours).
    $this->other = Branch::factory()->create();
    DatabaseActor::elevate('maintenance', fn () => DB::table('listing_branch_option')
        ->insert(['listing_id' => $this->order->listing_id, 'branch_id' => $this->other->branch_id]));
});

function changeBranch($test, $order, array $body, ?string $key = null)
{
    return $test->postJson(Orders::STAFF_URL."/{$order->order_id}/change-branch", $body, Listings::key($key));
}

it('moves the order, keeps the clock running, records and audits it, tells both', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $staff = Orders::staff($this, SeedRole::OPERATIONS);
    $deadline = $this->order->reach_branch_deadline;

    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'The seller lives closer to this one.'])
        ->assertOk()->assertJsonPath('data.branch.id', $this->other->branch_id);

    $order = $this->order->refresh();
    $change = OrderBranchChange::query()->sole();
    expect($order->branch_id)->toBe($this->other->branch_id)
        ->and($order->reach_branch_deadline->equalTo($deadline))->toBeTrue()
        ->and($change->changed_by)->toBe($staff->staff_id)
        ->and($change->extended_to)->toBeNull()
        ->and(OrderDeadlineExtension::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'order.branch_changed')->sole()->reason)->toBe('The seller lives closer to this one.');

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->seller_id && $job->notification->event === OrderEvent::BRANCH_CHANGED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->buyer_id && $job->notification->event === OrderEvent::BRANCH_CHANGED);
});

it('extends the deadline when asked, as a recorded extension, clearing the reminder', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    DatabaseActor::elevate('maintenance', fn () => DB::table('order')->where('order_id', $this->order->order_id)->update(['reach_reminder_sent_at' => now()]));
    $later = $this->order->reach_branch_deadline->addDay();

    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'The first branch closed for repairs.',
        'extend_to' => $later->toIso8601String()])->assertOk();

    $order = $this->order->refresh();
    expect($order->reach_branch_deadline->equalTo($later))->toBeTrue()
        ->and($order->reach_reminder_sent_at)->toBeNull()
        ->and(OrderDeadlineExtension::query()->sole()->which->value)->toBe('reach_branch');
});

it('refuses a branch the seller never named, a disabled one, the same one, an earlier deadline', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    $foreign = Branch::factory()->create();

    changeBranch($this, $this->order, ['branch_id' => $foreign->branch_id, 'reason' => 'Trying another branch.'])
        ->assertStatus(409)->assertJsonPath('code', 'branch_not_in_options');
    changeBranch($this, $this->order, ['branch_id' => $this->order->branch_id, 'reason' => 'Trying the same branch.'])->assertStatus(422);
    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'Trying an earlier deadline.',
        'extend_to' => $this->order->reach_branch_deadline->subHour()->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('code', 'deadline_must_move_forward');
    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'short'])->assertStatus(422);

    DatabaseActor::elevate('maintenance', fn () => DB::table('branch')->where('branch_id', $this->other->branch_id)->update(['is_enabled' => false]));
    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'Trying a closed branch.'])
        ->assertStatus(409)->assertJsonPath('code', 'branch_not_in_options');
});

it('refuses once the piece reached a branch, and staff without the permission; replays once', function () {
    Orders::staff($this, SeedRole::FINANCE);
    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'Finance has no say here.'])->assertForbidden();

    Orders::staff($this, SeedRole::OPERATIONS);
    $key = (string) Str::uuid();
    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'The seller lives closer.'], $key)->assertOk();
    changeBranch($this, $this->order, ['branch_id' => $this->other->branch_id, 'reason' => 'The seller lives closer.'], $key)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect(OrderBranchChange::query()->count())->toBe(1);

    Orders::receive($this, $this->order)->assertOk();
    changeBranch($this, $this->order, ['branch_id' => $this->order->branch_id, 'reason' => 'Moving it back again.'])
        ->assertStatus(409)->assertJsonPath('code', 'order_not_open');
});
