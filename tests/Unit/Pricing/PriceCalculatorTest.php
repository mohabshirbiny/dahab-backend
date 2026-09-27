<?php

use App\Enums\AdjustmentKind;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Support\Pricing\Adjustment;
use App\Support\Pricing\KaratPricing;
use App\Support\Pricing\MarketPrice;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PriceBreakdown;
use App\Support\Pricing\PriceCalculator;
use App\Support\Pricing\PricingRates;

// Spec 005 US4 / SC-001: Technical Spec Part 3 §2 (amended) and the §3 worked
// examples, to the piastre. No database, no clock.

function karat21(string $buy = '-13.125', string $sell = '13.125', AdjustmentKind $kind = AdjustmentKind::FIXED): KaratPricing
{
    return new KaratPricing(21, '0.87500', new Adjustment($kind, $buy), new Adjustment($kind, $sell));
}

function schemaRates(): PricingRates
{
    return new PricingRates(goldPct: '20', stonePct: '5', minimumEgp: '200', vatPct: '14');
}

function part3Market(): MarketPrice
{
    // Part 3 §3.3: R = 6,000 per gram of pure gold = 5,994 per gram of 24K (0.999), bid = ask.
    return new MarketPrice('5994', '5994');
}

function expectInvariant(PriceBreakdown $b): void
{
    expect(bcsub($b->buyerTotal, $b->sellerProceeds, 4))
        ->toBe(bcadd(bcadd($b->commission, $b->vat, 4), $b->spread, 4));
}

it('reproduces Part 3 §3.3 (21K ring, 10 g, clean pass)', function () {
    $b = (new PriceCalculator)->breakdown(part3Market(), Piece::gold(karat21(), '10.000', '300'), schemaRates());

    expect($b->buyerTotal)->toBe('55631.2500')
        ->and($b->sellerGross)->toBe('55368.7500')
        ->and($b->spread)->toBe('262.5000')
        ->and($b->commission)->toBe('600.0000')
        ->and($b->vat)->toBe('84.0000')
        ->and($b->sellerProceeds)->toBe('54684.7500');
    expectInvariant($b);
});

it('reproduces Part 3 §3.4 (IGI weight 9.9 g)', function () {
    $b = (new PriceCalculator)->breakdown(part3Market(), Piece::gold(karat21(), '9.900', '300'), schemaRates());

    expect($b->buyerTotal)->toBe('55074.9375')
        ->and($b->sellerGross)->toBe('54815.0625')
        ->and($b->spread)->toBe('259.8750')
        ->and($b->commission)->toBe('594.0000')
        ->and($b->vat)->toBe('83.1600')
        ->and($b->sellerProceeds)->toBe('54137.9025');
    expectInvariant($b);
});

it('prices every seeded karat from the 24K bid and ask by purity', function (int $code, string $purity, string $bid, string $ask) {
    $prices = (new PriceCalculator)->karatPrices(
        new MarketPrice('7944', '7990'),
        new KaratPricing($code, $purity, new Adjustment(AdjustmentKind::FIXED, '0'), new Adjustment(AdjustmentKind::FIXED, '0')),
    );

    expect($prices->marketBid)->toBe($bid)->and($prices->marketAsk)->toBe($ask);
})->with([
    '24K' => [24, '0.99900', '7944.0000', '7990.0000'],
    '22K' => [22, '0.91600', '7283.9880', '7326.1662'],
    '21K' => [21, '0.87500', '6957.9580', '6998.2482'],
    '20K' => [20, '0.83300', '6623.9760', '6662.3323'],
    '18K' => [18, '0.75000', '5963.9640', '5998.4985'],
]);

it('applies fixed and percentage adjustments to the right side', function () {
    $calc = new PriceCalculator;
    $market = new MarketPrice('7944', '7990');

    $fixed = $calc->karatPrices($market, karat21('-20', '10'));
    expect($fixed->sellersGet)->toBe('6937.9580')
        ->and($fixed->buyersPay)->toBe('7008.2482')
        ->and($fixed->difference)->toBe('70.2902');

    $percent = $calc->karatPrices($market, karat21('-1.5', '0.5', AdjustmentKind::PERCENT));
    expect($percent->sellersGet)->toBe('6853.5886') // 6957.9580 × 0.985
        ->and($percent->buyersPay)->toBe('7033.2394'); // 6998.2482 × 1.005
});

it('keeps the whole market spread plus the adjustments on a real bid/ask gap', function () {
    $b = (new PriceCalculator)->breakdown(new MarketPrice('7944', '7990'), Piece::gold(karat21('-20', '10'), '5.000', '150'), schemaRates());

    expect($b->buyerTotal)->toBe('35791.2410') // 7008.2482 × 5 + 750
        ->and($b->sellerGross)->toBe('35439.7900') // 6937.9580 × 5 + 750
        ->and($b->spread)->toBe('351.4510')
        ->and($b->commission)->toBe('200.0000') // 20% × 750 = 150 → the minimum
        ->and($b->vat)->toBe('28.0000');
    expectInvariant($b);
});

it('prices a pure diamond with no spread and commission on the whole asking price', function () {
    $b = (new PriceCalculator)->breakdown(null, Piece::diamond('120000'), schemaRates());

    expect($b->buyerTotal)->toBe('120000.0000')
        ->and($b->sellerGross)->toBe('120000.0000')
        ->and($b->spread)->toBe('0.0000')
        ->and($b->commission)->toBe('6000.0000')
        ->and($b->vat)->toBe('840.0000')
        ->and($b->sellerProceeds)->toBe('113160.0000');
    expectInvariant($b);
});

it('protects the gold inside gold-with-diamond at the unadjusted midpoint', function () {
    $b = (new PriceCalculator)->breakdown(new MarketPrice('7944', '7990'), Piece::goldWithDiamond(karat21(), '4.000', '60000'), schemaRates());

    // mid(21K) = (6957.9580 + 6998.2482) / 2 = 6978.1031; gold = 27912.4124
    expect($b->protectedGoldValue)->toBe('27912.4124')
        ->and($b->spread)->toBe('0.0000')
        ->and($b->commission)->toBe('1604.3794') // 5% × (60000 − 27912.4124)
        ->and($b->vat)->toBe('224.6131');
    expectInvariant($b);
});

it('floors the value above gold at zero, then applies the minimum', function () {
    $b = (new PriceCalculator)->breakdown(new MarketPrice('7944', '7990'), Piece::goldWithDiamond(karat21(), '10.000', '50000'), schemaRates());

    expect($b->commission)->toBe('200.0000');
});

it('lets a waiver skip the minimum', function () {
    $b = (new PriceCalculator)->breakdown(part3Market(), Piece::gold(karat21(), '10.000', '300', commissionWaived: true), schemaRates());

    expect($b->commission)->toBe('0.0000')->and($b->vat)->toBe('0.0000');
    expectInvariant($b);
});

it('rounds half-up at 4 dp and puts the residue in the spread', function () {
    // 3-dp weight and a price with 4 dp: the products carry more decimals.
    $b = (new PriceCalculator)->breakdown(new MarketPrice('7944.1234', '7990.5678'), Piece::gold(karat21('-7.3333', '9.1111'), '3.337', '123.45'), schemaRates());

    expect(strlen(explode('.', $b->buyerTotal)[1]))->toBe(4)
        ->and(bcsub($b->buyerTotal, $b->sellerGross, 4))->toBe($b->spread);
    expectInvariant($b);
});

it('refuses an inverted karat and a missing price', function () {
    $calc = new PriceCalculator;

    expect(fn () => $calc->breakdown(new MarketPrice('7944', '7990'), Piece::gold(karat21('0', '-200'), '1.000', '0'), schemaRates()))
        ->toThrow(DomainApiException::class, '21K')
        ->and(fn () => $calc->breakdown(null, Piece::gold(karat21(), '1.000', '0'), schemaRates()))
        ->toThrow(DomainApiException::class);

    expect($calc->karatPrices(new MarketPrice('7944', '7990'), karat21('0', '-200'))->inverted)->toBeTrue();
});

it('gives the same answer every time', function () {
    $calc = new PriceCalculator;
    $piece = Piece::gold(karat21(), '10.000', '300');

    expect($calc->breakdown(part3Market(), $piece, schemaRates()))->toEqual($calc->breakdown(part3Market(), $piece, schemaRates()));
});

it('knows the categories', function () {
    expect(Piece::diamond('1')->category)->toBe(PieceCategory::DIAMOND);
});
