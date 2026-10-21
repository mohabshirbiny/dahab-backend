<?php

use App\Support\Database\RowSecurityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 003 FR-002 at run time: while the database connection can bypass row-level security
// the API serves nothing, on every environment (DatabaseRoleTest covers the test database only).

it('serves the API while the role is subject to row-level security', function () {
    $this->getJson('/api/v1/health')->assertOk();

    expect(Cache::get(RowSecurityGuard::CACHE_KEY))->toBeFalse();
});

it('stops every API request while the connection bypasses row-level security', function () {
    Cache::put(RowSecurityGuard::CACHE_KEY, true);

    $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson([
        'message' => 'The service is unavailable.',
        'code' => 'database_isolation_off',
    ]);
    $this->getJson('/api/v1/customer/me/orders')->assertStatus(503)->assertJsonPath('code', 'database_isolation_off');
    $this->getJson('/api/v1/reference/karats')->assertStatus(503);
});

it('trusts a role that owns the tables when every one forces row security and it has no BYPASSRLS', function () {
    $owned = DB::selectOne("SELECT count(*) AS n FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relrowsecurity AND pg_has_role(current_user, relowner, 'USAGE')")->n;

    expect($owned)->toBeGreaterThan(0)
        ->and(app(RowSecurityGuard::class)->inspect())->toMatchArray(['reasons' => [], 'bypasses' => false]);
});

it('detects a superuser or BYPASSRLS role', function () {
    // The cluster's bootstrap superuser has both attributes; no role needs creating.
    $super = DB::selectOne('SELECT rolname FROM pg_roles WHERE rolsuper AND rolbypassrls ORDER BY oid LIMIT 1');
    if ($super === null) {
        $this->markTestSkipped('no superuser role visible');
    }

    $result = app(RowSecurityGuard::class)->inspect($super->rolname);

    expect($result['bypasses'])->toBeTrue()
        ->and($result['reasons'])->toContain('superuser')->toContain('BYPASSRLS');
});

it('fails closed on a role it cannot find', function () {
    expect(app(RowSecurityGuard::class)->inspect('dahab_no_such_role'))
        ->toMatchArray(['reasons' => ['unknown role'], 'bypasses' => true]);
});

it('detects an owned table with row security enabled but not forced', function () {
    DB::statement('CREATE TABLE rls_guard_probe (id int)');
    DB::statement('ALTER TABLE rls_guard_probe ENABLE ROW LEVEL SECURITY');

    $result = app(RowSecurityGuard::class)->inspect();

    expect($result['bypasses'])->toBeTrue()
        ->and($result['reasons'])->toBe(['owns tables with row security not forced: rls_guard_probe']);
});

it('detects a transaction-mode pooler host', function () {
    config(['database.connections.'.DB::getDefaultConnection().'.host' => 'ep-quiet-sun-123456-pooler.eu-central-1.aws.neon.tech']);

    expect(app(RowSecurityGuard::class)->inspect())->toMatchArray([
        'reasons' => ['connected through a transaction-mode pooler (-pooler host)'],
        'bypasses' => true,
    ]);
});

it('exits non-zero from the command on an unsafe connection and refreshes the cached answer', function () {
    config(['database.connections.'.DB::getDefaultConnection().'.host' => 'ep-x-pooler.neon.tech']);

    $this->artisan('db:check-isolation')
        ->expectsOutputToContain('bypasses row-level security: connected through a transaction-mode pooler')
        ->assertFailed();

    expect(Cache::get(RowSecurityGuard::CACHE_KEY))->toBeTrue();
});

it('passes the command on a safe connection and refreshes the cached answer', function () {
    Cache::put(RowSecurityGuard::CACHE_KEY, true);

    $this->artisan('db:check-isolation')
        ->expectsOutputToContain('is subject to row-level security')
        ->assertSuccessful();

    expect(Cache::get(RowSecurityGuard::CACHE_KEY))->toBeFalse();
    $this->getJson('/api/v1/health')->assertOk();
});
