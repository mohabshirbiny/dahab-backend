<?php

use App\Enums\ListingMediaKind;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use Database\Factories\ListingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US3 scenarios 2, 4, 6, 7; FR-011, FR-022–FR-024: the public piece
// page, its price and its files.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->price = Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->withInvoice()->withCertificate()->live()->create(['seller_id' => $this->seller->customer_id]);
    $this->video = ListingFactory::attachMedia($this->listing, ListingMediaKind::VIDEO, Listings::mp4(), 'video/mp4');
});

function piece($test, Listing|string $listing)
{
    return Listings::anonymous($test)->getJson(Listings::MARKET_URL.'/'.($listing instanceof Listing ? $listing->listing_id : $listing));
}

function pieceMedia($test, Listing $listing, ListingMedia|string $media)
{
    return Listings::anonymous($test)->get(Listings::MARKET_URL."/{$listing->listing_id}/media/".($media instanceof ListingMedia ? $media->media_id : $media), ['Accept' => 'application/json']);
}

it('shows a live piece in full, with no token', function () {
    $data = piece($this, $this->listing)->assertOk()->json('data');

    expect(array_keys($data))->toBe([
        'id', 'category', 'piece_type', 'karat', 'weight_g', 'making_charge_per_g', 'current_price', 'price_available',
        'price_is_indicative', 'photos', 'branch_options', 'queue_count', 'listed_at', 'is_mine',
        'description', 'video', 'stone_certificate', 'price_parts',
    ])
        ->and($data['description'])->toBe($this->listing->description)
        ->and($data['photos'])->toHaveCount(2)
        ->and($data['video']['id'])->toBe($this->video->media_id)
        ->and($data['video']['kind'])->toBe('video')
        ->and($data['stone_certificate']['kind'])->toBe('stone_certificate')
        ->and($data['stone_certificate']['is_private'])->toBeFalse();
});

it('prices gold with the calculator, at the current gold price', function () {
    $context = app(PricingContext::class);
    $expected = $context->breakdown(Piece::gold($context->pricing(21), '8.000', '250.00'));

    piece($this, $this->listing)->assertOk()
        ->assertJsonPath('data.current_price', $expected->buyerTotal)
        ->assertJsonPath('data.price_available', true)
        ->assertJsonPath('data.price_is_indicative', true)
        ->assertJsonPath('data.price_parts.rate_per_gram', $expected->karatPrices->buyersPay)
        ->assertJsonPath('data.price_parts.making_total', '2000.0000')
        ->assertJsonPath('data.price_parts.gold_value', bcadd(bcmul($expected->karatPrices->buyersPay, '8', 8), '0', 4));

    // buyers_pay × weight + making charge × weight (Part 3 §2.3).
    expect(bcadd(bcmul($expected->karatPrices->buyersPay, '8', 8), '2000', 4))->toBe($expected->buyerTotal);
});

it('follows the gold price', function () {
    $before = piece($this, $this->listing)->json('data.current_price');

    $this->travel(2)->minutes();
    Listings::goldPrice('8944', '8990');
    $after = piece($this, $this->listing)->json('data.current_price');
    $listed = Listings::anonymous($this)->getJson(Listings::MARKET_URL)->json('data.0.current_price');

    expect(bccomp($after, $before, 4))->toBe(1)->and($listed)->toBe($after);
});

it('shows a fixed asking price for stones, with no price parts', function (string $category, string $price) {
    $factory = Listing::factory()->withPhotos(3);
    $listing = ($category === 'diamond' ? $factory->diamond() : $factory->goldWithDiamond())->live()->create(['seller_id' => $this->seller->customer_id]);

    piece($this, $listing)->assertOk()
        ->assertJsonPath('data.current_price', $price)
        ->assertJsonPath('data.price_available', true)
        ->assertJsonPath('data.price_is_indicative', false)
        ->assertJsonPath('data.price_parts', null)
        ->assertJsonPath('data.making_charge_per_g', null);
})->with([['diamond', '120000.0000'], ['gold_with_diamond', '80150.0000']]);

it('still lists a gold piece when no price can be quoted', function (Closure $breakPrice) {
    $breakPrice();

    piece($this, $this->listing)->assertOk()
        ->assertJsonPath('data.current_price', null)
        ->assertJsonPath('data.price_available', false)
        ->assertJsonPath('data.price_is_indicative', true)
        ->assertJsonPath('data.price_parts', null);

    Listings::anonymous($this)->getJson(Listings::MARKET_URL)->assertOk()
        ->assertJsonPath('data.0.id', $this->listing->listing_id)->assertJsonPath('data.0.current_price', null);
    Listings::anonymous($this)->getJson(Listings::MARKET_URL.'?sort=price_asc')->assertOk()->assertJsonCount(1, 'data');
})->with([
    // gold_price is append-only: TRUNCATE (rolled back with the test) stands in for "no price yet".
    'no gold price yet' => [fn () => DB::statement('TRUNCATE gold_price CASCADE')],
    'an inverted karat' => [fn () => DB::table('karat_price_adjustment')->where('karat_code', 21)->where('side', 'sell')->update(['kind' => 'fixed', 'value' => '-100000'])],
]);

it('keeps a stone piece priced when there is no gold price', function () {
    DB::statement('TRUNCATE gold_price CASCADE');
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);

    piece($this, $diamond)->assertOk()->assertJsonPath('data.current_price', '120000.0000')->assertJsonPath('data.price_available', true);
});

it('answers not found for anything that is not on the market', function (string $state) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    $photo = $listing->media()->first();

    piece($this, $listing)->assertNotFound()->assertJsonPath('code', 'not_found');
    pieceMedia($this, $listing, $photo)->assertNotFound();

    // The seller's token does not open it on the market either.
    Listings::as($this, $this->seller)->getJson(Listings::MARKET_URL."/{$listing->listing_id}")->assertNotFound();
})->with(['draft', 'inReview', 'changesRequested', 'withdrawn', 'rejected', 'suspendedHold']);

it('answers not found for an unknown id', function () {
    piece($this, (string) Str::uuid())->assertNotFound();
    Listings::anonymous($this)->getJson(Listings::MARKET_URL.'/not-a-uuid')->assertNotFound();
});

it('streams the public files of a live piece, never cached', function () {
    $photoBytes = Listings::pngBytes(16);
    $photo = ListingFactory::attachMedia($this->listing, ListingMediaKind::PHOTO, Listings::file($photoBytes, 'real.png'), 'image/png', 9);
    $certificate = $this->listing->media()->where('kind', 'stone_certificate')->first();

    $res = pieceMedia($this, $this->listing, $photo)->assertOk();

    expect($res->streamedContent())->toBe($photoBytes)
        ->and($res->headers->get('Content-Type'))->toStartWith('image/png')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->headers->get('Cache-Control'))->not->toContain('public')
        ->and($res->headers->get('Cache-Control'))->not->toContain('max-age');

    pieceMedia($this, $this->listing, $this->video)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    pieceMedia($this, $this->listing, $certificate)->assertOk();
});

it('never serves the invoice on the market', function () {
    $invoice = $this->listing->media()->where('kind', 'invoice')->first();

    pieceMedia($this, $this->listing, $invoice)->assertNotFound();
    // Not to the seller through the market either: it has its own route for that.
    Listings::as($this, $this->seller)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}/media/{$invoice->media_id}")->assertNotFound();

    expect(json_encode(piece($this, $this->listing)->json()))->not->toContain($invoice->media_id);
});

it('does not serve a file through another listing', function () {
    $other = Listing::factory()->withPhotos(1)->live()->create();
    $theirPhoto = $other->media()->first();

    pieceMedia($this, $this->listing, $theirPhoto)->assertNotFound();
    pieceMedia($this, $this->listing, (string) Str::uuid())->assertNotFound();
});

it('stops serving every file the moment the listing leaves the market', function () {
    $photo = $this->listing->media()->where('kind', 'photo')->first();
    pieceMedia($this, $this->listing, $photo)->assertOk();

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/withdraw", [], Listings::key())->assertOk();

    pieceMedia($this, $this->listing, $photo)->assertNotFound();
    pieceMedia($this, $this->listing, $this->video)->assertNotFound();
});
