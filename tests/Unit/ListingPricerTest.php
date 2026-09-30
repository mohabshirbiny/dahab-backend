<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Spec 010 FR-023, research R8: listings are priced by the single calculator
// of Part 3 §2 (spec 005), never by a second implementation.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seller = Customer::factory()->verified()->create();
    $this->context = app(PricingContext::class);
});

function pricedListing(Customer $seller, string $category = 'gold', array $attributes = []): Listing
{
    $factory = match ($category) {
        'diamond' => Listing::factory()->diamond(),
        'gold_with_diamond' => Listing::factory()->goldWithDiamond(),
        default => Listing::factory(),
    };

    return $factory->create(['seller_id' => $seller->customer_id] + $attributes);
}

it('prices gold exactly as the calculator does', function () {
    Listings::goldPrice();
    $listing = pricedListing($this->seller, 'gold', ['karat_code' => 18, 'stated_weight_g' => '12.400', 'making_charge_per_g' => '180.00']);
    $expected = $this->context->breakdown(Piece::gold($this->context->pricing(18), '12.400', '180.00'));

    $quote = app(ListingPricer::class)->quote($listing);

    expect($quote->currentPrice)->toBe($expected->buyerTotal)
        ->and($quote->youWouldReceive)->toBe($expected->sellerProceeds)
        ->and($quote->priceIsIndicative)->toBeTrue()
        ->and($quote->priceAvailable())->toBeTrue()
        ->and($quote->priceParts())->toBe([
            'rate_per_gram' => $expected->karatPrices->buyersPay,
            'gold_value' => bcadd(bcmul($expected->karatPrices->buyersPay, '12.400', 8), '0.00005', 4),
            'making_total' => '2232.0000',
        ]);
});

it('prices a diamond at its asking price, with or without a gold price', function () {
    $listing = pricedListing($this->seller, 'diamond');

    $without = app(ListingPricer::class)->quote($listing);
    Listings::goldPrice();
    $with = app(ListingPricer::class)->quote($listing);
    $expected = $this->context->breakdown(Piece::diamond('120000.00'));

    expect($without->currentPrice)->toBe('120000.0000')
        ->and($without->priceIsIndicative)->toBeFalse()
        ->and($without->priceParts())->toBeNull()
        ->and($without->youWouldReceive)->toBe($expected->sellerProceeds)
        ->and($with->currentPrice)->toBe('120000.0000')
        ->and($with->youWouldReceive)->toBe($expected->sellerProceeds);
});

it('prices gold with diamond at its asking price, and the proceeds with the calculator', function () {
    Listings::goldPrice();
    $listing = pricedListing($this->seller, 'gold_with_diamond');
    $expected = $this->context->breakdown(Piece::goldWithDiamond($this->context->pricing(21), '6.100', '80150.00'));

    $quote = app(ListingPricer::class)->quote($listing);

    expect($quote->currentPrice)->toBe('80150.0000')
        ->and($quote->priceIsIndicative)->toBeFalse()
        ->and($quote->priceParts())->toBeNull()
        ->and($quote->youWouldReceive)->toBe($expected->sellerProceeds);
});

it('quotes nothing for gold when there is no gold price', function () {
    $quote = app(ListingPricer::class)->quote(pricedListing($this->seller));

    expect($quote->currentPrice)->toBeNull()
        ->and($quote->priceAvailable())->toBeFalse()
        ->and($quote->youWouldReceive)->toBeNull()
        ->and($quote->priceParts())->toBeNull()
        ->and($quote->priceIsIndicative)->toBeTrue();

    // Gold with diamond still shows its asking price; only the proceeds need the gold price.
    $mixed = app(ListingPricer::class)->quote(pricedListing($this->seller, 'gold_with_diamond'));
    expect($mixed->currentPrice)->toBe('80150.0000')->and($mixed->youWouldReceive)->toBeNull();
});

it('quotes nothing for a karat whose prices are inverted, and keeps the others', function () {
    Listings::goldPrice();
    DB::table('karat_price_adjustment')->where('karat_code', 21)->where('side', 'sell')->update(['kind' => 'fixed', 'value' => '-100000']);
    $pricer = app(ListingPricer::class);

    $inverted = $pricer->quote(pricedListing($this->seller));
    $fine = $pricer->quote(pricedListing($this->seller, 'gold', ['karat_code' => 18]));

    expect($inverted->currentPrice)->toBeNull()
        ->and($fine->currentPrice)->not->toBeNull()
        ->and(array_keys($pricer->buyersPayByKarat()))->not->toContain(21)->toContain(18);
});

it('gives the rates that order the market in SQL', function () {
    expect(app(ListingPricer::class)->buyersPayByKarat())->toBe([]);

    Listings::goldPrice();
    $rates = app(ListingPricer::class)->buyersPayByKarat();

    expect($rates[21])->toBe($this->context->karatPrices($this->context->market())->firstWhere('karat.karat_code', 21)['prices']->buyersPay)
        ->and($rates[21])->toMatch('/^\d+\.\d{4}$/');
});
