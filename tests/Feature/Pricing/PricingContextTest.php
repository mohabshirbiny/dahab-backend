<?php

use App\Enums\PieceCategory;
use App\Models\GoldPrice;
use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 005 FR-011: the latest effective price wins; every karat is priced, enabled or not.

function recordPrice(string $bid, string $ask, string $at): GoldPrice
{
    return GoldPrice::query()->create([
        'source' => 'feed', 'bid_24k' => $bid, 'ask_24k' => $ask,
        'recorded_by' => SystemActor::id(), 'effective_at' => $at,
    ]);
}

it('uses the latest effective price', function () {
    recordPrice('7000', '7050', '2026-09-27 09:00:00');
    recordPrice('7944', '7990', '2026-09-27 10:00:00');

    $context = app(PricingContext::class);
    $prices = $context->karatPrices($context->market())->keyBy(fn ($row) => $row['karat']->karat_code);

    // Seeded adjustments: buy −15, sell +15 fixed.
    expect($prices[21]['prices']->sellersGet)->toBe('6942.9580')
        ->and($prices[21]['prices']->buyersPay)->toBe('7013.2482')
        ->and($prices->keys()->all())->toBe([24, 22, 21, 20, 18]);
});

it('prices disabled karats too', function () {
    recordPrice('7944', '7990', '2026-09-27 10:00:00');

    expect(Karat::query()->find(22)->is_enabled)->toBeFalse();

    $context = app(PricingContext::class);
    expect($context->karatPrices($context->market())->firstWhere('karat.karat_code', 22)['prices']->sellersGet)->toBe('7268.9880');
});

it('computes a breakdown with the current settings', function () {
    recordPrice('5994', '5994', '2026-09-27 10:00:00');
    KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'buy')->update(['value' => '-13.125']);
    KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'sell')->update(['value' => '13.125']);

    $context = app(PricingContext::class);
    $b = $context->breakdown(Piece::gold($context->pricing(21), '10.000', '300'));

    expect($b->sellerProceeds)->toBe('54684.7500');
});

it('has no market price before the first one is recorded', function () {
    expect(app(PricingContext::class)->market())->toBeNull()
        ->and(Piece::diamond('1')->category)->toBe(PieceCategory::DIAMOND);
});

it('fails loudly for a karat without adjustments', function () {
    KaratPriceAdjustment::query()->where('karat_code', 18)->delete();

    app(PricingContext::class)->pricing(18);
})->throws(LogicException::class);
