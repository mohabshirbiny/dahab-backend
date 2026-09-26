<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Traits\HasRoles;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    Route::middleware(['api', 'auth:staff', 'abilities:staff:access', 'staff.permission:customer.suspend'])
        ->get('/api/v1/dashboard/_probe', fn () => response()->json(['ok' => true]));
});

it('keeps Spatie roles off the Customer model', function () {
    expect(class_uses_recursive(Customer::class))->not->toContain(HasRoles::class)
        ->and(method_exists(Customer::class, 'assignRole'))->toBeFalse()
        ->and(method_exists(Customer::class, 'givePermissionTo'))->toBeFalse();
});

it('does not let a customer token reach the dashboard', function (string $path) {
    $customer = Customer::factory()->verified()->create();
    $token = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->withToken($token)->getJson($path)
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect(AuditLog::count())->toBe(0);
})->with(['/api/v1/dashboard/auth/me', '/api/v1/dashboard/_probe']);

it('never grants a customer a dashboard permission through the gate', function () {
    $customer = Customer::factory()->verified()->create();

    expect($customer->can('customer.suspend'))->toBeFalse();
});

it('does not reuse a staff identity for a customer with the same id', function () {
    $staff = Staff::factory()->role(SeedRole::CEO)->create();
    $customer = Customer::factory()->create(['customer_id' => $staff->staff_id]);
    $token = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->withToken($token)->getJson('/api/v1/dashboard/_probe')->assertStatus(401);
});

it('leaves customer authentication working with dashboard authorization installed', function () {
    $device = ['X-Device-Id' => str_repeat('a', 32)];

    $register = registerCustomer(['phone' => '+201000009999'], $device)->assertCreated();

    $this->withToken($register->json('data.session.access_token'))->getJson('/api/v1/customer/auth/me')
        ->assertOk()
        ->assertJsonPath('data.phone', '+201000009999')
        ->assertJsonMissingPath('data.roles')
        ->assertJsonMissingPath('data.permissions');

    $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000009999',
        'password' => 'correct-horse-battery',
    ], $device)->assertOk()->assertJsonPath('data.customer.phone', '+201000009999');
});
