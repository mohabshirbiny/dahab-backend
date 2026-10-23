<?php

use App\Enums\CreditKind;
use App\Enums\CreditSource;
use App\Enums\WaiverKind;
use App\Support\Pricing\CommissionWaivers;
use App\Support\Pricing\CreditCandidate;
use App\Support\Pricing\PriceBreakdown;
use App\Support\Pricing\PricingRates;

// Spec 023 research R3/R4: the lowest single commission, never a sum; the minimum floors percent
// and amount credits but not a waiver of the whole commission; a credit that gives nothing is not
// chosen. The numbers below are the rates the test itself passes in (the minimum is a parameter).
// Package C: the resolver takes and compares what the seller pays VAT included (`*Total`); the
// winner is split into net + VAT (VAT = total × 14 / 100 half-up, net the rest).

function rates(string $minimum = '200', string $vat = '14'): PricingRates
{
    return new PricingRates('20', '5', $minimum, $vat);
}

function credit(string $id, CreditKind $kind, ?string $percent = null, ?string $left = null, CreditSource $source = CreditSource::PROMO_CODE, ?string $expires = null, string $granted = '2026-10-01'): CreditCandidate
{
    return new CreditCandidate($id, $kind, $source, $percent, $left, $expires, $granted);
}

it('charges the ordinary commission when nothing applies', function () {
    $r = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, []);

    expect($r->kind)->toBe(WaiverKind::NONE)
        ->and($r->chargedTotal)->toBe('600.0000')
        ->and($r->givenUpTotal)->toBe('0.0000')
        ->and($r->charged)->toBe('516.0000')->and($r->vat)->toBe('84.0000')
        ->and($r->creditId)->toBeNull()
        ->and($r->floorApplied)->toBeTrue();
});

it('waives the whole commission for a free relist, without the minimum', function () {
    $r = (new CommissionWaivers)->resolve('600.0000', rates(), true, false, []);

    expect($r->kind)->toBe(WaiverKind::FREE_RELIST)
        ->and($r->chargedTotal)->toBe('0.0000')
        ->and($r->vat)->toBe('0.0000')
        ->and($r->givenUpTotal)->toBe('600.0000')
        ->and($r->floorApplied)->toBeFalse();
});

it('waives the whole commission for a market-maker code', function () {
    $r = (new CommissionWaivers)->resolve('600.0000', rates(), false, true, []);

    expect($r->kind)->toBe(WaiverKind::MARKET_MAKER)
        ->and($r->chargedTotal)->toBe('0.0000')
        ->and($r->givenUpTotal)->toBe('600.0000');
});

it('takes a waive credit to nothing', function () {
    $r = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, [credit('c1', CreditKind::WAIVE)]);

    expect($r->kind)->toBe(WaiverKind::CREDIT)->and($r->creditId)->toBe('c1')
        ->and($r->chargedTotal)->toBe('0.0000')->and($r->floorApplied)->toBeFalse();
});

it('takes a percentage off, and the minimum binds', function () {
    $half = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, [credit('p', CreditKind::PERCENT, '50')]);
    $ninetyNine = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, [credit('p', CreditKind::PERCENT, '99')]);

    expect($half->chargedTotal)->toBe('300.0000')->and($half->givenUpTotal)->toBe('300.0000')->and($half->charged)->toBe('258.0000')->and($half->vat)->toBe('42.0000')
        ->and($half->givenUp)->toBe('258.0000')
        ->and($ninetyNine->chargedTotal)->toBe('200.0000')->and($ninetyNine->givenUpTotal)->toBe('400.0000')
        ->and($ninetyNine->floorApplied)->toBeTrue()
        // The stored floor is the net of the VAT-inclusive minimum, so commission_amount >= floor still holds.
        ->and($ninetyNine->floor)->toBe('172.0000')->and($ninetyNine->charged)->toBe('172.0000');
});

it('takes an amount off, partially, and the minimum binds', function () {
    $partial = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, [credit('a', CreditKind::AMOUNT, left: '150.0000', source: CreditSource::REFERRAL_INVITER)]);
    $big = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, [credit('a', CreditKind::AMOUNT, left: '5000.0000', source: CreditSource::REFERRAL_INVITER)]);

    expect($partial->chargedTotal)->toBe('450.0000')->and($partial->givenUpTotal)->toBe('150.0000')
        ->and($big->chargedTotal)->toBe('200.0000')->and($big->givenUpTotal)->toBe('400.0000');
});

it('does not choose a credit that cannot lower the commission', function () {
    // The ordinary commission is already the minimum: a 50 % credit would give 100, floored to 200.
    $atMinimum = (new CommissionWaivers)->resolve('200.0000', rates(), false, false, [credit('p', CreditKind::PERCENT, '50')]);
    $amount = (new CommissionWaivers)->resolve('200.0000', rates(), false, false, [credit('a', CreditKind::AMOUNT, left: '200.0000')]);

    expect($atMinimum->kind)->toBe(WaiverKind::NONE)->and($atMinimum->creditId)->toBeNull()
        ->and($amount->kind)->toBe(WaiverKind::NONE)->and($amount->creditId)->toBeNull();
});

it('never sums two credits: the lowest commission wins', function () {
    $r = (new CommissionWaivers)->resolve('1000.0000', rates(), false, false, [
        credit('half', CreditKind::PERCENT, '50'),
        credit('amount', CreditKind::AMOUNT, left: '300.0000', source: CreditSource::REFERRAL_INVITER),
    ]);

    // 50 % -> 500; 300 off -> 700. The percent credit wins alone.
    expect($r->creditId)->toBe('half')->and($r->chargedTotal)->toBe('500.0000');
});

it('breaks a tie by free relist, then market maker, then a code credit, then a referral credit', function () {
    $waivers = new CommissionWaivers;
    $code = credit('code', CreditKind::WAIVE);
    $referral = credit('ref', CreditKind::WAIVE, source: CreditSource::REFERRAL_FRIEND);

    expect($waivers->resolve('600.0000', rates(), true, true, [$code, $referral])->kind)->toBe(WaiverKind::FREE_RELIST)
        ->and($waivers->resolve('600.0000', rates(), false, true, [$code, $referral])->kind)->toBe(WaiverKind::MARKET_MAKER)
        ->and($waivers->resolve('600.0000', rates(), false, false, [$referral, $code])->creditId)->toBe('code')
        ->and($waivers->resolve('600.0000', rates(), false, false, [$referral])->creditId)->toBe('ref');
});

it('breaks a tie between credits by the earliest expiry, then the oldest', function () {
    $waivers = new CommissionWaivers;
    $late = credit('late', CreditKind::PERCENT, '50', expires: '2027-02-01', granted: '2026-10-01');
    $soon = credit('soon', CreditKind::PERCENT, '50', expires: '2027-01-01', granted: '2026-10-05');
    $never = credit('never', CreditKind::PERCENT, '50', expires: null, granted: '2026-09-01');
    $older = credit('older', CreditKind::PERCENT, '50', expires: '2027-02-01', granted: '2026-09-15');

    expect($waivers->resolve('600.0000', rates(), false, false, [$late, $soon, $never])->creditId)->toBe('soon')
        ->and($waivers->resolve('600.0000', rates(), false, false, [$late, $older])->creditId)->toBe('older');
});

it('prefers a lower commission over a higher-priority waiver', function () {
    // A free relist is 0; nothing beats it. A percent credit loses to the market-maker 0.
    $r = (new CommissionWaivers)->resolve('600.0000', rates(), false, true, [credit('p', CreditKind::PERCENT, '90')]);

    expect($r->kind)->toBe(WaiverKind::MARKET_MAKER);
});

it('rounds half up to four decimals and lets VAT follow the charged commission', function () {
    // 333.3333 x 50 % = 166.66665 -> 166.6667 before the floor; the floor of 100 does not bind.
    $r = (new CommissionWaivers)->resolve('333.3333', rates('100'), false, false, [credit('p', CreditKind::PERCENT, '50')]);

    expect($r->chargedTotal)->toBe('166.6667')->and($r->givenUpTotal)->toBe('166.6666')
        ->and($r->charged)->toBe('143.3334')->and($r->vat)->toBe('23.3333');
});

it('re-derives the seller proceeds from the charged commission', function () {
    $b = new PriceBreakdown('10000.0000', '9000.0000', '1000.0000', '600.0000', '84.0000', '8316.0000', null, null);
    $r = (new CommissionWaivers)->resolve('600.0000', rates(), false, false, [credit('p', CreditKind::PERCENT, '50')]);
    $applied = $r->applyTo($b);

    expect($applied->commission)->toBe('258.0000')->and($applied->vat)->toBe('42.0000')
        ->and($applied->sellerProceeds)->toBe('8700.0000')
        ->and($applied->buyerTotal)->toBe('10000.0000')->and($applied->spread)->toBe('1000.0000');
});
