<?php

use App\Enums\SeedRole;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\MarketMakerApproval;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 Story 6 / T065 (research R17–R20): views, the guide, the files, Leave and the cost
// panel made of figures stored at settlement.

beforeEach(function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create([
        'seller_id' => $this->seller->customer_id, 'karat_code' => 21, 'stated_weight_g' => '10.000',
        'making_charge_per_g' => '300.00', 'listed_at' => now()->subDays(30),
    ]);
});

function marketView($t, ?string $device = null, ?Customer $as = null, ?Listing $listing = null)
{
    $listing ??= $t->listing;
    $client = $as === null ? Listings::anonymous($t) : Listings::as($t, $as);

    return $client->withHeaders($device === null ? [] : ['X-Device-Id' => $device])->getJson("/api/v1/market/listings/{$listing->listing_id}");
}

function viewCount(Listing $l): int
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('listing_view')->where('listing_id', $l->listing_id)->count());
}

it('counts one view per viewer per piece per day, never the seller or an anonymous visitor', function () {
    marketView($this, 'dev-1')->assertOk();
    marketView($this, 'dev-1')->assertOk();
    expect(viewCount($this->listing))->toBe(1);

    marketView($this, 'dev-2')->assertOk();
    $viewer = Customer::factory()->verified()->create();
    marketView($this, null, $viewer)->assertOk();
    expect(viewCount($this->listing))->toBe(3);

    // The seller's own look and a request with neither token nor device id are not counted.
    marketView($this, null, $this->seller)->assertOk();
    marketView($this)->assertOk();
    expect(viewCount($this->listing))->toBe(3);

    // The next Cairo day counts again (the test app re-runs earlier after-response callbacks on
    // every request, so the assertion is on the new day's row, not on the grand total).
    $firstDay = now('Africa/Cairo')->toDateString();
    $this->travel(1)->days();
    marketView($this, 'dev-1')->assertOk();
    $days = DatabaseActor::elevate('maintenance', fn () => DB::table('listing_view')->pluck('day')->map(fn ($d) => (string) $d)->unique()->sort()->values()->all());
    expect($days)->toHaveCount(2)->and($days[0])->toBe($firstDay);
});

it('stores no raw identifier and shows customers nothing of it', function () {
    marketView($this, 'dev-secret-7')->assertOk();

    $row = DatabaseActor::elevate('maintenance', fn () => DB::table('listing_view')->first());
    expect($row->viewer_hash)->toMatch('/^[0-9a-f]{64}$/')->and(json_encode($row))->not->toContain('dev-secret-7');

    $reader = Customer::factory()->verified()->create();
    DatabaseActor::push('customer', $reader->customer_id);
    expect(DB::table('listing_view')->count())->toBe(0);
    DatabaseActor::pop();
});

it('prunes only the rows older than the retention', function () {
    marketView($this, 'dev-1')->assertOk();
    DatabaseActor::elevate('maintenance', fn () => DB::table('listing_view')->insert([
        'listing_id' => $this->listing->listing_id, 'day' => now()->subDays(200)->toDateString(), 'viewer_hash' => 'old',
    ]));

    $this->artisan('listing-views:prune')->assertSuccessful();

    expect(viewCount($this->listing))->toBe(1);
});

it('adds views, requests, files and the guide to the queue', function () {
    Orders::staff($this, SeedRole::FINANCE);
    Listing::query()->whereKey($this->listing->listing_id)->update(['active_queue_count' => 0]);
    marketView($this, 'dev-1')->assertOk();
    marketView($this, 'dev-2')->assertOk();

    // Re-assume staff after the anonymous market calls.
    Orders::staff($this, SeedRole::FINANCE);
    $res = $this->getJson('/api/v1/dashboard/market-maker/queue')->assertOk();
    $row = collect($res->json('data'))->firstWhere('listing_id', $this->listing->listing_id);

    expect($row['views'])->toBe(2)->and($row['requests'])->toBe(0)
        ->and(collect($row['media'])->pluck('kind')->unique()->all())->toBe(['photo'])
        ->and($row['guide']['kind'])->toBe('making_charge')
        ->and($row['guide']['value_pct'])->toBeInt()
        ->and($res->json('meta'))->toMatchArray(['min_list_age_days' => 7, 'require_approval' => true]);

    // The tone turns at the setting: a threshold of 0 makes any making charge "bad".
    Promo::set(SettingKey::MARKETMAKER_MAKING_CHARGE_WARN_PCT, 0);
    $bad = collect($this->getJson('/api/v1/dashboard/market-maker/queue')->json('data'))->firstWhere('listing_id', $this->listing->listing_id);
    Promo::set(SettingKey::MARKETMAKER_MAKING_CHARGE_WARN_PCT, 1000);
    $ok = collect($this->getJson('/api/v1/dashboard/market-maker/queue')->json('data'))->firstWhere('listing_id', $this->listing->listing_id);
    expect($bad['guide']['tone'])->toBe('bad')->and($ok['guide']['tone'])->toBe('ok');
});

it('says "no guide yet" for a stone', function () {
    $stone = Listing::factory()->diamond()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id, 'listed_at' => now()->subDays(30)]);
    Orders::staff($this, SeedRole::FINANCE);

    $row = collect($this->getJson('/api/v1/dashboard/market-maker/queue')->json('data'))->firstWhere('listing_id', $stone->listing_id);
    expect($row['guide'])->toBe(['kind' => 'none', 'value_pct' => null, 'tone' => 'none']);
});

it('leaves a piece as it is, logged, and refuses one that is not waiting', function () {
    $staff = Orders::staff($this, SeedRole::FINANCE);
    $before = $this->listing->refresh()->state->value;

    $this->postJson("/api/v1/dashboard/market-maker/queue/{$this->listing->listing_id}/leave", ['note' => 'Price looks fair'], Promo::key())
        ->assertOk()->assertJsonPath('data.listing_id', $this->listing->listing_id);

    expect($this->listing->refresh()->state->value)->toBe($before)
        ->and(MarketMakerApproval::query()->count())->toBe(0);
    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'market_maker.left')->sole());
    expect($audit->actor_staff_id)->toBe($staff->staff_id);

    // Still in the queue.
    expect(collect($this->getJson('/api/v1/dashboard/market-maker/queue')->json('data'))->pluck('listing_id')->all())->toContain($this->listing->listing_id);

    // Approved pieces are out of the queue: 404.
    MarketMakerApproval::query()->create(['listing_id' => $this->listing->listing_id, 'approved_by' => $staff->staff_id, 'asking_price' => '1000']);
    $this->postJson("/api/v1/dashboard/market-maker/queue/{$this->listing->listing_id}/leave", [], Promo::key())->assertNotFound();
});

it('opens a file of a piece in the queue for the approver, audited, and no one else', function () {
    $media = ListingMedia::query()->where('listing_id', $this->listing->listing_id)->first();
    $url = "/api/v1/dashboard/market-maker/queue/{$this->listing->listing_id}/media/{$media->media_id}";

    Orders::staff($this, SeedRole::OPERATIONS);
    $this->getJson($url)->assertForbidden();

    $staff = Orders::staff($this, SeedRole::FINANCE);
    // The fixture media has no file on the fake disk; the open is authorised and audited first.
    try {
        $this->get($url);
    } catch (Throwable) {
    }
    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'market_maker.media_viewed')->first());
    expect($audit)->not->toBeNull()->and($audit->actor_staff_id)->toBe($staff->staff_id);

    // A piece that is not live is not served.
    Listing::query()->whereKey($this->listing->listing_id)->update(['state' => 'suspended_hold']);
    $this->getJson($url)->assertNotFound();
});

/** A paid order of `$listing` with a market-maker code on its request (as MarketMakerSettlementTest does). */
function dealerSale($t): Order
{
    $order = Orders::accepted($t);
    $buyer = Orders::buyer($order);
    $code = Promo::dealerCode($buyer);
    DatabaseActor::elevate('maintenance', fn () => DB::table('buy_request')->where('buy_request_id', $order->buy_request_id)->update(['promo_code' => $code->code]));
    Orders::inspected($t, $order);
    Orders::pay($t, $buyer, $order)->assertOk();

    return $order->refresh();
}

it('sums the stored figures of the month: commission and spread given up, and counts the unrecorded', function () {
    // Staged below: a pre-spec sale is simulated by clearing the stored figures of one order.
    DB::statement('ALTER TABLE "order" DISABLE TRIGGER trg_order_cost_frozen');
    $sale = dealerSale($this);
    expect($sale->commission_waiver_kind)->toBe('market_maker')->and($sale->commission_given_up)->toBe('516.0000') // net of the 600 VAT-inclusive (package C)
        ->and($sale->spread_given_up)->toBe('262.5000');

    // A pre-spec market-maker sale: settled, no stored figures.
    $old = dealerSale($this);
    DatabaseActor::elevate('maintenance', function () use ($old) {
        DB::table('order')->where('order_id', $old->order_id)->update([
            'commission_ordinary_amount' => null, 'commission_given_up' => null, 'commission_waiver_kind' => null,
            'commission_floor_egp' => null, 'commission_floor_applied' => null, 'spread_given_up' => null, 'settled_at' => null,
        ]);
    });

    Orders::staff($this, SeedRole::FINANCE);
    $month = now('Africa/Cairo')->format('Y-m');
    $this->getJson("/api/v1/dashboard/market-maker/cost?month={$month}")->assertOk()
        ->assertJsonPath('data.pieces_bought', 2)
        ->assertJsonPath('data.commission_given_up_egp', '516.0000')
        ->assertJsonPath('data.spread_given_up_egp', '262.5000')
        ->assertJsonPath('data.total_cost_egp', '778.5000')
        ->assertJsonPath('data.not_recorded', 1);

    // Last month has nothing.
    $this->getJson('/api/v1/dashboard/market-maker/cost?month='.now('Africa/Cairo')->subMonthNoOverflow()->format('Y-m'))
        ->assertOk()->assertJsonPath('data.pieces_bought', 0)->assertJsonPath('data.total_cost_egp', '0.0000');
});
