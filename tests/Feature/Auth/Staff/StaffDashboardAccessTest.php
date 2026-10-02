<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    // Probe route lives in the test, not in routes/api.php.
    Route::middleware(['api', 'auth:staff', 'abilities:staff:access', 'staff.permission:customer.suspend'])
        ->get('/api/v1/dashboard/_probe', fn () => response()->json(['ok' => true]));
});

function staffBearer(Staff $staff): string
{
    return app(IssueTokenFamilyAction::class)->forStaff($staff)->accessToken;
}

it('authenticates a staff token on the dashboard and returns roles and permissions', function () {
    $staff = Staff::factory()->role(SeedRole::CEO)->create(['email' => 'boss@dahab.test']);

    $this->withToken(staffBearer($staff))->getJson('/api/v1/dashboard/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $staff->staff_id)
        ->assertJsonPath('data.email', 'boss@dahab.test')
        ->assertJsonPath('data.role', 'ceo')
        ->assertJsonPath('data.roles', ['ceo'])
        ->assertJsonPath('data.permissions', ['audit.view_all', 'audit.view_own', 'branches.manage', 'buy_request.view', 'customer.suspend', 'customer.view', 'gold_price.confirm', 'gold_price.enter', 'identity.review', 'identity.view', 'inspection.enter', 'karats.create', 'karats.toggle', 'listing.request_changes', 'listing.review', 'listing.takedown', 'order.cancel', 'order.change_branch', 'order.extend_deadline', 'order.handover', 'order.price_adjust', 'order.receive', 'order.view', 'pricing.rates.manage', 'pricing.view', 'reference.view', 'roles.manage', 'settings.manage', 'staff.view', 'topup.accounts.manage', 'topup.match', 'wallet.view'])
        ->assertJsonMissingPath('data.password_hash');
});

it('lists only the branch order codes for the inspector role', function () {
    // igi_branch held nothing until spec 012 gave it the counter's three codes.
    $staff = Staff::factory()->role(SeedRole::IGI_BRANCH)->create();

    $this->withToken(staffBearer($staff))->getJson('/api/v1/dashboard/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles', ['igi_branch'])
        ->assertJsonPath('data.permissions', ['inspection.enter', 'order.handover', 'order.receive']);
});

it('returns 401 without a token', function () {
    $this->getJson('/api/v1/dashboard/auth/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    $this->getJson('/api/v1/dashboard/_probe')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('lets staff holding the permission through the gate', function (SeedRole $role) {
    $staff = Staff::factory()->role($role)->create();

    $this->withToken(staffBearer($staff))->getJson('/api/v1/dashboard/_probe')
        ->assertOk()
        ->assertJson(['ok' => true]);
})->with([SeedRole::CEO, SeedRole::COO]);

it('refuses staff without the permission with 403 permission_denied and audits it', function (SeedRole $role) {
    $staff = Staff::factory()->role($role)->create();

    $this->withToken(staffBearer($staff))->getJson('/api/v1/dashboard/_probe')
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied');

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_PERMISSION_DENIED->value)->sole();
    expect($row->actor_staff_id)->toBe($staff->staff_id)
        ->and($row->actor_customer_id)->toBeNull()
        ->and($row->after_json['permission'])->toBe('customer.suspend');
})->with([SeedRole::FINANCE, SeedRole::OPERATIONS, SeedRole::VERIFICATION, SeedRole::IGI_BRANCH]);

it('honours a permission granted directly and takes it away again', function () {
    $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    $token = staffBearer($staff);

    $this->withToken($token)->getJson('/api/v1/dashboard/_probe')->assertStatus(403);

    // The guard caches its user for the life of the app instance; production
    // handles one request per process, so reset it between in-test requests.
    $staff->givePermissionTo('customer.suspend');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/dashboard/_probe')->assertOk();

    $staff->revokePermissionTo('customer.suspend');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/dashboard/_probe')->assertStatus(403);
});

it('honours a role change on the next request', function () {
    $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    $token = staffBearer($staff);

    $staff->syncRoles(['coo']);

    $this->withToken($token)->getJson('/api/v1/dashboard/_probe')->assertOk();
});
