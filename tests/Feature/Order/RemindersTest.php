<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-025, research R14: one reminder 3 working hours before the
// reach-branch deadline (seller) and one 24 h before the balance deadline
// (buyer).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
});

function reminders(OrderEvent $event): int
{
    return Bus::dispatched(NotifyCustomerJob::class, fn ($job) => $job->notification->event === $event)->count();
}

it('reminds the seller once when little working time is left', function () {
    Bus::fake([NotifyCustomerJob::class]);

    Orders::sweep();
    expect(reminders(OrderEvent::REACH_REMINDER))->toBe(0);

    $this->travelTo($this->order->reach_branch_deadline->subMinutes(30));
    Orders::sweep();
    Orders::sweep();

    expect(reminders(OrderEvent::REACH_REMINDER))->toBe(1)
        ->and($this->order->refresh()->reach_reminder_sent_at)->not->toBeNull();
});

it('does not remind once the piece is received', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();

    $this->travelTo($this->order->reach_branch_deadline->subMinutes(30));
    Orders::sweep();

    expect(reminders(OrderEvent::REACH_REMINDER))->toBe(0);
});

it('reminds the buyer once a day before the balance deadline, with the amount due', function () {
    Orders::inspected($this, $this->order);
    Bus::fake([NotifyCustomerJob::class]);

    $this->travelTo($this->order->refresh()->balance_due_deadline->subHours(23));
    Orders::sweep();
    Orders::sweep();

    $sent = Bus::dispatched(NotifyCustomerJob::class, fn ($job) => $job->notification->event === OrderEvent::BALANCE_REMINDER);
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->customerId)->toBe($this->order->buyer_id)
        ->and($sent->first()->notification->amount)->toBe('44505.0000');
});
