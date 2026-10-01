<?php

use App\Enums\ListingMediaKind;
use App\Http\Resources\Market\MarketListingDetailResource;
use App\Http\Resources\Market\MarketListingResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use Database\Factories\ListingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-020, SC-004; Part 1 §5.3 (product-owner decision: NO database
// view). The market reads `listing` in the `market` scope and returns only
// the market Resources. Those shapes are the column boundary, and this test
// is the guard: a seller field or a private file in any public response
// fails the build.

const FORBIDDEN_KEYS = [
    'seller', 'seller_id', 'customer', 'customer_id', 'display_ref', 'full_name', 'name', 'phone', 'phone_masked', 'email',
    'storage_ref', 'staff_message', 'you_would_receive', 'history', 'state', 'asking_price',
];

const MARKET_KEYS = [
    'id', 'category', 'piece_type', 'karat', 'weight_g', 'making_charge_per_g', 'current_price', 'price_available',
    'price_is_indicative', 'photos', 'branch_options', 'queue_count', 'listed_at', 'is_mine',
];

const MARKET_DETAIL_KEYS = ['description', 'video', 'stone_certificate', 'price_parts', 'deposit_amount'];

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create([
        'display_ref' => '008842', 'full_name' => 'Sara Mostafa Abdelrahman', 'phone' => '+201012348842', 'email' => 'sara.abdelrahman@example.test',
    ]);

    $make = fn ($factory) => $factory->withPhotos(3)->withInvoice()->withCertificate()->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->listings = [
        $make(Listing::factory()),
        $make(Listing::factory()->diamond()),
        $make(Listing::factory()->goldWithDiamond()),
    ];
    foreach ($this->listings as $listing) {
        ListingFactory::attachMedia($listing, ListingMediaKind::VIDEO, Listings::mp4(), 'video/mp4');
    }

    $this->secrets = [
        'seller id' => $this->seller->customer_id,
        'display reference' => '008842',
        'name' => 'Abdelrahman',
        'first name' => 'Sara',
        'phone' => '201012348842',
        'phone tail' => '12348842',
        'email' => 'sara.abdelrahman',
    ];
    $this->privateMedia = DB::table('listing_media')->where('is_private', true)->pluck('media_id')->all();
    $this->storageRefs = DB::table('listing_media')->pluck('storage_ref')->all();
});

/** Every key used anywhere in a decoded JSON document. */
function allKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, allKeys($child));
    }

    return array_values(array_unique($keys));
}

/** @return list<string> every market and reference URL of the fixtures */
function publicUrls(array $listings): array
{
    $urls = [
        Listings::MARKET_URL,
        Listings::MARKET_URL.'?sort=price_asc',
        Listings::MARKET_URL.'?sort=price_desc&per_page=2',
        Listings::MARKET_URL.'?category=gold',
        '/api/v1/reference/karats',
        '/api/v1/reference/piece-types',
        '/api/v1/reference/branches',
        '/api/v1/reference/legal-documents/ownership_declaration',
    ];

    foreach ($listings as $listing) {
        $urls[] = Listings::MARKET_URL.'/'.$listing->listing_id;
    }

    return $urls;
}

it('has at least one private file and one seller to leak', function () {
    expect($this->privateMedia)->toHaveCount(3)->and(Branch::query()->count())->toBeGreaterThan(0);
});

it('never returns a seller or private field, to anyone', function (Closure $as) {
    foreach (publicUrls($this->listings) as $url) {
        $res = $as($this)->getJson($url)->assertOk();
        $json = $res->json();
        $raw = (string) $res->getContent();

        expect(array_values(array_intersect(allKeys($json), FORBIDDEN_KEYS)))->toBe([], "forbidden key in {$url}");

        foreach ($this->secrets as $label => $secret) {
            expect(str_contains($raw, $secret))->toBeFalse("the seller's {$label} is in {$url}");
        }
        foreach ($this->privateMedia as $id) {
            expect(str_contains($raw, $id))->toBeFalse("a private media id is in {$url}");
        }
        foreach ($this->storageRefs as $ref) {
            expect(str_contains($raw, $ref))->toBeFalse("a storage key is in {$url}");
        }
        expect(str_contains($raw, 'listing-media/'))->toBeFalse("a storage path is in {$url}");
    }
})->with([
    'an anonymous visitor' => [fn ($test) => Listings::anonymous($test)],
    'another customer' => [fn ($test) => Listings::as($test, Customer::factory()->verified()->create())],
    'the seller themself' => [fn ($test) => Listings::as($test, $test->seller)],
]);

it('returns exactly the contract\'s fields', function () {
    $list = Listings::anonymous($this)->getJson(Listings::MARKET_URL)->json('data');
    $detail = Listings::anonymous($this)->getJson(Listings::MARKET_URL.'/'.$this->listings[0]->listing_id)->json('data');

    foreach ($list as $item) {
        expect(array_keys($item))->toBe(MARKET_KEYS);
    }

    expect(array_keys($detail))->toBe([...MARKET_KEYS, ...MARKET_DETAIL_KEYS]);
});

it('keeps the market Resources free of seller fields, whatever model they are given', function () {
    // A listing loaded with EVERYTHING, as a careless query might: the shape still leaks nothing.
    $listing = Listing::query()->with(['seller', 'pieceType', 'media', 'branches', 'changes', 'declaration'])->findOrFail($this->listings[0]->listing_id);
    $request = Request::create('/api/v1/market/listings');

    foreach ([MarketListingResource::class, MarketListingDetailResource::class] as $resource) {
        $raw = (string) json_encode((new $resource($listing))->resolve($request));

        expect(array_values(array_intersect(allKeys(json_decode($raw, true)), FORBIDDEN_KEYS)))->toBe([], $resource);

        foreach ([...array_values($this->secrets), ...$this->privateMedia] as $secret) {
            expect(str_contains($raw, $secret))->toBeFalse($resource);
        }
    }
});

it('gives the only seller-related signal as a boolean', function () {
    $mine = Listings::as($this, $this->seller)->getJson(Listings::MARKET_URL)->json('data');

    expect(array_unique(array_column($mine, 'is_mine')))->toBe([true]);
});
