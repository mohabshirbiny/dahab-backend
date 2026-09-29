<?php

use App\Models\Customer;
use App\Support\TopUpReference;

it('prefixes the display reference', function () {
    $customer = new Customer(['display_ref' => '004417']);

    expect(TopUpReference::for($customer))->toBe('DAHAB-004417');
});

it('normalises staff search terms', function (string $term, string $expected) {
    expect(TopUpReference::normalise($term))->toBe($expected);
})->with([
    ['DAHAB-004417', '004417'],
    ['dahab 004417', '004417'],
    ['Dahab-004417', '004417'],
    ['004417', '004417'],
    [' 004 417 ', '004417'],
    ['+201000000001', '+201000000001'],
]);
