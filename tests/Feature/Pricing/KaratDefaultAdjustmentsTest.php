<?php

use App\Enums\SeedRole;
use App\Models\GoldPrice;
use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use App\Models\KaratPriceAdjustmentHistory;
use App\Models\Staff;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Testing fixes item 1: every karat starts with a 0 adjustment on both sides (was buy −15 / sell +15),
// so the price entered is used as is (only purity applies). Rows staff edited keep their value.

function zeroAdjustmentsMigration(): object
{
    return require database_path('migrations/2026_10_17_000010_zero_default_karat_adjustments.php');
}

/** @return array<int, array{buy: string, sell: string}> */
function adjustmentValues(): array
{
    return KaratPriceAdjustment::query()->orderBy('karat_code')->get()
        ->groupBy('karat_code')
        ->map(fn ($rows) => [
            'buy' => $rows->firstWhere('side.value', 'buy')->value,
            'sell' => $rows->firstWhere('side.value', 'sell')->value,
        ])->all();
}

it('ends a fresh database with 0 / 0 on every karat', function () {
    expect(KaratPriceAdjustment::query()->count())->toBe(Karat::query()->count() * 2)
        ->and(KaratPriceAdjustment::query()->where(fn ($q) => $q->where('value', '<>', 0)->orWhere('kind', '<>', 'fixed'))->exists())->toBeFalse();
});

it('starts a karat added later at 0 / 0', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->withKnownDevice('dev-1')->create();

    $this->bearer(staffAccessToken($coo))->postJson('/api/v1/dashboard/karats', ['code' => 14, 'purity' => '0.58500'])->assertCreated();

    expect(adjustmentValues()[14])->toBe(['buy' => '0.0000', 'sell' => '0.0000']);
});

it('resets only the rows staff never changed, and its down() restores ±15 on the same rows', function () {
    $migration = zeroAdjustmentsMigration();
    $migration->down();
    expect(adjustmentValues()[21])->toBe(['buy' => '-15.0000', 'sell' => '15.0000']);

    // Finance edited the 21K buy side before the migration ran.
    $staff = Staff::factory()->create();
    KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'buy')->update(['kind' => 'fixed', 'value' => '-9']);
    KaratPriceAdjustmentHistory::query()->create([
        'karat_code' => 21, 'side' => 'buy', 'old_kind' => 'fixed', 'old_value' => '-15', 'new_kind' => 'fixed', 'new_value' => '-9',
        'changed_by' => $staff->staff_id, 'reason' => 'Market moved',
    ]);

    $migration->up();

    $values = adjustmentValues();
    expect($values[21])->toBe(['buy' => '-9.0000', 'sell' => '0.0000'])
        ->and($values[24])->toBe(['buy' => '0.0000', 'sell' => '0.0000'])
        ->and($values[18])->toBe(['buy' => '0.0000', 'sell' => '0.0000']);

    $migration->down();
    expect(adjustmentValues()[21])->toBe(['buy' => '-9.0000', 'sell' => '15.0000']);
    $migration->up();
});

it('prices at the market with no adjustment: sellers get bid × purity / 0.999, buyers pay ask × purity / 0.999', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7944', 'ask_24k' => '7990', 'recorded_by' => SystemActor::id()]);
    $token = staffAccessToken(Staff::factory()->role(SeedRole::OPERATIONS)->create());

    $karats = collect($this->bearer($token)->getJson('/api/v1/dashboard/gold-prices/current')->assertOk()->json('data.karats'))->keyBy('code');

    // Half-up at 4 dp, as the calculator rounds.
    $price = fn (string $rate, string $purity) => bcdiv(bcadd(bcdiv(bcmul($rate, $purity, 12), '0.999', 12), '0.00005', 12), '1', 4);

    foreach ([24 => '0.99900', 21 => '0.87500', 18 => '0.75000'] as $code => $purity) {
        expect($karats[$code]['sellers_get'])->toBe($price('7944', $purity))
            ->and($karats[$code]['buyers_pay'])->toBe($price('7990', $purity))
            ->and($karats[$code]['sellers_get'])->toBe($karats[$code]['market_bid']);
    }
    expect($karats[24]['sellers_get'])->toBe('7944.0000');
});
