<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\PieceCategory;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US3 scenarios 1, 3, 8; FR-020–FR-022, FR-025; contract GET /market/listings.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
});

function liveListing(Customer $seller, array $attributes = [], string $category = 'gold'): Listing
{
    $factory = Listing::factory()->withPhotos(2);
    $factory = match ($category) {
        'diamond' => $factory->diamond(),
        'gold_with_diamond' => $factory->goldWithDiamond(),
        default => $factory,
    };

    return $factory->live()->create(['seller_id' => $seller->customer_id] + $attributes);
}

function market($test, string $query = '')
{
    return Listings::anonymous($test)->getJson(Listings::MARKET_URL.$query);
}

function marketListIds($test, string $query = ''): array
{
    return array_column(market($test, $query)->assertOk()->json('data'), 'id');
}

it('lists only what is on the market, newest first, with no token', function () {
    $old = liveListing($this->seller);
    $new = liveListing($this->seller);
    foreach (['draft', 'inReview', 'changesRequested', 'withdrawn', 'rejected', 'suspendedHold'] as $state) {
        Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    }

    $res = market($this)->assertOk()->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.next_cursor', null);

    expect(array_column($res->json('data'), 'id'))->toBe([$new->listing_id, $old->listing_id]);
});

it('returns exactly the market fields', function () {
    $listing = Listing::factory()->withPhotos(2)->withInvoice()->withCertificate()->live()->create(['seller_id' => $this->seller->customer_id]);

    $item = market($this)->assertOk()->json('data.0');

    expect(array_keys($item))->toBe([
        'id', 'category', 'piece_type', 'karat', 'weight_g', 'making_charge_per_g', 'current_price', 'price_available',
        'price_is_indicative', 'photos', 'branch_options', 'queue_count', 'listed_at', 'is_mine',
    ])
        ->and($item['id'])->toBe($listing->listing_id)
        ->and($item['category'])->toBe('gold')
        ->and($item['piece_type'])->toBe(['id' => $listing->piece_type_id, 'name_en' => 'Ring', 'name_ar' => 'خاتم'])
        ->and($item['karat'])->toBe(21)
        ->and($item['weight_g'])->toBe('8.000')
        ->and($item['making_charge_per_g'])->toBe('250.0000')
        ->and($item['queue_count'])->toBe(0)
        ->and($item['is_mine'])->toBeFalse()
        ->and($item['photos'])->toHaveCount(2)
        ->and(array_keys($item['photos'][0]))->toBe(['id', 'kind', 'is_private', 'mime', 'position', 'url'])
        ->and(array_unique(array_column($item['photos'], 'kind')))->toBe(['photo'])
        ->and($item['photos'][0]['url'])->toBe(Listings::MARKET_URL."/{$listing->listing_id}/media/".$item['photos'][0]['id'])
        ->and(array_keys($item['branch_options'][0]))->toBe(['id', 'name_en', 'name_ar'])
        ->and($item['listed_at'])->not->toBeNull();
});

it('filters by category, karat, piece type, branch and weight', function () {
    $ring21 = liveListing($this->seller, ['stated_weight_g' => '8.000']);
    $chain18 = liveListing($this->seller, ['karat_code' => 18, 'stated_weight_g' => '12.400', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::GOLD, 'Chain')]);
    $diamond = liveListing($this->seller, category: 'diamond');
    $mixed = liveListing($this->seller, ['stated_weight_g' => '6.100'], 'gold_with_diamond');
    // Created last, so no listing took it as its default branch.
    $branch = Branch::factory()->create();
    DB::table('listing_branch_option')->insert(['listing_id' => $chain18->listing_id, 'branch_id' => $branch->branch_id]);

    $sorted = fn (array $ids) => collect($ids)->sort()->values()->all();

    expect(marketListIds($this, '?category=gold'))->toEqualCanonicalizing([$ring21->listing_id, $chain18->listing_id])
        ->and(marketListIds($this, '?category=diamond'))->toBe([$diamond->listing_id])
        ->and(marketListIds($this, '?category=gold_with_diamond'))->toBe([$mixed->listing_id])
        ->and(marketListIds($this, '?karat=18'))->toBe([$chain18->listing_id])
        ->and(marketListIds($this, '?karat=21'))->toEqualCanonicalizing([$ring21->listing_id, $mixed->listing_id])
        ->and(marketListIds($this, '?piece_type='.Listings::pieceTypeId(PieceCategory::GOLD, 'Chain')))->toBe([$chain18->listing_id])
        ->and(marketListIds($this, '?branch='.$branch->branch_id))->toBe([$chain18->listing_id])
        ->and(marketListIds($this, '?min_g=8'))->toEqualCanonicalizing([$ring21->listing_id, $chain18->listing_id])
        ->and(marketListIds($this, '?max_g=8'))->toEqualCanonicalizing([$ring21->listing_id, $mixed->listing_id])
        ->and(marketListIds($this, '?min_g=7&max_g=10'))->toBe([$ring21->listing_id])
        ->and(marketListIds($this, '?category=gold&karat=18&min_g=12'))->toBe([$chain18->listing_id])
        ->and(marketListIds($this, '?category=gold&karat=24'))->toBe([])
        ->and($sorted(marketListIds($this)))->toHaveCount(4);
});

it('sorts by price, with pieces that cannot be priced last', function () {
    $cheap = liveListing($this->seller, ['stated_weight_g' => '2.000', 'making_charge_per_g' => '100.00']);
    $dear = liveListing($this->seller, ['stated_weight_g' => '30.000', 'making_charge_per_g' => '400.00']);
    $diamond = liveListing($this->seller, ['asking_price' => '60000.00'], 'diamond');
    // 22K has no usable price here: its adjustments are inverted on purpose.
    DB::table('karat')->where('karat_code', 22)->update(['is_enabled' => true]);
    $unpriced = liveListing($this->seller, ['karat_code' => 22]);
    DB::table('karat_price_adjustment')->where('karat_code', 22)->where('side', 'sell')->update(['kind' => 'fixed', 'value' => '-100000']);

    $asc = market($this, '?sort=price_asc')->assertOk()->json('data');
    $desc = market($this, '?sort=price_desc')->assertOk()->json('data');

    expect(array_column($asc, 'id'))->toBe([$cheap->listing_id, $diamond->listing_id, $dear->listing_id, $unpriced->listing_id])
        ->and(array_column($desc, 'id'))->toBe([$dear->listing_id, $diamond->listing_id, $cheap->listing_id, $unpriced->listing_id])
        ->and($asc[3]['current_price'])->toBeNull()
        ->and($asc[3]['price_available'])->toBeFalse()
        ->and(bccomp($asc[0]['current_price'], $asc[1]['current_price'], 4))->toBe(-1)
        ->and(bccomp($asc[1]['current_price'], $asc[2]['current_price'], 4))->toBe(-1);
});

it('pages by cursor in every sort without repeating or skipping', function (string $sort) {
    $all = [];
    foreach (range(1, 7) as $i) {
        $all[] = liveListing($this->seller, ['stated_weight_g' => (string) (3 + $i).'.000'])->listing_id;
    }
    // Two pieces with the same price: the tie is broken by id.
    $all[] = liveListing($this->seller, ['asking_price' => '50000.00'], 'diamond')->listing_id;
    $all[] = liveListing($this->seller, ['asking_price' => '50000.00'], 'diamond')->listing_id;

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $res = market($this, "?sort={$sort}&per_page=4".($cursor ? "&cursor={$cursor}" : ''))->assertOk();
        $seen = array_merge($seen, array_column($res->json('data'), 'id'));
        $cursor = $res->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)
        ->and($seen)->toHaveCount(9)
        ->and(array_unique($seen))->toHaveCount(9)
        ->and($seen)->toEqualCanonicalizing($all);

    // The pages joined are in the same order as one big page.
    expect($seen)->toBe(marketListIds($this, "?sort={$sort}&per_page=100"));
})->with(['newest', 'price_asc', 'price_desc']);

it('validates the filters, the sort, the page size and the cursor', function (string $query, string $field) {
    market($this, $query)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);
})->with([
    ['?category=silver', 'category'],
    ['?karat=abc', 'karat'],
    ['?sort=cheapest', 'sort'],
    ['?per_page=0', 'per_page'],
    ['?per_page=101', 'per_page'],
    ['?min_g=-1', 'min_g'],
    ['?max_g=1.2345', 'max_g'],
    ['?cursor=not-a-cursor', 'cursor'],
]);

it('accepts up to 100 per page', function () {
    liveListing($this->seller);

    market($this, '?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
});

it('marks a signed-in customer\'s own pieces, and only theirs', function () {
    $mine = liveListing($this->seller);
    $theirs = liveListing(Customer::factory()->verified()->create());
    $flags = fn ($res) => collect($res->json('data'))->pluck('is_mine', 'id')->all();

    $asSeller = Listings::as($this, $this->seller)->getJson(Listings::MARKET_URL)->assertOk();
    $asOther = Listings::as($this, Customer::factory()->verified()->create())->getJson(Listings::MARKET_URL)->assertOk();
    $anonymous = market($this);

    app('auth')->forgetGuards();
    $garbage = $this->withToken('not-a-real-token')->getJson(Listings::MARKET_URL)->assertOk();

    expect($flags($asSeller))->toBe([$theirs->listing_id => false, $mine->listing_id => true])
        ->and(array_filter($flags($asOther)))->toBe([])
        ->and(array_filter($flags($anonymous)))->toBe([])
        ->and(array_filter($flags($garbage)))->toBe([]);

    // A signed-in seller still sees only what is on the market — never their own drafts.
    $draft = Listing::factory()->withPhotos(2)->create(['seller_id' => $this->seller->customer_id]);
    expect(array_column(Listings::as($this, $this->seller)->getJson(Listings::MARKET_URL)->json('data'), 'id'))->not->toContain($draft->listing_id);
});

it('does not mark pieces for a refresh token or a staff token', function () {
    $mine = liveListing($this->seller);
    $session = app(IssueTokenFamilyAction::class)->forCustomer($this->seller);

    app('auth')->forgetGuards();
    $res = $this->withToken($session->refreshToken)->getJson(Listings::MARKET_URL)->assertOk();

    expect($res->json('data.0.id'))->toBe($mine->listing_id)->and($res->json('data.0.is_mine'))->toBeFalse();
});

it('loads a page in a bounded number of queries', function () {
    foreach (range(1, 10) as $i) {
        liveListing(Customer::factory()->verified()->create());
    }

    DB::enableQueryLog();
    market($this)->assertOk()->assertJsonCount(10, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(30);
});
