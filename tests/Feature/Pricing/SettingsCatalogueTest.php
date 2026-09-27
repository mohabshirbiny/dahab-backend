<?php

use App\Enums\SettingKey;
use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use App\Models\Setting;
use App\Support\Pricing\Settings;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 005 FR-001/FR-005: the code catalogue and the seeded rows are the same set.

it('seeds exactly the catalogue keys, without the old price corrections', function () {
    $seeded = Setting::query()->pluck('setting_key')->sort()->values()->all();
    $catalogue = collect(SettingKey::cases())->map->value->sort()->values()->all();

    expect($seeded)->toBe($catalogue)
        ->and($seeded)->not->toContain('price_correction.buy_side')
        ->and($seeded)->not->toContain('price_correction.sell_side');
});

it('reads numbers as strings and flags as booleans', function () {
    $settings = app(Settings::class);

    expect($settings->numeric(SettingKey::COMMISSION_GOLD_PCT))->toBe('20.0000')
        ->and($settings->integer(SettingKey::PRICEFEED_STALE_AFTER_MINUTES))->toBe(5)
        ->and($settings->bool(SettingKey::MANUALPRICE_CONFIRMER_MUST_DIFFER))->toBeTrue();
});

it('seeds both price adjustments for every karat, and a new karat starts at zero', function () {
    expect(KaratPriceAdjustment::query()->count())->toBe(Karat::query()->count() * 2)
        ->and(KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'buy')->value('value'))->toBe('-15.0000');

    $karat = Karat::factory()->create(['karat_code' => 14]);

    expect(KaratPriceAdjustment::query()->where('karat_code', $karat->karat_code)->pluck('value')->all())->toBe(['0.0000', '0.0000']);
});

it('keeps the history tables and prices append-only', function (string $table, array $row, string $sql) {
    $actor = SystemActor::id();
    DB::table($table)->insert(array_map(fn ($v) => $v === '@actor' ? $actor : $v, $row));

    expect(fn () => DB::statement($sql))->toThrow(QueryException::class, 'append-only');
})->with([
    'setting history' => ['setting_history', ['setting_key' => 'vat.pct', 'old_numeric' => 14, 'new_numeric' => 15, 'changed_by' => '@actor', 'reason' => 'Test row'], 'DELETE FROM setting_history'],
    'gold price' => ['gold_price', ['source' => 'feed', 'bid_24k' => 5000, 'ask_24k' => 5010, 'recorded_by' => '@actor'], 'UPDATE gold_price SET bid_24k = 1'],
    'adjustment history' => ['karat_price_adjustment_history', ['karat_code' => 21, 'side' => 'buy', 'old_kind' => 'fixed', 'old_value' => -15, 'new_kind' => 'fixed', 'new_value' => -10, 'changed_by' => '@actor', 'reason' => 'Test row'], 'DELETE FROM karat_price_adjustment_history'],
]);
