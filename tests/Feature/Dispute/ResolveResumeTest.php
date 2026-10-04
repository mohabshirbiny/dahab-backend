<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\OrderDeadlineExtension;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-010–FR-012, Clarification: resume returns the order to where it
// was and gives every running deadline back exactly the frozen time.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('resumes to the frozen-from state and pushes the running deadline by the frozen time', function (string $from, ?string $column, ?string $which) {
    Bus::fake([NotifyCustomerJob::class]);
    $order = match ($from) {
        'at_inspection' => Disputes::atInspection($this),
        'weight_adjust_pending' => Disputes::deciding($this),
        'awaiting_balance' => Disputes::awaitingBalance($this),
        'ready_to_collect' => Disputes::readyToCollect($this),
    };
    $old = $column === null ? null : $order->refresh()->{$column};
    $dispute = Disputes::opened($this, $order);

    $this->travel(90)->minutes();
    $staff = Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute, ['reply' => 'We checked it and the result stands.'])->assertOk()
        ->assertJsonPath('data.state', 'resolved')->assertJsonPath('data.outcome', 'resume')
        ->assertJsonPath('data.reply', 'We checked it and the result stands.')
        ->assertJsonPath('data.resolved_by.staff_id', $staff->staff_id);

    $order->refresh();
    expect($order->state->value)->toBe($from);
    $ext = DatabaseActor::elevate('maintenance', fn () => OrderDeadlineExtension::query()->where('dispute_id', $dispute->dispute_id)->get());
    if ($column === null) {
        expect($ext)->toHaveCount(0);
    } else {
        // Frozen at the dispute's frozen_at, resolved 90 minutes later: exactly that much more time.
        $frozen = $dispute->frozen_at->diffInMicroseconds(now(), true);
        expect($ext)->toHaveCount(1)
            ->and($ext[0]->which->value)->toBe($which)
            ->and($ext[0]->granted_by)->toBe($staff->staff_id)
            ->and(abs($order->{$column}->diffInMicroseconds($old->addMicroseconds((int) round($frozen)), true)))->toBeLessThan(1000)
            ->and($old->diffInMinutes($order->{$column}))->toBeGreaterThanOrEqual(90);
    }

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'dispute.resolved')->sole());
    expect($audit->reason)->toBe('We checked it and the result stands.')
        ->and($audit->actor_staff_id)->toBe($staff->staff_id);

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $order->buyer_id
        && $j->notification->event === OrderEvent::DISPUTE_RESOLVED && str_contains($j->notification->body(false), 'result stands'));
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $order->seller_id && $j->notification->event === OrderEvent::DISPUTE_RESUMED);
})->with([
    ['at_inspection', null, null],
    ['weight_adjust_pending', 'decision_due_deadline', 'decision'],
    ['awaiting_balance', 'balance_due_deadline', 'balance'],
    ['ready_to_collect', 'collect_deadline', 'collect'],
]);

it('lets the sweep honour the new balance deadline after a resume', function () {
    $order = Disputes::awaitingBalance($this);
    $deadline = $order->refresh()->balance_due_deadline;
    $dispute = Disputes::opened($this, $order);
    $this->travelTo($deadline->addDay());
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute)->assertOk();

    // The old deadline has passed, but the order got the frozen time back.
    Orders::sweep();
    expect($order->refresh()->state->value)->toBe('awaiting_balance');

    $this->travelTo($order->balance_due_deadline->addMinute());
    Orders::sweep();
    expect($order->refresh()->state->value)->toBe('cancelled_buyer_nopay');
});

it('refuses a resolution without a proper reply, and a resolved dispute', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    Orders::staff($this, SeedRole::OPERATIONS);

    Disputes::resolve($this, $dispute, ['reply' => ''])->assertStatus(422)->assertJsonValidationErrors('reply');
    Disputes::resolve($this, $dispute, ['reply' => 'too short'])->assertStatus(422);
    Disputes::resolve($this, $dispute, ['reply' => str_repeat('x', 2001)])->assertStatus(422);
    expect($order->refresh()->state->value)->toBe('disputed');

    Disputes::resolve($this, $dispute)->assertOk();
    Disputes::resolve($this, $dispute)->assertStatus(409)->assertJsonPath('code', 'illegal_dispute_transition');
});

it('lets anyone with the code resolve a dispute passed on to someone else', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    $coo = Orders::staff($this, SeedRole::COO);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::passOn($this, $dispute, ['assignee_id' => $coo->staff_id, 'note' => 'Please take this one over.'])->assertOk();

    $other = Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute)->assertOk()->assertJsonPath('data.resolved_by.staff_id', $other->staff_id);
});

it('does not show a second payment on the buyer\'s timeline when a paid order resumes', function () {
    $order = Disputes::readyToCollect($this);
    $dispute = Disputes::opened($this, $order);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute)->assertOk();

    $events = collect(Listings::as($this, Orders::buyer($order))->getJson(Orders::CUSTOMER_URL."/{$order->order_id}")
        ->assertOk()->json('data.timeline'))->pluck('event');
    expect($events->filter(fn ($e) => $e === 'paid'))->toHaveCount(1);
});
