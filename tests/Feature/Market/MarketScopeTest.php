<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 research R2, Part 1 §5.3 (product-owner decision: no view): the
// public market reads in the `market` row-level-security scope. The engine
// gives it only live/reserved listings, their branch options and their
// non-private media, and lets it write nothing.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seller = Customer::factory()->verified()->create();
    $make = fn (string $state) => Listing::factory()->withPhotos(2)->withInvoice()->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    $this->live = $make('live');
    $this->draft = $make('draft');
    $this->waiting = $make('inReview');
    $this->withdrawn = $make('withdrawn');
    $this->rejected = $make('rejected');
    $this->held = $make('suspendedHold');
    DB::table('listing_ownership_declaration')->insert(['listing_id' => $this->live->listing_id, 'customer_id' => $this->seller->customer_id, 'legal_doc_id' => Listings::declarationId()]);
    DB::table('agreement_acceptance')->insert(['customer_id' => $this->seller->customer_id, 'legal_doc_id' => Listings::declarationId(), 'context' => 'list_piece']);
});

function inMarketScope(Closure $work): mixed
{
    return DatabaseActor::market($work);
}

it('is not an elevation', function () {
    expect(DatabaseActor::SCOPES)->toContain('market')
        ->and(DatabaseActor::ELEVATED)->not->toContain('market')
        ->and(inMarketScope(fn () => DatabaseActor::isElevated()))->toBeFalse()
        ->and(inMarketScope(fn () => DB::selectOne('SELECT dahab_rls_elevated() AS e')->e))->toBeFalse()
        ->and(fn () => DatabaseActor::elevate('market', fn () => null))->toThrow(InvalidArgumentException::class);
});

it('sees only listings that are on the market', function () {
    $ids = inMarketScope(fn () => DB::table('listing')->pluck('listing_id')->all());

    expect($ids)->toBe([$this->live->listing_id]);
});

it('sees only the public media and the branch options of those listings', function () {
    [$media, $branches] = inMarketScope(fn () => [
        DB::table('listing_media')->get(['listing_id', 'kind', 'is_private']),
        DB::table('listing_branch_option')->pluck('listing_id')->unique()->values()->all(),
    ]);

    expect($media->pluck('listing_id')->unique()->values()->all())->toBe([$this->live->listing_id])
        ->and($media->pluck('kind')->sort()->values()->all())->toBe(['photo', 'photo'])
        ->and($media->where('is_private', true))->toHaveCount(0)
        ->and($branches)->toBe([$this->live->listing_id]);
});

it('sees nothing of what is not the market\'s business', function (string $table) {
    expect(inMarketScope(fn () => DB::table($table)->count()))->toBe(0);
})->with([
    'listing_state_change', 'listing_ownership_declaration', 'listing_queue_seq', 'agreement_acceptance',
    'customer', 'identity_document', 'topup', 'account', 'ledger_posting', 'audit_log',
]);

it('carries no customer, so a seller\'s own drafts stay out', function () {
    $customerId = inMarketScope(fn () => DB::selectOne('SELECT dahab_current_customer_id() AS id')->id);

    // Even inside a customer's request, entering the market scope drops the customer id.
    DatabaseActor::push('customer', customerId: $this->seller->customer_id);
    try {
        $ids = inMarketScope(fn () => DB::table('listing')->pluck('listing_id')->all());
    } finally {
        DatabaseActor::pop();
    }

    expect($customerId)->toBeNull()->and($ids)->toBe([$this->live->listing_id]);
});

it('can write nothing', function () {
    $refused = function (Closure $work): bool {
        try {
            return DB::transaction($work) === 0;
        } catch (QueryException) {
            return true;
        }
    };

    $results = inMarketScope(fn () => [
        'update listing' => $refused(fn () => DB::table('listing')->update(['description' => 'defaced'])),
        'delete media' => $refused(fn () => DB::table('listing_media')->delete()),
        'update media' => $refused(fn () => DB::table('listing_media')->update(['is_private' => false])),
        'delete branches' => $refused(fn () => DB::table('listing_branch_option')->delete()),
        'insert listing' => $refused(fn () => DB::table('listing')->insert([
            'seller_id' => $this->seller->customer_id, 'category' => 'diamond', 'piece_type_id' => $this->live->piece_type_id, 'asking_price' => '1.00',
        ]) ? 1 : 0),
        'insert media' => $refused(fn () => DB::table('listing_media')->insert([
            'listing_id' => $this->live->listing_id, 'kind' => 'photo', 'storage_ref' => 'x', 'mime' => 'image/png',
        ]) ? 1 : 0),
        'insert history' => $refused(fn () => DB::table('listing_state_change')->insert([
            'listing_id' => $this->live->listing_id, 'from_state' => 'live', 'to_state' => 'withdrawn', 'actor_customer_id' => $this->seller->customer_id,
        ]) ? 1 : 0),
    ]);

    expect(array_filter($results, fn (bool $ok) => ! $ok))->toBe([])
        ->and($this->live->fresh()->description)->not->toBe('defaced')
        ->and(DB::table('listing_media')->where('listing_id', $this->live->listing_id)->count())->toBe(3);
});

it('is declared on exactly the market routes, and never on an authenticated one', function () {
    $withScope = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('db.market', $route->gatherMiddleware(), true));

    expect($withScope->map->getName()->sort()->values()->all())->toBe([
        'api.v1.market.listings.index',
        'api.v1.market.listings.media',
        'api.v1.market.listings.show',
    ]);

    foreach ($withScope as $route) {
        $auth = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'customer.gate') || str_starts_with($m, 'staff.')));

        expect($auth->all())->toBe([], $route->getName())
            ->and($route->gatherMiddleware())->toContain('throttle:public.market');
    }

    // Every route under /market carries the scope.
    $market = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/market'));
    expect($market->count())->toBe($withScope->count());
});

it('answers the market routes without a token', function () {
    $photo = $this->live->media()->where('kind', 'photo')->first();

    Listings::anonymous($this)->getJson(Listings::MARKET_URL)->assertOk()->assertJsonCount(1, 'data');
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->live->listing_id}")->assertOk();
    Listings::anonymous($this)->get(Listings::MARKET_URL."/{$this->live->listing_id}/media/{$photo->media_id}")->assertOk();
});

it('leaves no scope behind after a market request', function () {
    Listings::anonymous($this)->getJson(Listings::MARKET_URL)->assertOk();

    expect(DatabaseActor::scope())->toBe('maintenance');
});
