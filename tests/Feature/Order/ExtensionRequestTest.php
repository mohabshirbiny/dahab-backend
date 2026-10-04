<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\OrderDeadlineExtension;
use App\Models\OrderExtensionRequest;
use App\Support\DatabaseActor;
use App\Support\WorkingHours\WorkingHoursResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 US4, FR-022–FR-027, Clarification: the seller asks with a reason
// and a line; staff accept with 6/12/24/48 working hours or refuse; a request
// lapses when the order moves on.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
});

function extensionRequestOf($order): OrderExtensionRequest
{
    return DatabaseActor::elevate('maintenance', fn () => OrderExtensionRequest::query()->where('order_id', $order->order_id)
        ->orderByDesc('requested_at')->firstOrFail());
}

it('stores a waiting request, the deadline unchanged, shown to the seller only', function () {
    $deadline = $this->order->reach_branch_deadline;
    Disputes::askMoreTime($this, $this->order)->assertCreated()
        ->assertJsonPath('data.extension_request.state', 'waiting')
        ->assertJsonPath('data.extension_request.reason', 'branch_closed');
    expect(Orders::show($this, Orders::seller($this->order), $this->order)->json('data.actions'))->not->toContain('ask_more_time');

    expect($this->order->refresh()->reach_branch_deadline->equalTo($deadline))->toBeTrue()
        ->and(extensionRequestOf($this->order)->deadline_at_request->equalTo($deadline))->toBeTrue()
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.extension_requested')->sole()->actor_customer_id))->toBe($this->order->seller_id);
    Orders::show($this, Orders::buyer($this->order), $this->order)->assertJsonPath('data.extension_request', null);

    Disputes::askMoreTime($this, $this->order)->assertStatus(409)->assertJsonPath('code', 'extension_request_pending');
});

it('refuses the buyer, other states, a passed deadline and bad bodies', function () {
    Listings::as($this, Orders::buyer($this->order))->postJson(Orders::CUSTOMER_URL."/{$this->order->order_id}/extension-requests",
        ['reason' => 'travelling', 'detail' => 'I am travelling this week, sorry.'], Listings::key())->assertNotFound();
    Disputes::askMoreTime($this, $this->order, ['reason' => 'sold'])->assertStatus(422)->assertJsonValidationErrors('reason');
    Disputes::askMoreTime($this, $this->order, ['detail' => 'short'])->assertStatus(422)->assertJsonValidationErrors('detail');

    $this->travelTo($this->order->reach_branch_deadline->addMinute());
    Disputes::askMoreTime($this, $this->order)->assertStatus(409)->assertJsonPath('code', 'deadline_not_running');

    $other = Disputes::awaitingBalance($this);
    Disputes::askMoreTime($this, $other)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');
});

it('accepts with working hours through the staff extend, linked, audited, both told', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Disputes::askMoreTime($this, $this->order)->assertCreated();
    $request = extensionRequestOf($this->order);
    $old = $this->order->reach_branch_deadline;
    $staff = Orders::staff($this, SeedRole::OPERATIONS);

    $this->getJson(Disputes::REQUESTS_URL)->assertOk()->assertJsonPath('data.0.id', $request->request_id)
        ->assertJsonPath('data.0.order.ref', $this->order->order_ref)->assertJsonPath('data.0.extensions_before', 0);

    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/accept", ['hours' => 12, 'note' => 'Extended — please go first thing tomorrow.'], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'accepted')->assertJsonPath('data.hours_granted', 12);

    $expected = app(WorkingHoursResolver::class)->addWorkingMinutes($old, 720, (int) $this->order->branch_id);
    $ext = DatabaseActor::elevate('maintenance', fn () => OrderDeadlineExtension::query()->where('order_id', $this->order->order_id)->sole());
    expect($this->order->refresh()->reach_branch_deadline->equalTo($expected))->toBeTrue()
        ->and($ext->extension_request_id)->toBe($request->request_id)
        ->and($ext->granted_by)->toBe($staff->staff_id)
        ->and($ext->reason)->toBe('Extended — please go first thing tomorrow.')
        ->and(extensionRequestOf($this->order)->extension_id)->toBe($ext->extension_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->whereIn('action', ['order.deadline_extended', 'order.extension_request_accepted'])->count()))->toBe(2);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $this->order->seller_id && $j->notification->event === OrderEvent::DEADLINE_EXTENDED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $this->order->buyer_id && $j->notification->event === OrderEvent::DEADLINE_EXTENDED);

    Orders::show($this, Orders::seller($this->order), $this->order)->assertJsonPath('data.extension_request.state', 'accepted')
        ->assertJsonPath('data.extension_request.hours_granted', 12);
    Disputes::actAs($staff);
    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/refuse", ['note' => 'Too late, already accepted it.'], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'illegal_extension_request_transition');

    // The next request counts the extension before it.
    Disputes::askMoreTime($this, $this->order)->assertCreated();
    Orders::staff($this, SeedRole::OPERATIONS);
    $this->getJson(Disputes::REQUESTS_URL)->assertJsonPath('data.0.extensions_before', 1);
});

it('refuses with a note, the deadline kept, the seller told', function () {
    Bus::fake([NotifyCustomerJob::class]);
    Disputes::askMoreTime($this, $this->order)->assertCreated();
    $request = extensionRequestOf($this->order);
    $old = $this->order->reach_branch_deadline;
    Orders::staff($this, SeedRole::OPERATIONS);

    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/refuse", ['note' => 'The buyer has waited long enough.'], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'refused');

    expect($this->order->refresh()->reach_branch_deadline->equalTo($old))->toBeTrue();
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $this->order->seller_id
        && $j->notification->event === OrderEvent::EXTENSION_REFUSED && str_contains($j->notification->body(false), 'waited long enough'));
    Bus::assertNotDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $this->order->buyer_id);
});

it('validates the answer and needs order.extend_deadline', function () {
    Disputes::askMoreTime($this, $this->order)->assertCreated();
    $request = extensionRequestOf($this->order);

    Orders::staff($this, SeedRole::OPERATIONS);
    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/accept", ['hours' => 10, 'note' => 'Extended — please go tomorrow.'], Listings::key())
        ->assertStatus(422)->assertJsonValidationErrors('hours');
    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/accept", ['hours' => 6, 'note' => 'short'], Listings::key())
        ->assertStatus(422)->assertJsonValidationErrors('note');

    Orders::staff($this, SeedRole::FINANCE);
    $this->getJson(Disputes::REQUESTS_URL)->assertOk();
    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/accept", ['hours' => 6, 'note' => 'Extended — please go tomorrow.'], Listings::key())
        ->assertForbidden();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $this->getJson(Disputes::REQUESTS_URL)->assertForbidden();
});

it('lapses a waiting request when the order moves on', function (string $how) {
    Disputes::askMoreTime($this, $this->order)->assertCreated();

    match ($how) {
        'received' => (function () {
            Orders::staff($this, SeedRole::OPERATIONS);
            Orders::receive($this, $this->order)->assertOk();
        })(),
        'seller_cancelled' => Orders::cancel($this, Orders::seller($this->order), $this->order)->assertOk(),
        'staff_cancelled' => (function () {
            Orders::staff($this, SeedRole::OPERATIONS);
            $this->postJson(Orders::STAFF_URL."/{$this->order->order_id}/cancel", ['reason' => 'Cancelled for the test.', 'relist' => true], Listings::key())->assertOk();
        })(),
        'deadline_missed' => (function () {
            $this->travelTo($this->order->reach_branch_deadline->addMinute());
            Orders::sweep();
        })(),
    };

    expect(extensionRequestOf($this->order)->state->value)->toBe('lapsed');
})->with(['received', 'seller_cancelled', 'staff_cancelled', 'deadline_missed']);

it('lists a month of requests with their outcomes', function () {
    Disputes::askMoreTime($this, $this->order)->assertCreated();
    Orders::staff($this, SeedRole::OPERATIONS);
    $request = extensionRequestOf($this->order);
    $this->postJson(Disputes::REQUESTS_URL."/{$request->request_id}/refuse", ['note' => 'The buyer has waited long enough.'], Listings::key())->assertOk();

    $month = now('Africa/Cairo')->format('Y-m');
    $this->getJson(Disputes::REQUESTS_URL."?state=all&month={$month}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'refused');
    $this->getJson(Disputes::REQUESTS_URL.'?state=all&month='.now('Africa/Cairo')->subMonth()->format('Y-m'))->assertJsonCount(0, 'data');
    $this->getJson(Disputes::REQUESTS_URL)->assertJsonCount(0, 'data');
});
