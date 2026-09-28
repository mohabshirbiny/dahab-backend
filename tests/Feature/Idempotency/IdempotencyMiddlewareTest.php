<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Spec 007 research R2: the shared Idempotency-Key layer.

beforeEach(function () {
    $this->runs = 0;
    $counter = function () {
        $this->runs++;

        return response()->json(['data' => ['run' => $this->runs, 'body' => request()->all()]], 201);
    };

    // Probe routes live in the test, not in routes/api.php.
    Route::middleware(['api', 'auth:staff', 'abilities:staff:access', 'idempotent'])->group(function () use ($counter) {
        Route::post('/api/v1/dashboard/_probe/idem', $counter)->name('probe.idem');
        Route::post('/api/v1/dashboard/_probe/idem-other', $counter)->name('probe.idem_other');
        Route::post('/api/v1/dashboard/_probe/idem-fail', function () {
            $this->runs++;

            throw new RuntimeException('boom');
        })->name('probe.idem_fail');
    });
    Route::middleware(['api', 'auth:customer', 'abilities:customer:access', 'idempotent'])
        ->post('/api/v1/customer/_probe/idem', $counter)->name('probe.customer_idem');

    $this->staff = Staff::factory()->create();
    $this->token = app(IssueTokenFamilyAction::class)->forStaff($this->staff)->accessToken;
});

function idemPost($test, string $token, string $uri, array $body, ?string $key)
{
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    // Same as TestCase::bearer() (protected): guards cache the user per app.
    app('auth')->forgetGuards();

    return $test->withToken($token)->postJson($uri, $body, $headers);
}

it('requires a UUID Idempotency-Key', function (?string $key) {
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)
        ->assertStatus(400)
        ->assertJsonPath('code', 'idempotency_key_required');

    expect($this->runs)->toBe(0)->and(IdempotencyKey::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'not a uuid' => ['abc'],
]);

it('runs the first request and replays the same one without running it again', function () {
    $key = (string) Str::uuid();

    $first = idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1, 'b' => 'x'], $key)
        ->assertStatus(201)
        ->assertHeaderMissing('Idempotent-Replayed');

    // Same body with keys in another order: the canonical hash matches.
    $replay = idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['b' => 'x', 'a' => 1], $key)
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($this->runs)->toBe(1)
        ->and($replay->json())->toBe($first->json());

    $row = IdempotencyKey::query()->sole();
    expect($row->state)->toBe('completed')
        ->and($row->response_status)->toBe(201)
        ->and($row->actor_kind)->toBe('staff')
        ->and($row->actor_staff_id)->toBe($this->staff->staff_id)
        ->and($row->endpoint)->toBe('probe.idem')
        ->and($row->expires_at->greaterThan(now()->addHours(23)))->toBeTrue();
});

it('refuses the same key with a different request', function () {
    $key = (string) Str::uuid();
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)->assertStatus(201);

    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 2], $key)
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_mismatch');

    expect($this->runs)->toBe(1);
});

it('refuses a key that is still in flight, and takes over an abandoned one', function () {
    $key = (string) Str::uuid();
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)->assertStatus(201);
    $row = IdempotencyKey::query()->sole();

    $row->forceFill(['state' => 'in_flight', 'response_status' => null, 'response_body' => null, 'completed_at' => null])->save();
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_in_progress');
    expect($this->runs)->toBe(1);

    $row->forceFill(['created_at' => now()->subSeconds(61)])->save();
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)
        ->assertStatus(201)
        ->assertHeaderMissing('Idempotent-Replayed');
    expect($this->runs)->toBe(2)
        ->and($row->fresh()->state)->toBe('completed');
});

it('marks a 5xx as failed so a retry runs again', function () {
    $key = (string) Str::uuid();

    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem-fail', [], $key)->assertStatus(500);
    expect(IdempotencyKey::query()->sole()->state)->toBe('failed');

    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem-fail', [], $key)->assertStatus(500);
    expect($this->runs)->toBe(2);
});

it('scopes a key per actor and per endpoint', function () {
    $key = (string) Str::uuid();
    $other = app(IssueTokenFamilyAction::class)->forStaff(Staff::factory()->create())->accessToken;

    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)->assertStatus(201);
    idemPost($this, $other, '/api/v1/dashboard/_probe/idem', ['a' => 1], $key)->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem-other', ['a' => 1], $key)->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');

    expect($this->runs)->toBe(3)->and(IdempotencyKey::query()->count())->toBe(3);
});

it('stores customer keys under row-level security', function () {
    $a = Customer::factory()->verified()->create();
    $b = Customer::factory()->verified()->create();
    $tokenA = app(IssueTokenFamilyAction::class)->forCustomer($a)->accessToken;

    idemPost($this, $tokenA, '/api/v1/customer/_probe/idem', ['a' => 1], (string) Str::uuid())->assertStatus(201);

    $row = IdempotencyKey::query()->sole();
    expect($row->actor_kind)->toBe('customer')->and($row->actor_customer_id)->toBe($a->customer_id);

    if (DB::getDriverName() !== 'pgsql') {
        return;
    }

    DatabaseActor::push('customer', customerId: $b->customer_id);
    try {
        expect(DB::table('idempotency_key')->count())->toBe(0);
    } finally {
        DatabaseActor::pop();
    }

    DatabaseActor::push('customer', customerId: $a->customer_id);
    try {
        expect(DB::table('idempotency_key')->count())->toBe(1);
    } finally {
        DatabaseActor::pop();
    }
});

it('prunes expired keys', function () {
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 1], (string) Str::uuid())->assertStatus(201);
    idemPost($this, $this->token, '/api/v1/dashboard/_probe/idem', ['a' => 2], (string) Str::uuid())->assertStatus(201);
    IdempotencyKey::query()->oldest('id')->first()->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->artisan('idempotency:prune')->assertSuccessful();

    expect(IdempotencyKey::query()->count())->toBe(1);
});
