<?php

use App\Enums\CustomerStatus;
use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Enums\SuspendedReason;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\DisputeChange;
use App\Models\DisputePhoto;
use App\Models\OrderStateChange;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 US1, FR-001–FR-006: a party reports a problem and the order freezes.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

function disputeElevated(Closure $work): mixed
{
    return DatabaseActor::elevate('maintenance', $work);
}

it('freezes an order awaiting the balance when the buyer reports a problem, with photos, history, audit and both parties told', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    $tokens = [Disputes::photoToken($this, $buyer), Disputes::photoToken($this, $buyer)];

    $res = Disputes::open($this, $buyer, $order, ['photo_tokens' => $tokens])->assertCreated();

    $res->assertJsonPath('data.raised_as', 'buyer')
        ->assertJsonPath('data.state', 'open')
        ->assertJsonPath('data.photo_count', 2)
        ->assertJsonPath('data.reply', null);
    expect($res->json('data.ref'))->toStartWith('DSP-');

    $dispute = Disputes::of($order);
    expect($order->refresh()->state->value)->toBe('disputed')
        ->and($dispute->frozen_from->value)->toBe('awaiting_balance')
        ->and(disputeElevated(fn () => DisputePhoto::query()->where('dispute_id', $dispute->dispute_id)->count()))->toBe(2)
        ->and(disputeElevated(fn () => DisputeChange::query()->where('dispute_id', $dispute->dispute_id)->sole()->actor_customer_id))->toBe($buyer->customer_id);

    $change = disputeElevated(fn () => OrderStateChange::query()->where('order_id', $order->order_id)->where('to_state', 'disputed')->sole());
    expect($change->actor_customer_id)->toBe($buyer->customer_id)
        ->and($change->note)->toBe(OrderStateChange::NOTE_DISPUTE_OPENED);

    $audit = disputeElevated(fn () => AuditLog::query()->where('action', 'dispute.opened')->sole());
    expect($audit->actor_customer_id)->toBe($buyer->customer_id);

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->seller_id && $job->notification->event === OrderEvent::DISPUTE_OPENED);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->buyer_id && $job->notification->event === OrderEvent::DISPUTE_OPENED);

    // A used token cannot be used again.
    Disputes::open($this, Orders::seller($order), $order, ['photo_tokens' => [$tokens[0]]])->assertStatus(422);
});

it('lets either party open from each of the four freezable states', function (string $state, string $by) {
    $order = match ($state) {
        'at_inspection' => Disputes::atInspection($this),
        'weight_adjust_pending' => Disputes::deciding($this),
        'awaiting_balance' => Disputes::awaitingBalance($this),
        'ready_to_collect' => Disputes::readyToCollect($this),
    };
    $customer = $by === 'buyer' ? Orders::buyer($order) : Orders::seller($order);

    Disputes::open($this, $customer, $order)->assertCreated()->assertJsonPath('data.raised_as', $by);

    expect($order->refresh()->state->value)->toBe('disputed')
        ->and(Disputes::of($order)->frozen_from->value)->toBe($state);
})->with(['at_inspection', 'weight_adjust_pending', 'awaiting_balance', 'ready_to_collect'])->with(['buyer', 'seller']);

it('refuses other states, other customers and a second dispute', function () {
    $order = Orders::accepted($this);
    Disputes::open($this, Orders::buyer($order), $order)->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');
    Disputes::open($this, Customer::factory()->verified()->create(), $order)->assertNotFound();

    $order = Disputes::awaitingBalance($this);
    Disputes::open($this, Orders::buyer($order), $order)->assertCreated();
    // The other party while it is open: frozen, and no reference for them.
    $res = Disputes::open($this, Orders::seller($order), $order)->assertStatus(409)->assertJsonPath('code', 'order_frozen');
    expect($res->json('details.dispute_ref'))->toBeNull();
    // The raiser again: they already raised one.
    Disputes::open($this, Orders::buyer($order), $order)->assertStatus(409)->assertJsonPath('code', 'dispute_already_raised');
    expect(disputeElevated(fn () => Dispute::query()->count()))->toBe(1);
});

it('lets the other party raise their own once the first is resolved, and never a second time', function () {
    $order = Disputes::awaitingBalance($this);
    $first = Disputes::opened($this, $order);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $first)->assertOk();

    Disputes::open($this, Orders::seller($order), $order)->assertCreated()->assertJsonPath('data.raised_as', 'seller');
    expect($order->refresh()->state->value)->toBe('disputed');

    Disputes::open($this, Orders::buyer($order), $order)->assertStatus(409)->assertJsonPath('code', 'dispute_already_raised');
});

it('validates the body', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);

    Disputes::open($this, $buyer, $order, ['detail' => 'too short'])->assertStatus(422)->assertJsonValidationErrors('detail');
    Disputes::open($this, $buyer, $order, ['detail' => str_repeat('a', 2001)])->assertStatus(422);
    Disputes::open($this, $buyer, $order, ['reason' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('reason');
    Disputes::open($this, $seller, $order, ['reason' => 'not_theirs_to_sell'])->assertStatus(422)->assertJsonValidationErrors('reason');
    Disputes::open($this, $buyer, $order, ['photo_tokens' => array_fill(0, 6, 'x')])->assertStatus(422);

    expect($order->refresh()->state->value)->toBe('awaiting_balance');
});

it('refuses photo tokens of another purpose or another customer', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);

    // A token of another purpose, or of someone else.
    Disputes::open($this, $buyer, $order, ['photo_tokens' => [Listings::uploadToken($this, $buyer, 'proxy_id')]])
        ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');
    Disputes::open($this, $buyer, $order, ['photo_tokens' => [Disputes::photoToken($this, $seller)]])
        ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');

    expect($order->refresh()->state->value)->toBe('awaiting_balance');
});

it('lets a suspended customer report a problem and upload its photos, but not an unverified one', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    disputeElevated(function () use ($buyer) {
        $buyer->suspend(SuspendedReason::OTHER, 'Testing a suspended buyer.', Staff::query()->findOrFail(SystemActor::id()));
        $buyer->save();
    });
    expect($buyer->refresh()->status)->toBe(CustomerStatus::SUSPENDED);

    $token = Disputes::photoToken($this, $buyer->refresh());
    Disputes::open($this, $buyer, $order, ['photo_tokens' => [$token]])->assertCreated();

    $stranger = Customer::factory()->pendingVerification()->create();
    Listings::upload($this, $stranger, 'dispute_photo')->assertForbidden();
});

it('replays an idempotent request without a second dispute', function () {
    $order = Disputes::awaitingBalance($this);
    $key = (string) Str::uuid();

    $a = Disputes::open($this, Orders::buyer($order), $order, [], $key)->assertCreated();
    $b = Disputes::open($this, Orders::buyer($order), $order, [], $key)->assertCreated();

    expect($b->json('data.ref'))->toBe($a->json('data.ref'))
        ->and(disputeElevated(fn () => Dispute::query()->count()))->toBe(1);
});
