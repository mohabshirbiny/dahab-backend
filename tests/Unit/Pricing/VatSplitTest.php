<?php

use App\Support\Invoices\CreditNoteSplit;
use App\Support\Pricing\VatSplit;

// Package C: the commission is VAT-inclusive. Owner's decision (2026-10-23): VAT = 14 % of the total.

it('takes VAT as the rate of the VAT-inclusive total', function (string $total, string $net, string $vat) {
    expect(VatSplit::fromInclusive($total, '14'))->toBe(['net' => $net, 'vat' => $vat, 'total' => bcadd($total, '0', 4)]);
})->with([
    'the owner\'s example: 5 % of 39,000' => ['1950', '1677.0000', '273.0000'],
    'the 21K ring: 20 % of 3,000' => ['600', '516.0000', '84.0000'],
    'the minimum, VAT included' => ['200', '172.0000', '28.0000'],
    'an odd making charge' => ['333.3333', '286.6666', '46.6667'],
    'one piastre' => ['0.0001', '0.0001', '0.0000'],
    'nothing' => ['0', '0.0000', '0.0000'],
]);

it('keeps the whole total when there is no VAT', function () {
    expect(VatSplit::fromInclusive('600', '0'))->toBe(['net' => '600.0000', 'vat' => '0.0000', 'total' => '600.0000']);
});

it('always adds up', function () {
    mt_srand(23);
    for ($i = 0; $i < 20000; $i++) {
        $total = bcdiv((string) mt_rand(1, 99_999_999), '10000', 4);
        $split = VatSplit::fromInclusive($total, '14');

        expect(bcadd($split['net'], $split['vat'], 4))->toBe($total);
    }
});

it('credits an invoice\'s own VAT share, and the last note clears the VAT exactly', function () {
    // Invoice 1,950 = 1,677 + 273.
    $first = CreditNoteSplit::of('1000', '1950', '273');
    expect($first)->toBe(['net' => '860.0000', 'vat' => '140.0000', 'gross' => '1000.0000']);

    $last = CreditNoteSplit::of('950', '1950', '273', '1000', '140');
    expect($last)->toBe(['net' => '817.0000', 'vat' => '133.0000', 'gross' => '950.0000']);

    // An invoice split the earlier way (600 = 526.3158 + 73.6842) reverses its own figures.
    expect(CreditNoteSplit::of('600', '600', '73.6842'))->toBe(['net' => '526.3158', 'vat' => '73.6842', 'gross' => '600.0000'])
        ->and(CreditNoteSplit::of('600', '600', '0'))->toBe(['net' => '600.0000', 'vat' => '0.0000', 'gross' => '600.0000']);
});
