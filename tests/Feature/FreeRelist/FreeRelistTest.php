<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 US2, FR-004–FR-016, research R4–R7: the buyer puts a collected
// piece back on the market at once, as a new live listing linked to the order.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

function ac018Staff(string $table, array $where): array
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table($table)->where($where)->get()->map(fn ($r) => (array) $r)->all());
}

it('creates a new live listing owned by the buyer, copied from the piece, with no review', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $origin = Listing::factory()->withPhotos(3)->withInvoice()->live()->create(['karat_code' => 21, 'stated_weight_g' => '10.000', 'making_charge_per_g' => '300.00']);
    $order = FreeRelists::ac018CollectedOrder($this, $origin, ['measured_karat' => 21, 'measured_weight_g' => '9.900']);
    $buyer = Orders::buyer($order);

    $res = FreeRelists::ac018Relist($this, $buyer, $order, FreeRelists::ac018RelistPayload($order, ['making_charge_per_g' => '275.50', 'description' => 'Ready again.']))
        ->assertCreated()
        ->assertJsonPath('data.listing.state', 'live')
        ->assertJsonPath('data.order.free_relist.status', 'used');

    $new = Listing::query()->where('relisted_from_order_id', $order->order_id)->sole();
    $originKept = Listing::query()->findOrFail($origin->listing_id);

    expect($new->listing_id)->toBe($res->json('data.listing.id'))
        ->and($new->seller_id)->toBe($buyer->customer_id)
        ->and($new->state->value)->toBe('live')
        ->and($new->listed_at)->not->toBeNull()
        ->and($new->karat_code)->toBe(21)
        // The IGI-measured weight, not the stated one.
        ->and((string) $new->stated_weight_g)->toBe('9.900')
        ->and((string) $new->making_charge_per_g)->toBe('275.5000')
        ->and($new->description)->toBe('Ready again.')
        ->and($originKept->state->value)->toBe('sold');

    $media = ac018Staff('listing_media', ['listing_id' => $new->listing_id]);
    expect(collect($media)->where('kind', 'photo'))->toHaveCount(3)
        ->and(collect($media)->where('kind', 'invoice'))->toHaveCount(0)
        ->and(collect($media)->where('is_private', true))->toHaveCount(0);

    expect(ac018Staff('listing_branch_option', ['listing_id' => $new->listing_id]))->not->toBeEmpty()
        ->and(ac018Staff('listing_ownership_declaration', ['listing_id' => $new->listing_id]))->toHaveCount(1)
        ->and(ac018Staff('listing_queue_seq', ['listing_id' => $new->listing_id]))->toHaveCount(1);

    $history = ListingStateChange::query()->where('listing_id', $new->listing_id)->orderBy('change_id')->get();
    expect($history->pluck('to_state')->map->value->all())->toBe(['draft', 'live'])
        ->and($history->last()->actor_customer_id)->toBe($buyer->customer_id)
        ->and($history->last()->note)->toContain($order->order_ref);

    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.free_relisted')->sole()->actor_customer_id))->toBe($buyer->customer_id);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $buyer->customer_id && $job->notification->event === OrderEvent::FREE_RELISTED);

    // The offer is used, with the new listing named.
    Orders::show($this, $buyer, $order)->assertOk()
        ->assertJsonPath('data.free_relist.status', 'used')
        ->assertJsonPath('data.free_relist.listing_id', $new->listing_id);
});

it('relists a diamond and a gold piece with a stone on the entered asking price', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create();
    $one = FreeRelists::ac018CollectedOrder($this, $diamond);
    FreeRelists::ac018Relist($this, Orders::buyer($one), $one, FreeRelists::ac018RelistPayload($one, ['asking_price' => '125000.00']))->assertCreated();

    $stone = Listing::factory()->goldWithDiamond()->withPhotos(3)->live()->create();
    $two = FreeRelists::ac018CollectedOrder($this, $stone);
    FreeRelists::ac018Relist($this, Orders::buyer($two), $two, FreeRelists::ac018RelistPayload($two, ['asking_price' => '95000.00']))->assertCreated();

    $a = Listing::query()->where('relisted_from_order_id', $one->order_id)->sole();
    $b = Listing::query()->where('relisted_from_order_id', $two->order_id)->sole();
    expect((string) $a->asking_price)->toBe('125000.0000')->and($a->karat_code)->toBeNull()
        ->and((string) $b->asking_price)->toBe('95000.0000')->and($b->karat_code)->toBe(21);
});

it('answers 404 to anyone but the buyer', function () {
    $order = FreeRelists::ac018CollectedOrder($this);

    FreeRelists::ac018Relist($this, Orders::seller($order), $order)->assertNotFound();
    FreeRelists::ac018Relist($this, Customer::factory()->verified()->create(), $order)->assertNotFound();
    expect(Listing::query()->whereNotNull('relisted_from_order_id')->count())->toBe(0);
});

it('refuses an order not collected yet', function () {
    Orders::workedPrices();
    $order = Orders::inspected($this, Orders::accepted($this, Orders::ring(), '200000'), '10.000');
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    FreeRelists::ac018Relist($this, Orders::buyer($order), $order->refresh(), ['making_charge_per_g' => '250', 'ownership_legal_doc_id' => Listings::declarationId()])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_order_transition');
});

it('refuses after the window, and a second relist of the same order', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);

    $k1 = (string) Str::uuid();
    $k2 = (string) Str::uuid();
    FreeRelists::ac018Relist($this, $buyer, $order, null, $k1)->assertCreated();
    // The same key replays; another key is a second relist.
    FreeRelists::ac018Relist($this, $buyer, $order, null, $k1)->assertCreated();
    FreeRelists::ac018Relist($this, $buyer, $order, null, $k2)->assertStatus(409)->assertJsonPath('code', 'already_relisted');
    expect(Listing::query()->where('relisted_from_order_id', $order->order_id)->count())->toBe(1);

    $late = FreeRelists::ac018CollectedOrder($this);
    $this->travel(30)->days();
    FreeRelists::ac018Relist($this, Orders::buyer($late), $late)->assertStatus(409)->assertJsonPath('code', 'free_relist_expired');
    expect(Orders::show($this, Orders::buyer($late), $late)->json('data.free_relist.status'))->toBe('expired');
});

it('refuses a suspended buyer, and a closed one', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = FreeRelists::ac018Suspend(Orders::buyer($order));
    FreeRelists::ac018Relist($this, $buyer, $order)->assertForbidden()->assertJsonPath('code', 'account_suspended');

    $other = FreeRelists::ac018CollectedOrder($this);
    $closed = FreeRelists::ac018Close(Orders::buyer($other));
    FreeRelists::ac018Relist($this, $closed, $other)->assertForbidden()->assertJsonPath('code', 'account_closed');
    expect(Listing::query()->whereNotNull('relisted_from_order_id')->count())->toBe(0);
});

it('refuses a missing price for the category, a stale declaration, and when no branch option is left', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);

    FreeRelists::ac018Relist($this, $buyer, $order, ['description' => 'x', 'ownership_legal_doc_id' => Listings::declarationId()])
        ->assertStatus(422)->assertJsonValidationErrors(['making_charge_per_g']);
    FreeRelists::ac018Relist($this, $buyer, $order, FreeRelists::ac018RelistPayload($order, ['ownership_legal_doc_id' => 999999]))
        ->assertStatus(422)->assertJsonPath('code', 'ownership_declaration_required');

    DatabaseActor::elevate('maintenance', fn () => DB::table('branch')->update(['is_enabled' => false]));
    FreeRelists::ac018Relist($this, $buyer, $order)->assertStatus(422)->assertJsonPath('code', 'branch_options_required');
    expect(Listing::query()->whereNotNull('relisted_from_order_id')->count())->toBe(0);
});

it('leaves the origin listing, order and history exactly as they were', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);
    $before = [
        ac018Staff('listing_state_change', ['listing_id' => $order->listing_id]),
        DatabaseActor::elevate('maintenance', fn () => (array) DB::table('order')->where('order_id', $order->order_id)->first()),
    ];

    FreeRelists::ac018Relist($this, $buyer, $order)->assertCreated();

    expect(ac018Staff('listing_state_change', ['listing_id' => $order->listing_id]))->toBe($before[0])
        ->and(DatabaseActor::elevate('maintenance', fn () => (array) DB::table('order')->where('order_id', $order->order_id)->first()))->toBe($before[1]);
});

it('does not let a relist of a relisted listing\'s sale be made again', function () {
    $origin = FreeRelists::ac018CollectedOrder($this);
    FreeRelists::ac018Relist($this, Orders::buyer($origin), $origin)->assertCreated();
    $relisted = Listing::query()->where('relisted_from_order_id', $origin->order_id)->sole();

    $second = FreeRelists::ac018SettleRelisted($this, $relisted);
    $code = Orders::show($this, Orders::buyer($second), $second)->json('data.collection_code');
    Orders::staff($this, SeedRole::IGI_BRANCH, $second->branch_id);
    $this->postJson(Orders::STAFF_URL."/{$second->order_id}/handover", ['code' => $code], Listings::key())->assertOk();

    FreeRelists::ac018Relist($this, Orders::buyer($second), $second->refresh())
        ->assertStatus(409)->assertJsonPath('code', 'free_relist_expired');
});
