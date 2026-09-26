<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

const DASHBOARD_ENDPOINTS = [
    'GET dashboard me' => ['GET', '/api/v1/dashboard/auth/me'],
    'POST dashboard refresh' => ['POST', '/api/v1/dashboard/auth/refresh'],
];

const CUSTOMER_ENDPOINTS = [
    'GET customer me' => ['GET', '/api/v1/customer/auth/me'],
    'POST customer logout' => ['POST', '/api/v1/customer/auth/logout'],
    'POST customer logout-all' => ['POST', '/api/v1/customer/auth/logout-all'],
    'POST customer refresh' => ['POST', '/api/v1/customer/auth/refresh'],
];

it('runs customers and staff on separate Sanctum guards over their own providers', function () {
    expect(config('auth.guards.customer'))->toBe(['driver' => 'sanctum', 'provider' => 'customers'])
        ->and(config('auth.guards.staff'))->toBe(['driver' => 'sanctum', 'provider' => 'staff'])
        ->and(config('auth.providers.customers.model'))->toBe(Customer::class)
        ->and(config('auth.providers.staff.model'))->toBe(Staff::class);
});

it('answers 401 unauthenticated on every protected endpoint without a token', function (string $method, string $path) {
    $this->json($method, $path)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
})->with(array_merge(CUSTOMER_ENDPOINTS, DASHBOARD_ENDPOINTS));

it('rejects a customer token on the dashboard: 401, for access and refresh tokens alike', function (string $method, string $path) {
    $session = app(IssueTokenFamilyAction::class)->forCustomer(Customer::factory()->verified()->create());

    foreach ([$session->accessToken, $session->refreshToken] as $token) {
        $this->bearer($token)->json($method, $path)
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    // Refused at authentication: nothing was rotated or revoked on the way.
    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(2)
        ->and(DB::table('personal_access_tokens')->whereNotNull('rotated_at')->count())->toBe(0);
})->with(DASHBOARD_ENDPOINTS);

it('rejects a staff token on the customer API: 401, for access and refresh tokens alike', function (string $method, string $path) {
    $session = app(IssueTokenFamilyAction::class)->forStaff(Staff::factory()->role(SeedRole::CEO)->create());

    foreach ([$session->accessToken, $session->refreshToken] as $token) {
        $this->bearer($token)->json($method, $path)
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(2)
        ->and(DB::table('personal_access_tokens')->whereNotNull('rotated_at')->count())->toBe(0);
})->with(CUSTOMER_ENDPOINTS);

it('does not confuse a staff and a customer that share the same id', function () {
    $staff = Staff::factory()->role(SeedRole::CEO)->create();
    $customer = Customer::factory()->create(['customer_id' => $staff->staff_id]);
    $issue = app(IssueTokenFamilyAction::class);

    $this->bearer($issue->forStaff($staff)->accessToken)->getJson('/api/v1/customer/auth/me')->assertStatus(401);
    $this->bearer($issue->forCustomer($customer)->accessToken)->getJson('/api/v1/dashboard/auth/me')->assertStatus(401);
});

it('no longer serves the generic /auth/* routes or /user', function (string $method, string $path) {
    $this->json($method, $path)->assertStatus(404)->assertJsonPath('code', 'not_found');
})->with([
    ['POST', '/api/v1/auth/register'],
    ['POST', '/api/v1/auth/login'],
    ['GET', '/api/v1/auth/me'],
    ['POST', '/api/v1/auth/logout'],
    ['POST', '/api/v1/auth/logout-all'],
    ['GET', '/api/v1/user'],
]);

it('names customer routes api.v1.customer.auth.* and has dropped the old names', function () {
    foreach (['register.start', 'register.verify-phone-otp', 'register.email', 'register.verify-email-otp', 'register.documents', 'register.submit', 'register.complete', 'login', 'refresh', 'me', 'logout', 'logout-all'] as $name) {
        expect(Route::has("api.v1.customer.auth.{$name}"))->toBeTrue();
    }

    expect(Route::has('api.v1.dashboard.auth.me'))->toBeTrue()
        ->and(Route::has('api.v1.dashboard.auth.refresh'))->toBeTrue()
        ->and(Route::has('api.v1.auth.customer.login'))->toBeFalse()
        ->and(Route::has('api.v1.user'))->toBeFalse();
});

it('guards every customer and dashboard route with the right guard and ability, and never auth:sanctum', function () {
    // Public entry points: they hold no token yet. Staff MFA steps are credentialed by the
    // single-use `session_ref` from login instead (see StaffMfaTest).
    $public = [
        'api/v1/customer/auth/register/start',
        'api/v1/customer/auth/register/verify-phone-otp',
        'api/v1/customer/auth/register/email',
        'api/v1/customer/auth/register/verify-email-otp',
        'api/v1/customer/auth/register/documents',
        'api/v1/customer/auth/register/submit',
        'api/v1/customer/auth/register/complete',
        'api/v1/customer/auth/login',
        // New-device sign-in: credentialed by the challenge_id from login, not a token.
        'api/v1/customer/auth/otp/verify',
        'api/v1/customer/auth/otp/resend',
        'api/v1/dashboard/auth/login',
        'api/v1/dashboard/auth/mfa/verify',
        'api/v1/dashboard/auth/mfa/enroll',
    ];

    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_filter($route->gatherMiddleware(), 'is_string');
        $uri = $route->uri();

        expect($middleware)->not->toContain('auth:sanctum');

        if (str_starts_with($uri, 'api/v1/customer/')) {
            expect($middleware)->not->toContain('auth:staff');

            if (! in_array($uri, $public, true)) {
                expect($middleware)->toContain('auth:customer');
                expect(collect($middleware)->contains(fn ($m) => in_array($m, ['abilities:customer:access', 'abilities:customer:refresh'], true)))
                    ->toBeTrue("{$uri} must require a customer ability");
                $checked++;
            }
        }

        if (str_starts_with($uri, 'api/v1/dashboard/') && ! in_array($uri, $public, true)) {
            expect($middleware)->toContain('auth:staff')
                ->and($middleware)->not->toContain('auth:customer');
            expect(collect($middleware)->contains(fn ($m) => in_array($m, ['abilities:staff:access', 'abilities:staff:refresh'], true)))
                ->toBeTrue("{$uri} must require a staff ability");
            $checked++;
        }
    }

    // customer: refresh, me, logout, logout-all, me/uploads, me/identity-documents (6)
    // dashboard: refresh, me, logout, logout-all, identity-documents index/show/image/review (8), customers index/show (2),
    //            permissions index, roles index/store/show/update/destroy, staff index/show/roles (9, spec 002)
    expect($checked)->toBe(25);
});

it('keeps refresh routes on the refresh ability and access routes on the access ability', function () {
    $abilityOf = fn (string $name) => collect(Route::getRoutes()->getByName($name)->gatherMiddleware())
        ->first(fn ($m) => is_string($m) && str_starts_with($m, 'abilities:'));

    expect($abilityOf('api.v1.customer.auth.refresh'))->toBe('abilities:customer:refresh')
        ->and($abilityOf('api.v1.customer.auth.me'))->toBe('abilities:customer:access')
        ->and($abilityOf('api.v1.customer.auth.logout'))->toBe('abilities:customer:access')
        ->and($abilityOf('api.v1.customer.auth.logout-all'))->toBe('abilities:customer:access')
        ->and($abilityOf('api.v1.dashboard.auth.refresh'))->toBe('abilities:staff:refresh')
        ->and($abilityOf('api.v1.dashboard.auth.me'))->toBe('abilities:staff:access');
});
