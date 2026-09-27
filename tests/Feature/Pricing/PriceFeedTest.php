<?php

use App\Enums\ManualPriceStatus;
use App\Models\GoldPrice;
use App\Models\ManualGoldPrice;
use App\Models\PriceFeedStatus;
use App\Models\Staff;
use App\Support\SystemActor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Spec 005 US1 / Technical Spec Part 4 §1. The provider is always faked; the
// credentials here are dummies — real ones live only in .env.

const FEED = 'https://feed.test';
const PRICE_URL = FEED.'/v1/datafeed/METAL_PRICE_TYPE_EGY/xau/price';

beforeEach(function () {
    config(['services.gold_feed' => ['base_url' => FEED, 'username' => 'dummy-user', 'password' => 'dummy-pass', 'timeout' => 10]]);
    Cache::flush();
});

function fakeProvider(array|Closure $price, int $authStatus = 200): void
{
    Http::fake([
        FEED.'/v1/auth' => Http::response($authStatus === 200 ? ['access_token' => 'token-1'] : ['error' => 'no'], $authStatus),
        PRICE_URL => is_array($price) ? Http::response($price) : $price,
    ]);
}

function pullFeed(): int
{
    return test()->artisan('pricing:pull-feed')->run();
}

it('records a changed bid and ask as the current price, attributed to the system actor', function () {
    fakeProvider(['bidPrice' => 7944.5, 'askPrice' => 7990.25]);

    expect(pullFeed())->toBe(0);

    $current = GoldPrice::current();
    expect($current->source->value)->toBe('feed')
        ->and((string) $current->bid_24k)->toBe('7944.5000')
        ->and((string) $current->ask_24k)->toBe('7990.2500')
        ->and($current->recorded_by)->toBe(SystemActor::id())
        ->and(PriceFeedStatus::query()->find('default')->last_success_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === FEED.'/v1/auth'
        && $request['username'] === 'dummy-user' && $request['password'] === 'dummy-pass');
    Http::assertSent(fn ($request) => $request->url() === PRICE_URL && $request->hasHeader('Authorization', 'Bearer token-1'));
});

it('writes nothing new when the price did not change, but records the good reading', function () {
    fakeProvider(['bidPrice' => 7944, 'askPrice' => 7990]);
    pullFeed();
    $this->travel(2)->minutes();

    pullFeed();

    expect(GoldPrice::query()->count())->toBe(1)
        ->and(PriceFeedStatus::query()->find('default')->last_success_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('reuses the cached token and signs in again once after a 401', function () {
    $calls = 0;
    fakeProvider(function () use (&$calls) {
        $calls++;

        return $calls === 2 ? Http::response([], 401) : Http::response(['bidPrice' => 7944 + $calls, 'askPrice' => 7990 + $calls]);
    });

    pullFeed(); // login + price
    pullFeed(); // cached token → 401 → login again + price

    Http::assertSentCount(5);
    expect((string) GoldPrice::current()->bid_24k)->toBe('7947.0000');
});

it('writes no price and keeps no secret when the provider fails', function (Closure $setUp, string $error) {
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7000', 'ask_24k' => '7050', 'recorded_by' => SystemActor::id(), 'effective_at' => now()->subMinute()]);
    $setUp();

    expect(pullFeed())->toBe(1);

    $status = PriceFeedStatus::query()->find('default');
    expect(GoldPrice::query()->count())->toBe(1)
        ->and($status->last_failure_at)->not->toBeNull()
        ->and($status->last_error)->toContain($error)
        ->and($status->last_error)->not->toContain('dummy-pass')
        ->and($status->last_error)->not->toContain('token-1');
})->with([
    'login refused' => [fn () => fakeProvider(['bidPrice' => 1, 'askPrice' => 2], 401), 'sign-in'],
    'server error' => [fn () => fakeProvider(fn () => Http::response('boom', 500)), 'HTTP 500'],
    'timeout' => [fn () => fakeProvider(fn () => throw new ConnectionException('timed out')), 'unreachable'],
    'missing field' => [fn () => fakeProvider(['bidPrice' => 7944]), 'askPrice'],
    'zero bid' => [fn () => fakeProvider(['bidPrice' => 0, 'askPrice' => 7990]), 'bidPrice'],
    'ask below bid' => [fn () => fakeProvider(['bidPrice' => 8000, 'askPrice' => 7990]), 'askPrice'],
]);

it('skips quietly when the feed is not configured', function () {
    config(['services.gold_feed.password' => null]);
    Http::fake();

    expect(pullFeed())->toBe(0);
    Http::assertNothingSent();
    expect(GoldPrice::query()->count())->toBe(0);
});

it('takes over from a manual price and closes the pending request', function () {
    $staff = Staff::factory()->create();
    $manual = ManualGoldPrice::query()->create([
        'bid_24k' => '9000', 'ask_24k' => '9050', 'requires_confirmation' => true, 'reason' => 'Feed down',
        'entered_by' => $staff->staff_id, 'status' => 'pending', 'expires_at' => now()->addDay(),
    ]);
    fakeProvider(['bidPrice' => 7944, 'askPrice' => 7990]);

    pullFeed();

    expect($manual->fresh()->status)->toBe(ManualPriceStatus::SUPERSEDED)
        ->and(GoldPrice::current()->source->value)->toBe('feed');
});

it('runs every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'pricing:pull-feed'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
