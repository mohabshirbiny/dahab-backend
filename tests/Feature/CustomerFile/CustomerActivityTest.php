<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Audit/AuditLogTestHelpers.php';

uses(RefreshDatabase::class);

// Spec 007 US3 / FR-011, contract §5 (History), SC-005.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $this->verification = Staff::factory()->role(SeedRole::VERIFICATION)->create();

    $this->customer = Customer::factory()->create();
    $this->document = IdentityDocument::factory()->for($this->customer)->verified()->create();
    $c = $this->customer->customer_id;

    // Newest last: the endpoint lists newest first.
    $this->registered = auditRow('auth.customer.registered', ['actor_customer_id' => $c, 'entity_type' => 'customer', 'entity_id' => $c, 'created_at' => now()->subDays(5)]);
    $this->reviewed = auditRow('identity.document.approved', ['actor_staff_id' => $this->verification->staff_id, 'entity_type' => 'identity_document', 'entity_id' => $this->document->document_id, 'created_at' => now()->subDays(4)]);
    $this->signedIn = auditRow('auth.customer.sign_in', ['actor_customer_id' => $c, 'created_at' => now()->subDays(3)]);
    $this->rotated = auditRow('auth.token.rotated', ['actor_customer_id' => $c, 'created_at' => now()->subDays(2)]);
    $this->suspended = auditRow('auth.customer.suspended', ['actor_staff_id' => $this->ceo->staff_id, 'entity_type' => 'customer', 'entity_id' => $c, 'created_at' => now()->subDay()]);

    // Someone else's activity never shows.
    $other = Customer::factory()->create();
    auditRow('auth.customer.sign_in', ['actor_customer_id' => $other->customer_id]);
    auditRow('auth.customer.suspended', ['actor_staff_id' => $this->ceo->staff_id, 'entity_type' => 'customer', 'entity_id' => $other->customer_id]);
});

function activityOf($test, Customer $customer, string $query = '')
{
    return $test->getJson("/api/v1/dashboard/customers/{$customer->customer_id}/activity{$query}");
}

it('lists what happened to and by the customer, newest first, without token rotations', function () {
    Sanctum::actingAs($this->ceo, ['staff:access'], 'staff');

    $res = activityOf($this, $this->customer)->assertOk();

    expect(array_column($res->json('data'), 'id'))->toBe([$this->suspended, $this->signedIn, $this->reviewed, $this->registered])
        ->and($res->json('data.0.label'))->toBe('Account suspended')
        ->and($res->json('data.0.category'))->toBe('accounts')
        ->and($res->json('meta.next_cursor'))->toBeNull();
});

it('pages with a cursor without gaps or repeats', function () {
    Sanctum::actingAs($this->ceo, ['staff:access'], 'staff');

    $first = activityOf($this, $this->customer, '?per_page=3')->assertOk();
    $cursor = $first->json('meta.next_cursor');
    expect($first->json('data'))->toHaveCount(3)->and($cursor)->not->toBeNull();

    $second = activityOf($this, $this->customer, '?per_page=3&cursor='.$cursor)->assertOk();

    expect([...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')])
        ->toBe([$this->suspended, $this->signedIn, $this->reviewed, $this->registered]);
});

it('shows an own-actions holder only what they did', function () {
    Sanctum::actingAs($this->verification, ['staff:access'], 'staff');

    $res = activityOf($this, $this->customer)->assertOk();

    expect(array_column($res->json('data'), 'id'))->toBe([$this->reviewed]);
});

it('needs customer.view and an audit permission', function () {
    // IGI holds neither customer.view nor an audit permission.
    Sanctum::actingAs(Staff::factory()->role(SeedRole::IGI_BRANCH)->create(), ['staff:access'], 'staff');
    activityOf($this, $this->customer)->assertForbidden()->assertJsonPath('code', 'permission_denied');

    // customer.view without any audit permission.
    DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['audit.view_all', 'audit.view_own'])->pluck('id'))->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($this->verification->fresh(), ['staff:access'], 'staff');
    activityOf($this, $this->customer)->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('rejects a malformed cursor', function () {
    Sanctum::actingAs($this->ceo, ['staff:access'], 'staff');

    activityOf($this, $this->customer, '?cursor=nonsense')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('stays a bounded number of queries at volume, with the customer index in place', function () {
    Sanctum::actingAs($this->ceo, ['staff:access'], 'staff');
    $c = $this->customer->customer_id;

    DB::statement("INSERT INTO audit_log (actor_customer_id, action, entity_type, after_json, created_at)
        SELECT '{$c}', 'auth.customer.sign_in', 'auth', '{\"outcome\":\"success\"}', now() - (g || ' minutes')::interval
        FROM generate_series(1, 10000) g");

    DB::flushQueryLog();
    DB::enableQueryLog();
    activityOf($this, $this->customer)->assertOk()->assertJsonCount(20, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);

    // Which index the planner picks depends on the data mix (here every row is this
    // customer's, so a scan by time is just as cheap); the partial index must exist.
    expect(DB::table('pg_indexes')->where('tablename', 'audit_log')->where('indexname', 'idx_audit_actor_customer')->exists())->toBeTrue();
});
