<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Models\Setting;
use App\Support\DatabaseActor;
use App\Support\WorkingHours\WorkingHoursResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 US1, FR-001–FR-003, research R1–R3: the staff handover stores the
// end of the free-relist window — working hours on the order's branch — and
// never fails because of it.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

/** Take an order to ready-to-collect and return it with the buyer's code. */
function ac018PaidOrder($test, ?Listing $listing = null): array
{
    Orders::workedPrices();
    $order = Orders::inspected($test, Orders::accepted($test, $listing ?? Orders::ring(), '200000'), '10.000');
    $code = Orders::pay($test, Orders::buyer($order), $order)->assertOk()->json('data.collection_code');

    return [$order->refresh(), $code];
}

function ac018Handover($test, Order $order, string $code, array $extra = [])
{
    Orders::staff($test, SeedRole::IGI_BRANCH, $order->branch_id);

    return $test->postJson(Orders::STAFF_URL."/{$order->order_id}/handover", ['code' => $code] + $extra, Listings::key());
}

it('stores the handover plus twelve working hours on the order\'s branch', function () {
    [$order, $code] = ac018PaidOrder($this);

    ac018Handover($this, $order, $code)->assertOk();

    $collection = OrderCollection::query()->where('order_id', $order->order_id)->sole();
    $expected = app(WorkingHoursResolver::class)->addWorkingMinutes(
        CarbonImmutable::parse($collection->collected_at), 12 * 60, (int) $order->branch_id,
    );

    expect($collection->free_relist_until)->not->toBeNull()
        ->and(CarbonImmutable::parse($collection->free_relist_until)->equalTo($expected))->toBeTrue()
        ->and(CarbonImmutable::parse($collection->free_relist_until)->greaterThan($collection->collected_at))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'order.handed_over')->sole();
    expect($audit->after_json['free_relist_offer'] ?? null)->toBe('open');
});

it('gives no offer when the setting is zero, and says why in the audit', function () {
    Setting::query()->whereKey('deadline.free_relist_working_hours')->update(['value_numeric' => 0]);
    [$order, $code] = ac018PaidOrder($this);

    ac018Handover($this, $order, $code)->assertOk()->assertJsonPath('data.state', 'completed');

    expect(FreeRelists::ac018Window($order))->toBeNull();
    $audit = AuditLog::query()->where('action', 'order.handed_over')->sole();
    expect($audit->after_json['free_relist_offer'] ?? null)->toBe('none')
        ->and($audit->after_json['free_relist_none_reason'] ?? null)->toBe('setting_zero');

    Orders::show($this, Orders::buyer($order), $order)->assertOk()->assertJsonPath('data.free_relist.status', 'none')
        ->assertJsonPath('data.free_relist.ends_at', null);
});

it('still completes the handover when the branch\'s hours cannot be worked out', function () {
    [$order, $code] = ac018PaidOrder($this);
    DatabaseActor::elevate('maintenance', fn () => DB::table('branch_hours')->where('branch_id', $order->branch_id)->delete());

    ac018Handover($this, $order, $code)->assertOk()->assertJsonPath('data.state', 'completed');

    expect(FreeRelists::ac018Window($order))->toBeNull();
    $audit = AuditLog::query()->where('action', 'order.handed_over')->sole();
    expect($audit->after_json['free_relist_none_reason'] ?? null)->toBe('hours_unavailable');
});

it('does not move the stored end when the setting changes afterwards', function () {
    [$order, $code] = ac018PaidOrder($this);
    ac018Handover($this, $order, $code)->assertOk();
    $before = FreeRelists::ac018Window($order);

    Setting::query()->whereKey('deadline.free_relist_working_hours')->update(['value_numeric' => 48]);

    Orders::show($this, Orders::buyer($order), $order)->assertOk()
        ->assertJsonPath('data.free_relist.status', 'open');
    expect(CarbonImmutable::parse(FreeRelists::ac018Window($order))->equalTo(CarbonImmutable::parse($before)))->toBeTrue();
});

it('shows the buyer an open offer with its end, and the seller none of the buyer\'s offer', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $until = FreeRelists::ac018Window($order);

    $buyerView = Orders::show($this, Orders::buyer($order), $order)->assertOk();
    $buyerView->assertJsonPath('data.free_relist.status', 'open');
    expect(CarbonImmutable::parse($buyerView->json('data.free_relist.ends_at'))->equalTo(CarbonImmutable::parse($until)))->toBeTrue();

    // The seller of the origin sale has no offer of their own.
    Orders::show($this, Orders::seller($order), $order)->assertOk()->assertJsonPath('data.free_relist.status', 'none');
});

it('tells the buyer the window end in the collected notice, the seller nothing of it', function () {
    Bus::fake([NotifyCustomerJob::class]);
    [$order, $code] = ac018PaidOrder($this);

    ac018Handover($this, $order, $code)->assertOk();

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->buyer_id
        && $job->notification->event === OrderEvent::COLLECTED && $job->notification->deadline !== null);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->seller_id
        && $job->notification->event === OrderEvent::COLLECTED && $job->notification->deadline === null);
    // No reminder or expiry notice is ever scheduled.
    expect(Bus::dispatched(NotifyCustomerJob::class, fn ($j) => $j->notification->event === OrderEvent::COLLECTED))->toHaveCount(2);
});

it('stores the window after a proxy handover too', function () {
    Orders::workedPrices();
    $order = Disputes::readyToCollect($this);
    Disputes::nameProxy($this, $order)->assertOk();
    $code = DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $order->order_id)->value('code_encrypted'));

    ac018Handover($this, $order, $code, ['collector' => 'proxy', 'proxy_id_checked' => true])->assertOk();

    expect(FreeRelists::ac018Window($order))->not->toBeNull();
});

it('keeps the offer visible and counting while the buyer is suspended', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);
    FreeRelists::ac018Suspend($buyer);

    Orders::show($this, $buyer->refresh(), $order)->assertOk()
        ->assertJsonPath('data.free_relist.status', 'open');
});

it('gives no offer when the sold listing is itself a free relist', function () {
    $origin = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($origin);
    FreeRelists::ac018Relist($this, $buyer, $origin)->assertCreated();
    $relisted = Listing::query()->where('relisted_from_order_id', $origin->order_id)->sole();

    $second = FreeRelists::ac018SettleRelisted($this, $relisted);
    $code = Orders::show($this, Orders::buyer($second), $second)->json('data.collection_code');
    ac018Handover($this, $second, $code)->assertOk();

    expect(FreeRelists::ac018Window($second))->toBeNull();
    $audit = AuditLog::query()->where('action', 'order.handed_over')->latest('created_at')->first();
    expect($audit->after_json['free_relist_none_reason'] ?? null)->toBe('free_relist_sale');
});
