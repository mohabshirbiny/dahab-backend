<?php

use App\Models\Listing;
use App\Models\ListingPriceChange;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// Spec 022 T006 (data-model §1): the listing_price_change table, its CHECKs and append-only
// guard, the DH023 price guards, listing_price_locked(), and the refusing down(). Direct SQL;
// each attempt runs in a savepoint so a refusal does not abort the test's transaction.

/** The SQLSTATE a write is refused with, or null when it succeeds. */
function lpcSqlState(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->errorInfo[0];
    }

    return null;
}

/** The deferred "price change is recorded" trigger fires at commit; fire it now, then defer again. */
function lpcFireDeferred(): ?string
{
    $state = lpcSqlState(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    return $state;
}

function lpcMigration(): object
{
    return require base_path('database/migrations/2026_10_15_000010_listing_price_change.php');
}

function lpcSetPrice(Listing $l, string $value): void
{
    DB::table('listing')->where('listing_id', $l->listing_id)->update([$l->priceField()->value => $value]);
}

it('adds the table with its columns', function () {
    expect(Schema::hasTable('listing_price_change'))->toBeTrue()
        ->and(Schema::hasColumns('listing_price_change', [
            'change_id', 'listing_id', 'field', 'old_value', 'new_value', 'actor_customer_id', 'actor_staff_id', 'changed_at', 'txid',
        ]))->toBeTrue();
});

it('holds the history CHECKs', function () {
    $l = Listing::factory()->withPhotos(2)->live()->create();
    $row = fn (array $set) => fn () => DB::table('listing_price_change')->insert($set + [
        'listing_id' => $l->listing_id, 'field' => 'making_charge_per_g', 'old_value' => '250.00', 'new_value' => '220.00',
        'actor_customer_id' => $l->seller_id,
    ]);

    expect(lpcSqlState($row(['field' => 'weight'])))->toBe('23514')
        ->and(lpcSqlState($row(['new_value' => '250.00'])))->toBe('23514')              // no-op
        ->and(lpcSqlState($row(['new_value' => '220.005'])))->toBe('23514')              // more than 2 decimals
        ->and(lpcSqlState($row(['new_value' => '-1'])))->toBe('23514')
        ->and(lpcSqlState($row(['field' => 'asking_price', 'old_value' => '100', 'new_value' => '0'])))->toBe('23514')
        ->and(lpcSqlState($row(['actor_customer_id' => null])))->toBe('23514')            // no actor
        ->and(lpcSqlState($row([])))->toBeNull();
});

it('keeps the history append-only with DH023', function () {
    $l = Listing::factory()->withPhotos(2)->live()->create();
    $c = ListingPriceChange::factory()->create(['listing_id' => $l->listing_id]);

    expect(lpcSqlState(fn () => DB::table('listing_price_change')->where('change_id', $c->change_id)->update(['new_value' => '1.00'])))->toBe('DH023')
        ->and(lpcSqlState(fn () => DB::table('listing_price_change')->where('change_id', $c->change_id)->delete()))->toBe('DH023');
});

it('refuses a price write outside draft, changes requested, in review and live with DH023', function () {
    foreach ([
        Listing::factory()->withPhotos(2)->suspendedHold()->create(),
        Listing::factory()->withPhotos(2)->withdrawn()->create(),
    ] as $l) {
        expect(lpcSqlState(fn () => lpcSetPrice($l, '240.00')))->toBe('DH023');
    }

    $reserved = Listing::factory()->withPhotos(2)->live()->create();
    DB::table('listing')->where('listing_id', $reserved->listing_id)->update(['state' => 'reserved']);
    expect(lpcSqlState(fn () => lpcSetPrice($reserved->refresh(), '240.00')))->toBe('DH023');
});

it('lets a draft and a changes-requested price be edited with no history row', function () {
    $draft = Listing::factory()->withPhotos(2)->create();
    $sent = Listing::factory()->withPhotos(2)->changesRequested()->create();

    expect(lpcSqlState(fn () => lpcSetPrice($draft, '240.00')))->toBeNull()
        ->and(lpcSqlState(fn () => lpcSetPrice($sent, '240.00')))->toBeNull()
        ->and(lpcFireDeferred())->toBeNull();
});

it('refuses a live price change that has no history row, at commit', function () {
    $l = Listing::factory()->withPhotos(2)->live()->create();

    DB::table('listing')->where('listing_id', $l->listing_id)->update(['making_charge_per_g' => '230.00']);

    expect(lpcFireDeferred())->toBe('DH023');
});

it('refuses an in-review price change that has no history row, at commit', function () {
    $l = Listing::factory()->withPhotos(2)->inReview()->create();

    DB::table('listing')->where('listing_id', $l->listing_id)->update(['making_charge_per_g' => '230.00']);

    expect(lpcFireDeferred())->toBe('DH023');
});

it('accepts a live price change with its history row in the same transaction', function () {
    $l = Listing::factory()->withPhotos(2)->live()->create();

    DB::table('listing')->where('listing_id', $l->listing_id)->update(['making_charge_per_g' => '230.00']);
    ListingPriceChange::query()->create([
        'listing_id' => $l->listing_id, 'field' => 'making_charge_per_g', 'old_value' => '250.00', 'new_value' => '230.00',
        'actor_customer_id' => $l->seller_id,
    ]);

    expect(lpcFireDeferred())->toBeNull();
});

it('refuses a live price change of a piece approved for market makers with DH023', function () {
    $l = Listing::factory()->withPhotos(2)->approvedForMarketMakers()->create();

    expect(lpcSqlState(fn () => lpcSetPrice($l->refresh(), '230.00')))->toBe('DH023');
});

it('refuses a price change together with a state move with DH023', function () {
    $l = Listing::factory()->withPhotos(2)->live()->create();

    expect(lpcSqlState(fn () => DB::table('listing')->where('listing_id', $l->listing_id)
        ->update(['state' => 'withdrawn', 'making_charge_per_g' => '230.00'])))->toBe('DH023');
});

it('answers listing_price_locked() from the customer scope without widening it', function () {
    $free = Listing::factory()->withPhotos(2)->live()->create();
    $locked = Listing::factory()->withPhotos(2)->approvedForMarketMakers()->create();

    $ask = fn (string $id): bool => (bool) DB::selectOne('SELECT listing_price_locked(?) AS locked', [$id])->locked;

    DatabaseActor::push('customer', customerId: $locked->seller_id);
    try {
        expect($ask($free->listing_id))->toBeFalse()
            ->and($ask($locked->listing_id))->toBeTrue()
            // The scope is put back: the approval rows stay unreadable to this caller.
            ->and(DB::table('market_maker_approval')->count())->toBe(0);
    } finally {
        DatabaseActor::pop();
    }
});

it('refuses to roll back once a price change exists, and rolls back clean without', function () {
    lpcMigration()->down();
    expect(Schema::hasTable('listing_price_change'))->toBeFalse();
    lpcMigration()->up();

    ListingPriceChange::factory()->create();

    expect(fn () => lpcMigration()->down())->toThrow(RuntimeException::class, 'cannot be rolled back');
});
