<?php

use App\Enums\PieceCategory;
use App\Support\Pricing\Money;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PriceCalculator;
use App\Support\Pricing\PricingRates;

// Spec 012 research R6: settlement on rates locked earlier, reproducing
// Part 3 §3.3 and §3.4 to the piastre (bid = ask = 5,994; 21K ∓13.125 fixed:
// sellers_get 5,236.875, buyers_pay 5,263.125; making 300/g; 20% / 14% / 200).

function lockedRates(): PricingRates
{
    return new PricingRates(goldPct: '20', stonePct: '5', minimumEgp: '200', vatPct: '14');
}

it('reproduces Part 3 §3.3 on 10.000 g', function () {
    $b = (new PriceCalculator)->lockedBreakdown(
        Piece::locked(PieceCategory::GOLD, '10.000', '300', null), '5263.1250', '5236.8750', lockedRates(),
    );

    expect($b->buyerTotal)->toBe('55631.2500')
        ->and($b->sellerGross)->toBe('55368.7500')
        ->and($b->spread)->toBe('262.5000')
        ->and($b->commission)->toBe('600.0000')
        ->and($b->vat)->toBe('84.0000')
        ->and($b->sellerProceeds)->toBe('54684.7500');
});

it('reproduces Part 3 §3.4 on the IGI weight of 9.900 g', function () {
    $b = (new PriceCalculator)->lockedBreakdown(
        Piece::locked(PieceCategory::GOLD, '9.900', '300', null), '5263.1250', '5236.8750', lockedRates(),
    );

    expect($b->buyerTotal)->toBe('55074.9375')
        ->and($b->sellerGross)->toBe('54815.0625')
        ->and($b->spread)->toBe('259.8750')
        ->and($b->commission)->toBe('594.0000')
        ->and($b->vat)->toBe('83.1600')
        ->and($b->sellerProceeds)->toBe('54137.9025');
});

it('gives a negative spread when the locked rates crossed, and the seller still gets their figure', function () {
    $b = (new PriceCalculator)->lockedBreakdown(
        Piece::locked(PieceCategory::GOLD, '10.000', '300', null), '5263.1250', '5300.0000', lockedRates(),
    );

    expect($b->spread)->toBe('-368.7500')
        ->and($b->sellerGross)->toBe('56000.0000')
        ->and($b->sellerProceeds)->toBe('55316.0000');
});

it('prices a diamond on its asking price with stone commission and no spread', function () {
    $b = (new PriceCalculator)->lockedBreakdown(
        Piece::locked(PieceCategory::DIAMOND, null, null, '120000'), null, null, lockedRates(),
    );

    expect($b->buyerTotal)->toBe('120000.0000')
        ->and($b->spread)->toBe('0.0000')
        ->and($b->commission)->toBe('6000.0000')
        ->and($b->vat)->toBe('840.0000')
        ->and($b->sellerProceeds)->toBe('113160.0000');
});

it('protects the gold value of a gold-with-diamond piece at the locked mid', function () {
    // 5 g at a mid of 5,250 = 26,250 of gold; commission on 80,000 − 26,250.
    $b = (new PriceCalculator)->lockedBreakdown(
        Piece::locked(PieceCategory::GOLD_WITH_DIAMOND, '5.000', null, '80000'), null, '5250.0000', lockedRates(),
    );

    expect($b->protectedGoldValue)->toBe('26250.0000')
        ->and($b->commission)->toBe('2687.5000')
        ->and($b->spread)->toBe('0.0000');
});

it('never leaves a rounding residue: total = proceeds + commission + VAT + spread', function () {
    mt_srand(12);
    for ($i = 0; $i < 10000; $i++) {
        $weight = number_format(mt_rand(100, 99999) / 1000, 3, '.', '');
        $making = number_format(mt_rand(0, 90000) / 100, 2, '.', '');
        $buyer = number_format(mt_rand(300000000, 900000000) / 100000, 4, '.', '');
        $seller = number_format(mt_rand(300000000, 900000000) / 100000, 4, '.', '');

        $b = (new PriceCalculator)->lockedBreakdown(Piece::locked(PieceCategory::GOLD, $weight, $making, null), $buyer, $seller, lockedRates());
        $sum = Money::add(Money::add(Money::add($b->sellerProceeds, $b->commission), $b->vat), $b->spread);

        expect(Money::cmp($sum, $b->buyerTotal))->toBe(0);
    }
});
