<?php

use App\Enums\PieceCategory;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 data-model: what the database itself guarantees for listings,
// whatever the application does (docs/Database schema/04_schema_market.sql §7,
// 05_schema_security.sql "Listing guards").

/** Run statements in a savepoint and return the SQLSTATE, or null when they succeed. */
function listingSqlState(Closure $work): ?string
{
    try {
        DB::transaction($work);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/**
 * The deferred "every move is recorded" trigger fires at commit. Tests run in
 * a transaction that never commits, so make it fire now (the pattern of
 * tests/Feature/Ledger/LedgerSchemaTest.php).
 */
function fireListingDeferred(): ?string
{
    $state = listingSqlState(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    return $state;
}

/** @param  array<string, mixed>  $overrides */
function listingRow(Customer $seller, array $overrides = []): string
{
    $id = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
    DB::table('listing')->insert(array_merge([
        'listing_id' => $id,
        'seller_id' => $seller->customer_id,
        'category' => 'gold',
        'piece_type_id' => Listings::pieceTypeId(PieceCategory::GOLD),
        'karat_code' => 21,
        'stated_weight_g' => '8.000',
        'making_charge_per_g' => '250.00',
    ], $overrides));

    return $id;
}

function listingChange(string $listingId, ?string $from, string $to, ?string $customerId, ?string $staffId = null, ?string $note = null): void
{
    DB::table('listing_state_change')->insert([
        'listing_id' => $listingId, 'from_state' => $from, 'to_state' => $to,
        'actor_customer_id' => $customerId, 'actor_staff_id' => $staffId, 'note' => $note,
    ]);
}

/** Insert a listing with its creation row, then walk `$path` with history rows. */
function listingAt(Customer $seller, array $path = []): string
{
    $id = listingRow($seller);
    listingChange($id, null, 'draft', $seller->customer_id);
    $from = 'draft';

    foreach ($path as $to) {
        DB::table('listing')->where('listing_id', $id)->update(['state' => $to]);
        listingChange($id, $from, $to, null, SystemActor::id(), 'note for '.$to);
        $from = $to;
    }

    return $id;
}

beforeEach(function () {
    $this->seller = Customer::factory()->verified()->create();
});

it('defines the listing states, with rejected', function () {
    $states = collect(DB::select('SELECT unnest(enum_range(NULL::listing_state))::text AS v'))->pluck('v')->all();

    expect($states)->toContain('draft', 'in_review', 'changes_requested', 'live', 'reserved', 'withdrawn', 'suspended_hold', 'rejected')
        ->and($states)->toHaveCount(15);
});

it('seeds the allowed moves, with no way out of withdrawn or rejected', function () {
    $moves = DB::table('listing_transition')->get()->map(fn ($r) => $r->from_state.'>'.$r->to_state)->all();

    expect($moves)->toContain('draft>in_review', 'in_review>live', 'in_review>changes_requested', 'in_review>rejected',
        'changes_requested>in_review', 'live>withdrawn', 'live>suspended_hold', 'suspended_hold>live')
        ->and(DB::table('listing_transition')->whereIn('from_state', ['withdrawn', 'rejected'])->count())->toBe(0);
});

it('refuses rows of the wrong shape', function (array $overrides) {
    expect(listingSqlState(fn () => listingRow($this->seller, $overrides)))->toBe('23514');
})->with([
    'gold without a karat' => [['karat_code' => null]],
    'gold without a weight' => [['stated_weight_g' => null]],
    'gold with an asking price' => [['asking_price' => '1000.00']],
    'gold without a making charge' => [['making_charge_per_g' => null]],
    'diamond without an asking price' => [['category' => 'diamond', 'karat_code' => null, 'stated_weight_g' => null, 'making_charge_per_g' => null]],
    'diamond with a making charge' => [['category' => 'diamond', 'karat_code' => null, 'stated_weight_g' => null, 'asking_price' => '1000.00']],
    'a weight of zero' => [['stated_weight_g' => '0']],
    'a negative making charge' => [['making_charge_per_g' => '-1']],
    'a making charge with three decimals' => [['making_charge_per_g' => '250.125']],
    'an asking price with three decimals' => [['category' => 'diamond', 'karat_code' => null, 'stated_weight_g' => null, 'making_charge_per_g' => null, 'asking_price' => '1000.125']],
    'a description of 2001 characters' => [['description' => str_repeat('a', 2001)]],
]);

it('accepts a valid listing of each category', function () {
    expect(listingSqlState(fn () => listingRow($this->seller)))->toBeNull()
        ->and(listingSqlState(fn () => listingRow($this->seller, [
            'category' => 'diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::DIAMOND),
            'karat_code' => null, 'stated_weight_g' => null, 'making_charge_per_g' => null, 'asking_price' => '120000.00',
        ])))->toBeNull()
        ->and(listingSqlState(fn () => listingRow($this->seller, [
            'category' => 'gold_with_diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::GOLD_WITH_DIAMOND),
            'making_charge_per_g' => null, 'asking_price' => '80150.00',
        ])))->toBeNull();
});

it('starts every listing as a draft', function () {
    expect(listingSqlState(fn () => listingRow($this->seller, ['state' => 'live', 'listed_at' => now()])))->toBe('DH004');
});

it('refuses a move that is not in listing_transition', function (array $path, string $to) {
    $id = listingAt($this->seller, $path);

    expect(listingSqlState(fn () => DB::table('listing')->where('listing_id', $id)->update(['state' => $to])))->toBe('DH004');
})->with([
    'draft to live' => [[], 'live'],
    'draft to rejected' => [[], 'rejected'],
    'withdrawn to live' => [['in_review', 'live', 'withdrawn'], 'live'],
    'withdrawn to in review' => [['in_review', 'live', 'withdrawn'], 'in_review'],
    'rejected to in review' => [['in_review', 'rejected'], 'in_review'],
    'rejected to live' => [['in_review', 'rejected'], 'live'],
    'changes requested to live' => [['in_review', 'changes_requested'], 'live'],
    'live to rejected' => [['in_review', 'live'], 'rejected'],
]);

it('allows the moves this feature uses', function () {
    expect(listingSqlState(fn () => listingAt($this->seller, ['in_review', 'changes_requested', 'in_review', 'live', 'suspended_hold', 'live', 'withdrawn'])))->toBeNull()
        ->and(listingSqlState(fn () => listingAt($this->seller, ['in_review', 'rejected'])))->toBeNull()
        ->and(fireListingDeferred())->toBeNull();
});

it('stamps the state change and sets listed_at once', function () {
    $id = listingAt($this->seller, ['in_review']);
    expect(DB::table('listing')->where('listing_id', $id)->value('listed_at'))->toBeNull();

    DB::table('listing')->where('listing_id', $id)->update(['state_changed_at' => '2026-01-01 00:00:00+02']);
    DB::table('listing')->where('listing_id', $id)->update(['state' => 'live']);
    $row = DB::table('listing')->where('listing_id', $id)->first();

    expect($row->listed_at)->not->toBeNull()
        ->and(str_starts_with((string) $row->state_changed_at, '2026-01-01'))->toBeFalse();

    DB::table('listing')->where('listing_id', $id)->update(['listed_at' => '2026-02-02 10:00:00+02']);
    DB::table('listing')->where('listing_id', $id)->update(['state' => 'suspended_hold']);
    DB::table('listing')->where('listing_id', $id)->update(['state' => 'live']);

    expect((string) DB::table('listing')->where('listing_id', $id)->value('listed_at'))->toStartWith('2026-02-02');
});

it('refuses a creation with no history row when the transaction ends', function () {
    listingRow($this->seller);

    expect(fireListingDeferred())->toBe('DH004');
});

it('refuses a move with no history row when the transaction ends', function () {
    $id = listingAt($this->seller);
    expect(fireListingDeferred())->toBeNull();

    DB::table('listing')->where('listing_id', $id)->update(['state' => 'in_review']);
    expect(fireListingDeferred())->toBe('DH004');

    listingChange($id, 'draft', 'in_review', $this->seller->customer_id);
    expect(fireListingDeferred())->toBeNull();
});

it('never changes the seller or the creation time, and never deletes a listing', function () {
    $id = listingAt($this->seller);
    $other = Customer::factory()->verified()->create();

    expect(listingSqlState(fn () => DB::table('listing')->where('listing_id', $id)->update(['seller_id' => $other->customer_id])))->toBe('DH004')
        ->and(listingSqlState(fn () => DB::table('listing')->where('listing_id', $id)->update(['created_at' => now()->subDay()])))->toBe('DH004')
        ->and(listingSqlState(fn () => DB::table('listing')->where('listing_id', $id)->delete()))->toBe('DH004');
});

it('keeps the history append-only and attributed', function () {
    $id = listingAt($this->seller, ['in_review']);
    $staff = SystemActor::id();

    expect(listingSqlState(fn () => DB::table('listing_state_change')->where('listing_id', $id)->update(['note' => 'edited'])))->toBe('DH004')
        ->and(listingSqlState(fn () => DB::table('listing_state_change')->where('listing_id', $id)->delete()))->toBe('DH004')
        ->and(listingSqlState(fn () => listingChange($id, 'in_review', 'live', null, null)))->toBe('23514')
        ->and(listingSqlState(fn () => listingChange($id, 'in_review', 'live', $this->seller->customer_id, $staff)))->toBe('23514')
        ->and(listingSqlState(fn () => listingChange($id, 'in_review', 'changes_requested', null, $staff, null)))->toBe('23514')
        ->and(listingSqlState(fn () => listingChange($id, 'in_review', 'rejected', null, $staff, null)))->toBe('23514')
        ->and(listingSqlState(fn () => listingChange($id, 'live', 'withdrawn', null, $staff, null)))->toBe('23514')
        ->and(listingSqlState(fn () => listingChange($id, 'live', 'withdrawn', $this->seller->customer_id, null, null)))->toBeNull()
        ->and(listingSqlState(fn () => listingChange($id, 'in_review', 'rejected', null, $staff, str_repeat('a', 1001))))->toBe('23514');
});

it('lets a seller scope write history only in its own name', function () {
    $id = listingAt($this->seller);
    $other = Customer::factory()->verified()->create();
    $staff = SystemActor::id();

    DatabaseActor::push('customer', customerId: $this->seller->customer_id);

    try {
        $asOther = listingSqlState(fn () => listingChange($id, 'draft', 'in_review', $other->customer_id));
        $asStaff = listingSqlState(fn () => listingChange($id, 'draft', 'in_review', null, $staff));
        $asSelf = listingSqlState(fn () => listingChange($id, 'draft', 'in_review', $this->seller->customer_id));
    } finally {
        DatabaseActor::pop();
    }

    expect($asOther)->toBe('42501')->and($asStaff)->toBe('42501')->and($asSelf)->toBeNull();
});

it('keeps legal documents versioned and acceptances append-only', function () {
    $doc = DB::table('legal_document')->where('code', 'ownership_declaration')->first();

    expect($doc->version)->toBe(1)
        ->and($doc->published_by)->toBe(SystemActor::id())
        ->and(listingSqlState(fn () => DB::table('legal_document')->insert([
            'code' => 'ownership_declaration', 'version' => 1, 'body_en' => 'x', 'body_ar' => 'x', 'published_by' => SystemActor::id(),
        ])))->toBe('23505');

    DB::table('agreement_acceptance')->insert([
        'customer_id' => $this->seller->customer_id, 'legal_doc_id' => $doc->legal_doc_id, 'context' => 'list_piece',
    ]);

    expect(listingSqlState(fn () => DB::table('agreement_acceptance')->update(['context' => 'other'])))->not->toBeNull()
        ->and(listingSqlState(fn () => DB::table('agreement_acceptance')->delete()))->not->toBeNull();
});

it('builds every factory state through legal, recorded moves', function () {
    Storage::fake('identity_private');

    foreach (['inReview', 'changesRequested', 'live', 'rejected', 'withdrawn', 'suspendedHold'] as $state) {
        Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    }

    expect(fireListingDeferred())->toBeNull()
        ->and(Listing::query()->pluck('state')->map->value->sort()->values()->all())
        ->toBe(['changes_requested', 'in_review', 'live', 'rejected', 'suspended_hold', 'withdrawn']);
});
