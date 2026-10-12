<?php

use App\Exceptions\DomainApiException;
use App\Models\AppTextKey;
use App\Support\Content\PlaceholderValidator;
use App\Support\Content\SettingPlaceholders;

// Spec 019 FR-004, R5: the rules a text meets before it is saved or published.

function ptKey(array $overrides = []): AppTextKey
{
    return (new AppTextKey)->forceFill($overrides + [
        'text_key' => 'order.test.title',
        'kind' => 'app',
        'placeholders' => ['hours', 'minutes'],
        'required_placeholders' => [],
        'max_length' => 60,
    ]);
}

function ptCode(Closure $work): ?string
{
    try {
        $work();
    } catch (DomainApiException $e) {
        return $e->errorCode;
    }

    return null;
}

it('accepts a text that uses the key\'s own placeholders and the setting placeholders', function () {
    $v = new PlaceholderValidator;

    expect(ptCode(fn () => $v->validate(ptKey(), '{hours} h {minutes} min left, then {commission_gold_pct}%', 'فاضل {hours} ساعة و{minutes} دقيقة {commission_gold_pct}')))->toBeNull();
});

it('refuses an empty language', function (string $en, string $ar, array $languages) {
    try {
        (new PlaceholderValidator)->validate(ptKey(), $en, $ar);
        $this->fail('expected content_language_missing');
    } catch (DomainApiException $e) {
        expect($e->errorCode)->toBe('content_language_missing')->and($e->statusCode)->toBe(422)->and($e->details['languages'])->toBe($languages);
    }
})->with([
    'arabic' => ['Hello', '  ', ['ar']],
    'english' => ['', 'اهلا', ['en']],
    'both' => ['', '', ['en', 'ar']],
]);

it('refuses a text above the key\'s limit, in either language', function () {
    $v = new PlaceholderValidator;

    expect(ptCode(fn () => $v->validate(ptKey(['max_length' => 10]), 'Eleven char', 'قصير')))->toBe('content_too_long')
        ->and(ptCode(fn () => $v->validate(ptKey(['max_length' => 10]), 'Short', 'نص أطول من عشرة حروف')))->toBe('content_too_long')
        ->and(ptCode(fn () => $v->validate(ptKey(['max_length' => 11]), 'Eleven char', 'قصير')))->toBeNull();
});

it('refuses a placeholder that is not allowed, a badly named one and unbalanced braces', function (string $text, string $reported) {
    try {
        (new PlaceholderValidator)->validate(ptKey(), $text, 'عربي');
        $this->fail('expected content_invalid_placeholder');
    } catch (DomainApiException $e) {
        expect($e->errorCode)->toBe('content_invalid_placeholder')->and($e->details['placeholders'])->toContain($reported);
    }
})->with([
    'unknown name' => ['Hello {name}', 'name'],
    'upper case' => ['Hello {Hours}', 'Hours'],
    'with a space' => ['Hello {the hours}', 'the hours'],
    'empty' => ['Hello {}', '{}'],
    'open brace' => ['Hello {hours', 'unbalanced {'],
    'close brace' => ['Hello hours}', 'unbalanced }'],
]);

it('requires the placeholders a key must keep, in both languages', function () {
    $key = ptKey(['placeholders' => ['code'], 'required_placeholders' => ['code']]);
    $v = new PlaceholderValidator;

    expect(ptCode(fn () => $v->validate($key, 'Your code is {code}', 'كودك {code}')))->toBeNull()
        ->and(ptCode(fn () => $v->validate($key, 'Your code is 1234', 'كودك {code}')))->toBe('content_missing_required_placeholder')
        ->and(ptCode(fn () => $v->validate($key, 'Your code is {code}', 'كودك')))->toBe('content_missing_required_placeholder');
});

it('names the setting placeholders once, and formats a number as people write it', function () {
    expect(SettingPlaceholders::names())->toBe([
        'commission_gold_pct', 'commission_stone_pct', 'commission_minimum_egp', 'vat_pct', 'deposit_buyer_pct',
        'reach_branch_hours', 'buyer_pay_days', 'collect_weeks', 'seller_return_weeks', 'free_relist_hours',
        'withdrawal_pause_hours', 'weight_tolerance_pct', 'saved_max',
        'first_sale_cap', 'referral_friend_pct', 'referral_reward_egp', 'referral_expiry_days', 'referral_friend_sales',
    ])
        ->and(SettingPlaceholders::format('20.0000'))->toBe('20')
        ->and(SettingPlaceholders::format('0.5000'))->toBe('0.5')
        ->and(SettingPlaceholders::format('200.2500'))->toBe('200.25')
        ->and(SettingPlaceholders::format('12'))->toBe('12')
        ->and(SettingPlaceholders::format('0.0000'))->toBe('0');
});
