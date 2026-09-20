<?php

use App\Enums\AuditEvent;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffDeviceFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

const STAFF_PASSWORD = 'correct-horse-battery';
const STAFF_LOGIN_URL = '/api/v1/dashboard/auth/login';

function loginBody(Staff $staff, string $password = STAFF_PASSWORD): array
{
    return ['email' => $staff->email, 'password' => $password];
}

it('signs in a staff member with email and password and issues a session', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->withPassword(STAFF_PASSWORD)->create();

    $response = $this->postJson(STAFF_LOGIN_URL, loginBody($staff))
        ->assertOk()
        ->assertJsonPath('data.staff.id', $staff->staff_id)
        ->assertJsonPath('data.staff.role', 'operations')
        ->assertJsonPath('data.session.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['session' => ['access_token', 'refresh_token', 'family_id']]])
        ->assertJsonMissingPath('data.staff.password_hash');

    // The issued access token is a real staff credential.
    $this->bearer($response->json('data.session.access_token'))
        ->getJson('/api/v1/dashboard/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $staff->staff_id);

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN->value)->sole();
    expect($row->actor_staff_id)->toBe($staff->staff_id)
        ->and($row->actor_customer_id)->toBeNull()
        ->and($row->after_json['outcome'])->toBe('success');
});

it('matches the email case-insensitively', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->withPassword(STAFF_PASSWORD)->create(['email' => 'Ops.Person@dahab.test']);

    $this->postJson(STAFF_LOGIN_URL, ['email' => 'ops.person@DAHAB.test', 'password' => STAFF_PASSWORD])->assertOk();
});

it('records the device fingerprint on a successful sign-in', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->withPassword(STAFF_PASSWORD)->create();

    $this->postJson(STAFF_LOGIN_URL, loginBody($staff), ['X-Device-Id' => 'staff-laptop-0000000000000000'])->assertOk();

    expect(StaffDeviceFingerprint::query()->where('staff_id', $staff->staff_id)->count())->toBe(1);
});

it('refuses a wrong password with invalid_credentials, audits it and issues nothing', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->withPassword(STAFF_PASSWORD)->create();

    $this->postJson(STAFF_LOGIN_URL, loginBody($staff, 'not-the-password-1'))
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_credentials');

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN_FAILED->value)->sole();
    expect($row->actor_staff_id)->toBe($staff->staff_id)
        ->and($row->after_json['outcome'])->toBe('failure')
        ->and($row->after_json['reason'])->toBe('wrong_password');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('answers an unknown email exactly like a wrong password', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->withPassword(STAFF_PASSWORD)->create();

    $wrong = $this->postJson(STAFF_LOGIN_URL, loginBody($staff, 'not-the-password-1'));
    $unknown = $this->postJson(STAFF_LOGIN_URL, ['email' => 'nobody@dahab.test', 'password' => 'not-the-password-1']);

    $unknown->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    expect($unknown->json())->toBe($wrong->json());
});

it('refuses an inactive staff member with the same generic error and audits the reason', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->disabled()->withPassword(STAFF_PASSWORD)->create();

    $inactive = $this->postJson(STAFF_LOGIN_URL, loginBody($staff));
    $wrong = $this->postJson(STAFF_LOGIN_URL, loginBody($staff, 'not-the-password-1'));

    $inactive->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    expect($inactive->json())->toBe($wrong->json());

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN_FAILED->value)->orderBy('audit_id')->first();
    expect($row->actor_staff_id)->toBe($staff->staff_id)
        ->and($row->after_json['reason'])->toBe('inactive');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('refuses a frozen staff member with account_frozen and issues nothing', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->frozen()->withPassword(STAFF_PASSWORD)->create();

    $this->postJson(STAFF_LOGIN_URL, loginBody($staff))
        ->assertStatus(403)
        ->assertJsonPath('code', 'account_frozen');

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN_FAILED->value)->sole();
    expect($row->actor_staff_id)->toBe($staff->staff_id)
        ->and($row->after_json['reason'])->toBe('frozen');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('does not reveal a frozen account to someone without the password', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->frozen()->withPassword(STAFF_PASSWORD)->create();

    $this->postJson(STAFF_LOGIN_URL, loginBody($staff, 'not-the-password-1'))
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_credentials');
});

it('lets a staff member whose freeze was lifted sign in again', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->frozen()->withPassword(STAFF_PASSWORD)->create();
    $staff->activeFreeze()->update(['unfrozen_at' => now()]);

    $this->postJson(STAFF_LOGIN_URL, loginBody($staff))->assertOk();
});

it('validates the payload', function (array $body) {
    $this->postJson(STAFF_LOGIN_URL, $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
})->with([
    'no email' => [['password' => 'x']],
    'no password' => [['email' => 'a@dahab.test']],
    'malformed email' => [['email' => 'nope', 'password' => 'x']],
]);

it('locks the email out after five failures with account_locked', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->withPassword(STAFF_PASSWORD)->create();

    for ($i = 0; $i < config('dahab-auth.rate_limits.staff_login.per_identity_max'); $i++) {
        $this->postJson(STAFF_LOGIN_URL, loginBody($staff, 'not-the-password-1'))->assertStatus(401);
    }

    // Even the right password is refused while the identity bucket is exhausted.
    $this->postJson(STAFF_LOGIN_URL, loginBody($staff))
        ->assertStatus(429)
        ->assertJsonPath('code', 'account_locked')
        ->assertHeader('Retry-After');
});

it('answers an exhausted IP bucket with too_many_requests, not account_locked', function () {
    $max = config('dahab-auth.rate_limits.staff_login.per_ip_max');

    for ($i = 0; $i < $max; $i++) {
        $this->postJson(STAFF_LOGIN_URL, ['email' => "probe{$i}@dahab.test", 'password' => 'x'])->assertStatus(401);
    }

    $this->postJson(STAFF_LOGIN_URL, ['email' => 'probe-last@dahab.test', 'password' => 'x'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');
});

it('throttles the route with the dedicated auth.staff.login limiter', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'api.v1.dashboard.auth.login');

    expect($route->gatherMiddleware())->toContain('throttle:auth.staff.login')
        ->and($route->gatherMiddleware())->not->toContain('auth:sanctum');
});
