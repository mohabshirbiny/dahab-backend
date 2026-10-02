<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-021, research R14; Part 3 §10.2: paid but not collected within
// the window — the listing becomes uncollected_expired and the buyer is told;
// the order stays ready to collect, and a later handover still completes it.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->code = Orders::pay($this, Orders::buyer($this->order), $this->order)->json('data.collection_code');
});

it('marks the piece uncollected past the window, once, and the buyer is told', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $this->travelTo($this->order->refresh()->collect_deadline->addMinute());

    Orders::sweep();
    Orders::sweep();

    expect(Listing::query()->find($this->order->listing_id)->state->value)->toBe('uncollected_expired')
        ->and($this->order->refresh()->state->value)->toBe('ready_to_collect')
        ->and(AuditLog::query()->where('action', 'order.window_passed')->sole()->actor_staff_id)->toBe(SystemActor::id());
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->order->buyer_id
        && $job->notification->event === OrderEvent::COLLECTION_WINDOW_PASSED);

    Orders::show($this, Orders::buyer($this->order), $this->order)->assertOk()->assertJsonPath('data.collection.window_passed', true);
});

it('still hands the piece over after the window: sold, completed', function () {
    $this->travelTo($this->order->refresh()->collect_deadline->addMinute());
    Orders::sweep();

    Orders::staff($this, SeedRole::IGI_BRANCH);
    $this->postJson(Orders::STAFF_URL."/{$this->order->order_id}/handover", ['code' => $this->code], Listings::key())->assertOk();

    expect(Listing::query()->find($this->order->listing_id)->state->value)->toBe('sold')
        ->and($this->order->refresh()->state->value)->toBe('completed');
});
