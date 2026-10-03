<?php

use App\Support\Withdrawals\Iban;
use Tests\Support\Withdrawals;

// Spec 013 Clarifications: an Egyptian IBAN (EG + 27 digits, ISO 13616
// mod-97 = 1) or an 8–20 digit account number; spaces removed first.

it('accepts the registry example and the generated fixtures', function () {
    expect(Iban::isAcceptable('EG380019000500000000263180002'))->toBeTrue()
        ->and(Withdrawals::iban())->toBe('EG380019000500000000263180002')
        ->and(Iban::isAcceptable(Withdrawals::iban('1234567890123456789012345')))->toBeTrue();
});

it('normalizes spaces and case before checking', function () {
    expect(Iban::normalize(' eg38 0019 0005 0000 0000 2631 8000 2 '))->toBe('EG380019000500000000263180002')
        ->and(Iban::isAcceptable(Iban::normalize('eg38 0019 0005 0000 0000 2631 8000 2')))->toBeTrue();
});

it('refuses a wrong checksum, another country and the wrong length', function () {
    expect(Iban::isAcceptable('EG390019000500000000263180002'))->toBeFalse()
        ->and(Iban::isAcceptable('DE89370400440532013000'))->toBeFalse()
        ->and(Iban::isAcceptable('EG38001900050000000026318000'))->toBeFalse();
});

it('accepts 8 to 20 digit account numbers only', function () {
    expect(Iban::isAcceptable('12345678'))->toBeTrue()
        ->and(Iban::isAcceptable('12345678901234567890'))->toBeTrue()
        ->and(Iban::isAcceptable('1234567'))->toBeFalse()
        ->and(Iban::isAcceptable('123456789012345678901'))->toBeFalse()
        ->and(Iban::isAcceptable('1234-5678'))->toBeFalse();
});
