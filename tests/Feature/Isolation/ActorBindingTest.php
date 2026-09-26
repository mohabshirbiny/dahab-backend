<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Spec 003 US2 / FR-004 / FR-010 / SC-002 / SC-003: no actor → nothing;
// an identity never outlives its unit of work on the same connection.

function visibleCustomers(): int
{
    return (int) DB::selectOne('SELECT count(*) AS n FROM customer')->n;
}

it('fails closed when no actor is bound', function () {
    Customer::factory()->count(2)->create();

    DatabaseActor::push('');

    expect(visibleCustomers())->toBe(0);
    expect(function () {
        DB::transaction(fn () => DB::table('customer')->insert([
            'customer_id' => (string) Str::uuid(), 'display_ref' => '777777', 'phone' => '+201099999999',
            'preferred_lang' => 'ar', 'status' => 'active', 'is_verified' => true, 'is_suspended' => false,
        ]));
    })->toThrow(QueryException::class);

    DatabaseActor::pop();
});

it('does not carry a customer identity past its request, even when the request fails', function () {
    $a = Customer::factory()->create();
    Customer::factory()->create();
    $token = app(IssueTokenFamilyAction::class)->forCustomer($a)->accessToken;

    Route::middleware(['api', 'auth:customer', 'abilities:customer:access'])
        ->get('/api/v1/customer/_probe/boom', fn () => throw new RuntimeException('boom'));

    $this->bearer($token)->getJson('/api/v1/customer/auth/me')->assertOk();
    $this->bearer($token)->getJson('/api/v1/customer/_probe/boom')->assertStatus(500);

    // Back in the test's maintenance frame — not customer A's.
    expect(DatabaseActor::scope())->toBe('maintenance')
        ->and(DB::selectOne("SELECT current_setting('app.current_customer_id', true) AS v")->v)->toBe('');

    DatabaseActor::push('');
    expect(visibleCustomers())->toBe(0);
    DatabaseActor::pop();
});

it('restores the enclosing frame after a nested elevation, even if the work throws', function () {
    Customer::factory()->count(3)->create();
    DatabaseActor::push('');

    try {
        DatabaseActor::elevate('system', function () {
            expect(visibleCustomers())->toBe(3);
            throw new RuntimeException('job failed');
        });
    } catch (RuntimeException) {
    }

    expect(DatabaseActor::scope())->toBe('')->and(visibleCustomers())->toBe(0);
    DatabaseActor::pop();
});

it('rejects unknown scopes', function () {
    DatabaseActor::push('superuser');
})->throws(InvalidArgumentException::class);
