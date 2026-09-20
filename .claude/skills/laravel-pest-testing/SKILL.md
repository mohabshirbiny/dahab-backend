---
name: "laravel-pest-testing"
description: "Write Pest 3 feature tests that go through the HTTP boundary and assert on persisted rows, response envelopes, jobs, events and notifications. Use when writing or fixing tests, or when a spec-kit task says 'test'."
argument-hint: "Endpoint, Action or behavior to test"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Layout

- `tests/Feature/<Domain>/<Behavior>Test.php` for HTTP-level tests (required for state changes, Constitution V).
- `tests/Unit/` only for pure logic (calculators, value objects).
- `php artisan make:test <Domain>/<Behavior>Test --pest`

## Template

```php
<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a buy request for the authenticated customer', function () {
    Notification::fake();
    $customer = Customer::factory()->verified()->create();
    Sanctum::actingAs($customer, ['customer:access'], 'customer');

    $this->postJson(route('api.v1.buy-requests.store'), ['grams' => '10.500'])
        ->assertCreated()
        ->assertJsonPath('data.grams', '10.500');

    $this->assertDatabaseHas('buy_request', ['customer_id' => $customer->id, 'grams' => '10.500']);
});

it('refuses an unverified customer', function () {
    Sanctum::actingAs(Customer::factory()->create(), ['customer:access'], 'customer');

    $this->postJson(route('api.v1.buy-requests.store'), ['grams' => '10.500'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    $this->assertDatabaseCount('buy_request', 0);
});
```

## Rules

- Every endpoint: happy path and at least one refusal (validation 422 with `code: validation_failed`, 401, 403, or an invalid state transition).
- Assert observable effects: DB rows (`assertDatabaseHas`/`Missing`/`Count`), JSON paths and the envelope shape, `Queue::fake()` + `Queue::assertPushed`, `Event::fake`, `Notification::assertSentTo`. Don't mock the Action you're testing.
- Ledger paths: assert that entries balance and that balances changed by exactly the expected decimal string.
- Use factories and states. No hand-written inserts, no shared fixtures across tests.
- Use `->with([...])` datasets for validation matrices.
- Freeze time with `$this->freezeTime()` / `travelTo()` for expiry logic.
- **PostgreSQL-only behavior** (RLS, CHECK constraints, grants, `lockForUpdate`) can't be verified on the default SQLite `:memory:` config in `phpunit.xml`. Put those tests in a `pgsql` group that runs against the Docker Postgres:
  ```php
  it('hides other customers wallets', function () { ... })->group('pgsql');
  ```
  Run with `DB_CONNECTION=pgsql DB_DATABASE=dahab_test php vendor/bin/pest --group=pgsql`.

## Run

```bash
composer test                      # full suite (clears config first)
php vendor/bin/pest --filter="buy request"
php vendor/bin/pest --parallel
```

A task isn't done until the suite is green.
