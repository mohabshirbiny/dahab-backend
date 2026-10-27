<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\Overview\OverviewCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Finance;
use Tests\Support\Ledger;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Dashboard performance: the Overview's global figures are reused for a few
// seconds, one entry per section, read only after the permission check.

beforeEach(function () {
    Notification::fake();
    Orders::workedPrices();
    config(['dahab-finance.overview_cache_seconds' => 30]);
    Cache::flush();
});

it('reuses the wallet figures within the time and reads them again after', function () {
    $mona = Customer::factory()->verified()->create();
    Ledger::topUp($mona, '100');
    Finance::staff($this, SeedRole::CEO);

    $this->getJson('/api/v1/dashboard/wallets/overview')->assertOk()->assertJsonPath('data.available', '100.0000');
    Ledger::topUp($mona, '50');
    $this->getJson('/api/v1/dashboard/wallets/overview')->assertOk()->assertJsonPath('data.available', '100.0000');

    $this->travel(31)->seconds();
    $this->getJson('/api/v1/dashboard/wallets/overview')->assertOk()->assertJsonPath('data.available', '150.0000');
});

it('gives the same answer cached as fresh', function () {
    Ledger::topUp(Customer::factory()->verified()->create(), '700');
    Orders::accepted($this);
    Finance::staff($this, SeedRole::CEO);

    $first = $this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data');
    $cached = $this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data');
    config(['dahab-finance.overview_cache_seconds' => 0]);
    $fresh = $this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data');

    expect($cached)->toBe($first)->toBe($fresh)
        ->and(Cache::has(OverviewCache::PREFIX.'earnings'))->toBeTrue()
        ->and(Cache::has(OverviewCache::PREFIX.'orders'))->toBeTrue();
});

it('never gives a cached section to a viewer without its code', function () {
    Ledger::topUp(Customer::factory()->verified()->create(), '700');
    Finance::staff($this, SeedRole::CEO);
    $this->getJson('/api/v1/dashboard/overview')->assertOk()->assertJsonStructure(['data' => ['earnings', 'orders', 'this_month']]);
    $this->getJson('/api/v1/dashboard/wallets/overview')->assertOk();
    expect(Cache::has(OverviewCache::PREFIX.'wallets'))->toBeTrue();

    Finance::staff($this, SeedRole::OPERATIONS);
    expect($this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data'))->not->toHaveKey('earnings');
    $this->getJson('/api/v1/dashboard/wallets/overview')->assertForbidden();

    Finance::staff($this, SeedRole::IGI_BRANCH);
    expect($this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data'))->toBe([]);
});

it('gives a signed-in staff member a wider API bucket than a customer', function () {
    $limit = fn ($user) => RateLimiter::limiter('api')(tap(Request::create('/api/v1/x'), fn (Request $r) => $r->setUserResolver(fn () => $user)));

    $staff = $limit(Staff::factory()->create());
    $customer = $limit(Customer::factory()->verified()->create());
    $anonymous = $limit(null);

    expect($staff)->toBeInstanceOf(Limit::class)
        ->and($staff->maxAttempts)->toBe(240)
        ->and($customer->maxAttempts)->toBe(60)
        ->and($anonymous->maxAttempts)->toBe(60);
});
