<?php

use App\Support\Invoices\CreditNoteSplit;
use App\Support\Pricing\VatSplit;

// Package C: the commission is VAT-inclusive; VAT is carved out of the total.

it('carves VAT out of a VAT-inclusive total', function (string $total, string $net, string $vat) {
    expect(VatSplit::fromInclusive($total, '14'))->toBe(['net' => $net, 'vat' => $vat, 'total' => bcadd($total, '0', 4)]);
})->with([
    'the 21K ring: 20 % of 3,000' => ['600', '526.3158', '73.6842'],
    'the minimum, VAT included' => ['200', '175.4386', '24.5614'],
    'an odd making charge' => ['333.3333', '292.3976', '40.9357'],
    'one piastre' => ['0.0001', '0.0001', '0.0000'],
    'nothing' => ['0', '0.0000', '0.0000'],
]);

it('keeps the whole total when there is no VAT', function () {
    expect(VatSplit::fromInclusive('600', '0'))->toBe(['net' => '600.0000', 'vat' => '0.0000', 'total' => '600.0000']);
});

it('always adds up, and agrees with the credit-note split at 14 %', function () {
    mt_srand(23);
    for ($i = 0; $i < 20000; $i++) {
        $total = bcdiv((string) mt_rand(1, 99_999_999), '10000', 4);
        $split = VatSplit::fromInclusive($total, '14');
        $credit = CreditNoteSplit::of($total, '14');

        expect(bcadd($split['net'], $split['vat'], 4))->toBe($total)
            ->and([$split['net'], $split['vat']])->toBe([$credit['net'], $credit['vat']]);
    }
});
