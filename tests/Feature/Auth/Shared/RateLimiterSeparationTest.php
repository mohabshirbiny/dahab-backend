<?php

use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const RATE_DEVICE = ['X-Device-Id' => 'rate-limit-device-0000000000000000'];

function rateRegisterBody(string $phone, string $password = 'short'): array
{
    // `short` fails validation (422) — cheap, and the throttle counts it anyway.
    return ['phone' => $phone, 'password' => $password, 'preferred_lang' => 'en'];
}

it('does not let registration attempts lock the same phone out of login', function () {
    $phone = '+201000004444';
    $max = config('dahab-auth.rate_limits.customer_register.per_identity_max');

    for ($i = 0; $i < $max; $i++) {
        $this->postJson('/api/v1/customer/auth/register/start', rateRegisterBody($phone), RATE_DEVICE)->assertStatus(422);
    }

    // Registration budget for this phone is spent: generic throttle, not a lockout.
    $this->postJson('/api/v1/customer/auth/register/start', rateRegisterBody($phone), RATE_DEVICE)
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');

    // The login limiter has not seen a single hit: the full 5 failures are still allowed.
    for ($i = 0; $i < config('dahab-auth.rate_limits.customer_login.per_identity_max'); $i++) {
        $this->postJson('/api/v1/customer/auth/login', ['phone' => $phone, 'password' => 'wrong-password-abc'], RATE_DEVICE)
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_credentials');
    }
});

it('does not let failed logins eat the same phone\'s registration budget', function () {
    $phone = '+201000005555';

    for ($i = 0; $i < config('dahab-auth.rate_limits.customer_login.per_identity_max'); $i++) {
        $this->postJson('/api/v1/customer/auth/login', ['phone' => $phone, 'password' => 'wrong-password-abc'], RATE_DEVICE)->assertStatus(401);
    }
    $this->postJson('/api/v1/customer/auth/login', ['phone' => $phone, 'password' => 'wrong-password-abc'], RATE_DEVICE)
        ->assertStatus(429)
        ->assertJsonPath('code', 'account_locked');

    // Login for this phone is locked; registering it is a different budget.
    registerCustomer(['phone' => $phone], RATE_DEVICE)->assertCreated();
});

it('reports an exhausted per-IP login bucket as too_many_requests, not account_locked', function () {
    $ipMax = config('dahab-auth.rate_limits.customer_login.per_ip_max');

    for ($i = 0; $i < $ipMax; $i++) {
        $this->postJson('/api/v1/customer/auth/login', ['phone' => '+2010000060'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'password' => 'whatever-123'], RATE_DEVICE)
            ->assertStatus(401);
    }

    $this->postJson('/api/v1/customer/auth/login', ['phone' => '+201000006099', 'password' => 'whatever-123'], RATE_DEVICE)
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('gives the dashboard login its own lockout that customer login never sees', function () {
    // /dashboard/auth/login is not built yet (tasks T072/T076); exercise the real limiter on a probe.
    Route::middleware(['api', 'throttle:auth.staff.login'])
        ->post('/api/v1/dashboard/auth/_probe-login', fn () => response()->json(['ok' => true]));

    $max = config('dahab-auth.rate_limits.staff_login.per_identity_max');

    for ($i = 0; $i < $max; $i++) {
        $this->postJson('/api/v1/dashboard/auth/_probe-login', ['email' => 'Ops@Dahab.Test'])->assertOk();
    }

    // Identity is normalised (case-insensitive), so the same mailbox is locked whatever the casing.
    $this->postJson('/api/v1/dashboard/auth/_probe-login', ['email' => 'ops@dahab.test'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'account_locked')
        ->assertHeader('Retry-After');

    // Another mailbox and the customer login are untouched.
    $this->postJson('/api/v1/dashboard/auth/_probe-login', ['email' => 'finance@dahab.test'])->assertOk();
    $this->postJson('/api/v1/customer/auth/login', ['phone' => '+201000007777', 'password' => 'wrong-password-abc'], RATE_DEVICE)
        ->assertStatus(401);
});

it('decides account_locked vs too_many_requests from the limiter, never from the URL', function () {
    // A path that used to be hard-coded as "the login URL", throttled by a non-login limiter.
    Route::middleware(['api', 'throttle:auth.otp.send'])
        ->post('/api/v1/dashboard/auth/login', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'throttle:auth.password_reset.request'])
        ->post('/api/v1/customer/auth/login-lookalike', fn () => response()->json(['ok' => true]));
    // An arbitrary path throttled by an identity-lockout limiter.
    Route::middleware(['api', 'throttle:auth.customer.login'])
        ->post('/api/v1/anything/else', fn () => response()->json(['ok' => true]));

    $this->postJson('/api/v1/dashboard/auth/login', ['phone' => '+201000008801'])->assertOk();
    $this->postJson('/api/v1/dashboard/auth/login', ['phone' => '+201000008801'])
        ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/customer/auth/login-lookalike', ['phone' => '+201000008802'])->assertOk();
    }
    $this->postJson('/api/v1/customer/auth/login-lookalike', ['phone' => '+201000008802'])
        ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/anything/else', ['phone' => '+201000008803'])->assertOk();
    }
    $this->postJson('/api/v1/anything/else', ['phone' => '+201000008803'])
        ->assertStatus(429)->assertJsonPath('code', 'account_locked');
});

it('registers a separate limiter for each auth concern with disjoint bucket keys', function () {
    $request = Request::create('/x', 'POST', ['phone' => '+201000009000', 'email' => 'a@dahab.test', 'challenge_id' => 'ch-1'], server: ['REMOTE_ADDR' => '203.0.113.9']);

    $limiters = [
        'auth.customer.register',
        'auth.customer.login',
        'auth.staff.login',
        'auth.password_reset.request',
        'auth.otp.send',
        'auth.otp.verify',
        'auth.refresh',
    ];

    $keys = [];
    foreach ($limiters as $name) {
        $resolver = RateLimiter::limiter($name);
        expect($resolver)->not->toBeNull("limiter {$name} is not registered");

        foreach (Arr::wrap($resolver($request)) as $limit) {
            expect($limit)->toBeInstanceOf(Limit::class);
            $keys[] = $limit->key;
        }
    }

    expect($keys)->toHaveCount(count(array_unique($keys)));

    $prefixes = collect($keys)->map(fn ($k) => Str::before($k, ':'))->unique()->values()->all();
    expect($prefixes)->toEqualCanonicalizing([
        'cust-register-id', 'cust-register-ip', 'cust-login-id', 'cust-login-ip',
        'staff-login-id', 'staff-login-ip', 'reset-id', 'reset-ip',
        'otp-cooldown', 'otp-hourly', 'otp-verify', 'refresh',
    ]);
});

it('sizes each limiter from its own config block', function () {
    $request = Request::create('/x', 'POST', ['phone' => '+201000009001', 'email' => 'b@dahab.test'], server: ['REMOTE_ADDR' => '203.0.113.10']);
    $limits = fn (string $name) => collect(Arr::wrap(RateLimiter::limiter($name)($request)));

    $register = config('dahab-auth.rate_limits.customer_register');
    $identity = $limits('auth.customer.register')->first();
    expect($identity->maxAttempts)->toBe($register['per_identity_max'])
        ->and($identity->decaySeconds)->toBe($register['per_identity_window']);

    $staff = config('dahab-auth.rate_limits.staff_login');
    $identity = $limits('auth.staff.login')->first();
    expect($identity->maxAttempts)->toBe($staff['per_identity_max'])
        ->and($identity->decaySeconds)->toBe($staff['per_identity_window']);

    $customer = config('dahab-auth.rate_limits.customer_login');
    $identity = $limits('auth.customer.login')->first();
    expect($identity->maxAttempts)->toBe($customer['per_identity_max'])
        ->and($identity->decaySeconds)->toBe($customer['per_identity_window']);
});

it('turns only the identity bucket of a login limiter into account_locked', function () {
    $request = Request::create('/x', 'POST', ['phone' => '+201000009002'], server: ['REMOTE_ADDR' => '203.0.113.11']);

    [$identityLimit, $ipLimit] = Arr::wrap(RateLimiter::limiter('auth.customer.login')($request));

    expect($ipLimit->responseCallback)->toBeNull();

    try {
        ($identityLimit->responseCallback)($request, ['Retry-After' => 42]);
        $this->fail('identity lockout should throw');
    } catch (AuthApiException $e) {
        expect($e->errorCode)->toBe(AuthErrorCode::ACCOUNT_LOCKED)
            ->and($e->statusCode)->toBe(429)
            ->and($e->headers)->toBe(['Retry-After' => 42]);
    }
});
