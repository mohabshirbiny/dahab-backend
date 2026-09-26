<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffMfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const MFA_PASSWORD = 'correct-horse-battery';
const MFA_SECRET = 'JBSWY3DPEHPK3PXP';
const MFA_RECOVERY = ['aaaaa-bbbbb', 'ccccc-ddddd', 'eeeee-fffff'];

function totp(string $secret = MFA_SECRET): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

function wrongTotp(string $secret = MFA_SECRET): string
{
    $good = totp($secret);

    // Guaranteed to differ from every code inside the ±1 step window.
    return collect(['000000', '111111', '222222', '333333'])->first(fn ($c) => $c !== $good
        && ! app(Google2FA::class)->verifyKey($secret, $c));
}

function enrolledStaff(SeedRole $role = SeedRole::CEO): Staff
{
    return Staff::factory()->role($role)->withPassword(MFA_PASSWORD)->withMfa(MFA_SECRET, MFA_RECOVERY)->create();
}

function unenrolledStaff(SeedRole $role = SeedRole::CEO): Staff
{
    return Staff::factory()->role($role)->withPassword(MFA_PASSWORD)->create();
}

function mfaLogin($test, Staff $staff)
{
    return $test->postJson('/api/v1/dashboard/auth/login', ['email' => $staff->email, 'password' => MFA_PASSWORD]);
}

function beginEnrollment($test, Staff $staff): array
{
    return mfaLogin($test, $staff)->assertOk()->assertJsonPath('data.mfa_enrollment_required', true)->json('data');
}

function otpSecretFrom(string $otpauthUrl): string
{
    parse_str(parse_url($otpauthUrl, PHP_URL_QUERY), $query);

    return $query['secret'];
}

// --- login gate ------------------------------------------------------------

it('holds back the session and starts enrollment for ceo, coo and finance without a TOTP', function (SeedRole $role) {
    $staff = unenrolledStaff($role);

    $data = beginEnrollment($this, $staff);

    expect($data['otpauth_url'])->toStartWith('otpauth://totp/')->toContain(rawurlencode($staff->email))
        ->and($data['recovery_codes'])->toHaveCount(8)
        ->and($data['session_ref'])->toBeString()
        ->and($data)->not->toHaveKey('session');
    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(StaffMfa::query()->count())->toBe(0);
})->with([SeedRole::CEO, SeedRole::COO, SeedRole::FINANCE]);

it('does not demand MFA from a role that does not require it and has not enrolled', function (SeedRole $role) {
    $staff = unenrolledStaff($role);

    mfaLogin($this, $staff)->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);
})->with([SeedRole::OPERATIONS, SeedRole::VERIFICATION, SeedRole::IGI_BRANCH]);

it('challenges an enrolled account instead of issuing a session', function () {
    $staff = enrolledStaff();

    mfaLogin($this, $staff)
        ->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonStructure(['data' => ['session_ref', 'expires_at']])
        ->assertJsonMissingPath('data.session')
        ->assertJsonMissingPath('data.mfa_enrollment_required');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('challenges a role that does not require MFA once the member enrolled voluntarily', function () {
    mfaLogin($this, enrolledStaff(SeedRole::OPERATIONS))->assertOk()->assertJsonPath('data.mfa_required', true);
});

it('demands re-enrollment when a reset flagged force_reenroll_mfa_at after the last enrollment', function () {
    $staff = enrolledStaff();
    $staff->password->update(['force_reenroll_mfa_at' => now()->addMinute()]);

    mfaLogin($this, $staff)->assertOk()->assertJsonPath('data.mfa_enrollment_required', true);
});

// --- enroll ----------------------------------------------------------------

it('enrolls with a valid code, stores the secret and recovery codes protected, and issues the session', function () {
    $staff = unenrolledStaff();
    $data = beginEnrollment($this, $staff);
    $secret = otpSecretFrom($data['otpauth_url']);

    $response = $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $data['session_ref'], 'code' => totp($secret)])
        ->assertOk()
        ->assertJsonPath('data.staff.id', $staff->staff_id)
        ->assertJsonPath('data.staff.mfa_enrolled', true)
        ->assertJsonPath('data.recovery_codes', $data['recovery_codes'])
        ->assertJsonStructure(['data' => ['session' => ['access_token', 'refresh_token']]]);

    $row = DB::table('staff_mfa')->where('staff_id', $staff->staff_id)->first();
    expect($row->mfa_secret_encrypted)->not->toBe($secret)->not->toContain($secret);
    expect(StaffMfa::query()->find($staff->staff_id)->mfa_secret_encrypted)->toBe($secret);
    foreach ($data['recovery_codes'] as $plain) {
        expect($row->recovery_codes_hash)->not->toContain($plain);
    }
    expect(json_decode($row->recovery_codes_hash, true))->toHaveCount(8);

    $this->bearer($response->json('data.session.access_token'))->getJson('/api/v1/dashboard/auth/me')->assertOk();

    expect(AuditLog::query()->where('action', AuditEvent::STAFF_MFA_ENROLLED->value)->sole()->actor_staff_id)->toBe($staff->staff_id);
    expect(AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN->value)->sole()->after_json['via'])->toBe('mfa_enrollment');
});

it('never writes the TOTP secret or a recovery code to the audit log', function () {
    $staff = unenrolledStaff();
    $data = beginEnrollment($this, $staff);
    $secret = otpSecretFrom($data['otpauth_url']);
    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $data['session_ref'], 'code' => totp($secret)])->assertOk();

    $dump = json_encode(AuditLog::query()->get()->toArray());

    expect($dump)->not->toContain($secret);
    foreach ($data['recovery_codes'] as $plain) {
        expect($dump)->not->toContain($plain);
    }
});

it('refuses a wrong enrollment code, persists nothing, and keeps the session usable for a retry', function () {
    $staff = unenrolledStaff();
    $data = beginEnrollment($this, $staff);
    $secret = otpSecretFrom($data['otpauth_url']);

    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $data['session_ref'], 'code' => wrongTotp($secret)])
        ->assertStatus(401)
        ->assertJsonPath('code', 'mfa_invalid');

    expect(StaffMfa::query()->count())->toBe(0)->and(DB::table('personal_access_tokens')->count())->toBe(0);
    $failure = AuditLog::query()->where('action', AuditEvent::STAFF_MFA_FAILED->value)->sole();
    expect($failure->actor_staff_id)->toBe($staff->staff_id)->and($failure->after_json['stage'])->toBe('enrollment');

    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $data['session_ref'], 'code' => totp($secret)])->assertOk();
});

it('consumes an enrollment session exactly once', function () {
    $data = beginEnrollment($this, unenrolledStaff());
    $body = ['session_ref' => $data['session_ref'], 'code' => totp(otpSecretFrom($data['otpauth_url']))];

    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', $body)->assertOk();
    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', $body)->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
});

it('replaces the previous enrollment on a forced re-enrollment and clears the flag', function () {
    $staff = enrolledStaff();
    $staff->password->update(['force_reenroll_mfa_at' => now()->addMinute()]);
    $data = beginEnrollment($this, $staff);
    $newSecret = otpSecretFrom($data['otpauth_url']);

    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $data['session_ref'], 'code' => totp($newSecret)])->assertOk();

    expect(StaffMfa::query()->find($staff->staff_id)->mfa_secret_encrypted)->toBe($newSecret);
    expect($staff->password()->first()->force_reenroll_mfa_at)->toBeNull();
    mfaLogin($this, $staff)->assertJsonPath('data.mfa_required', true);
});

it('refuses to enroll once the account was disabled after the password step', function () {
    $staff = unenrolledStaff();
    $data = beginEnrollment($this, $staff);
    $staff->update(['is_active' => false]);

    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $data['session_ref'], 'code' => totp(otpSecretFrom($data['otpauth_url']))])
        ->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
    expect(StaffMfa::query()->count())->toBe(0);
});

// --- verify ----------------------------------------------------------------

it('completes a challenged sign-in with a valid TOTP and issues the session', function () {
    $staff = enrolledStaff();
    $ref = mfaLogin($this, $staff)->json('data.session_ref');

    $response = $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => totp()])
        ->assertOk()
        ->assertJsonPath('data.staff.id', $staff->staff_id)
        ->assertJsonPath('data.staff.role', 'ceo')
        ->assertJsonStructure(['data' => ['session' => ['access_token', 'refresh_token', 'family_id']]]);

    $this->bearer($response->json('data.session.access_token'))->getJson('/api/v1/dashboard/auth/me')->assertOk();

    $verified = AuditLog::query()->where('action', AuditEvent::STAFF_MFA_VERIFIED->value)->sole();
    expect($verified->actor_staff_id)->toBe($staff->staff_id)->and($verified->after_json['method'])->toBe('totp');
    expect(AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN->value)->sole()->after_json['via'])->toBe('mfa');
});

it('refuses a wrong TOTP with mfa_invalid, audits it and issues nothing', function () {
    $staff = enrolledStaff();
    $ref = mfaLogin($this, $staff)->json('data.session_ref');

    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => wrongTotp()])
        ->assertStatus(401)
        ->assertJsonPath('code', 'mfa_invalid');

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_MFA_FAILED->value)->sole();
    expect($row->actor_staff_id)->toBe($staff->staff_id)->and($row->after_json['outcome'])->toBe('failure');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('never accepts the same TOTP twice', function () {
    $staff = enrolledStaff();
    $code = totp();

    $ref = mfaLogin($this, $staff)->json('data.session_ref');
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => $code])->assertOk();

    $ref2 = mfaLogin($this, $staff)->json('data.session_ref');
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref2, 'code' => $code])
        ->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
});

it('consumes a challenge session exactly once', function () {
    $ref = mfaLogin($this, enrolledStaff())->json('data.session_ref');
    $code = totp();

    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => $code])->assertOk();
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => $code])->assertStatus(401);
});

it('refuses an unknown or expired session_ref', function () {
    $ref = mfaLogin($this, enrolledStaff())->json('data.session_ref');

    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => (string) Str::uuid(), 'code' => totp()])
        ->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');

    $this->travel(config('dahab-auth.mfa.session_ttl_seconds') + 1)->seconds();
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => totp()])
        ->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
});

it('keeps enrollment and challenge sessions apart', function () {
    $enrollment = beginEnrollment($this, unenrolledStaff());
    $challenge = mfaLogin($this, enrolledStaff(SeedRole::FINANCE))->json('data.session_ref');

    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $enrollment['session_ref'], 'code' => totp(otpSecretFrom($enrollment['otpauth_url']))])
        ->assertStatus(401);
    $this->postJson('/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => $challenge, 'code' => totp()])
        ->assertStatus(401);
});

it('refuses to finish a sign-in once the account was disabled or frozen after the password step', function (string $how) {
    $staff = enrolledStaff();
    $ref = mfaLogin($this, $staff)->json('data.session_ref');

    if ($how === 'disabled') {
        $staff->update(['is_active' => false]);
    } else {
        Staff::factory()->role(SeedRole::CEO)->frozen()->create(); // unrelated freeze must not matter
        AccountFreeze::query()->create([
            'frozen_staff_id' => $staff->staff_id,
            'frozen_by' => Staff::factory()->role(SeedRole::COO)->create()->staff_id,
            'frozen_at' => now(),
        ]);
    }

    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => totp()])
        ->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->with(['disabled', 'frozen']);

it('accepts a recovery code once and only once', function () {
    $staff = enrolledStaff();

    $ref = mfaLogin($this, $staff)->json('data.session_ref');
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'recovery_code' => strtoupper(MFA_RECOVERY[0])])
        ->assertOk();

    expect(StaffMfa::query()->find($staff->staff_id)->recovery_codes_hash)->toHaveCount(2);
    expect(AuditLog::query()->where('action', AuditEvent::STAFF_MFA_VERIFIED->value)->sole()->after_json['method'])->toBe('recovery_code');

    $ref2 = mfaLogin($this, $staff)->json('data.session_ref');
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref2, 'recovery_code' => MFA_RECOVERY[0]])
        ->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref2, 'recovery_code' => MFA_RECOVERY[1]])
        ->assertOk();
});

it('throttles guessing per session_ref: the sixth attempt is 429 even with the right code', function () {
    $ref = mfaLogin($this, enrolledStaff())->json('data.session_ref');
    $max = config('dahab-auth.rate_limits.staff_mfa.per_session_max');

    for ($i = 0; $i < $max; $i++) {
        $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => wrongTotp()])->assertStatus(401);
    }

    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => totp()])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('validates the payloads', function (string $path, array $body) {
    $this->postJson($path, $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
})->with([
    'verify: no session_ref' => ['/api/v1/dashboard/auth/mfa/verify', ['code' => '123456']],
    'verify: session_ref not a uuid' => ['/api/v1/dashboard/auth/mfa/verify', ['session_ref' => 'abc', 'code' => '123456']],
    'verify: neither code nor recovery_code' => ['/api/v1/dashboard/auth/mfa/verify', ['session_ref' => 'a3f1c2d4-1111-4222-8333-444455556666']],
    'verify: code not six digits' => ['/api/v1/dashboard/auth/mfa/verify', ['session_ref' => 'a3f1c2d4-1111-4222-8333-444455556666', 'code' => '12ab56']],
    'enroll: no code' => ['/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => 'a3f1c2d4-1111-4222-8333-444455556666']],
    'enroll: code too short' => ['/api/v1/dashboard/auth/mfa/enroll', ['session_ref' => 'a3f1c2d4-1111-4222-8333-444455556666', 'code' => '123']],
]);

it('puts the MFA routes on their own limiter and outside auth:sanctum', function (string $name) {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === $name);

    expect($route->gatherMiddleware())->toContain('throttle:auth.staff.mfa')->not->toContain('auth:sanctum');
})->with(['api.v1.dashboard.auth.mfa.verify', 'api.v1.dashboard.auth.mfa.enroll']);

it('does not let a customer token or a staff token skip the MFA step by calling login-protected routes', function () {
    // MFA-required staff have no token until verify/enroll succeeds: a session_ref is not a bearer.
    $ref = mfaLogin($this, enrolledStaff())->json('data.session_ref');

    $this->bearer($ref)->getJson('/api/v1/dashboard/auth/me')->assertStatus(401);
});

it('keeps the MFA limiter separate from the staff login limiter', function () {
    $staff = unenrolledStaff(SeedRole::OPERATIONS);
    $max = config('dahab-auth.rate_limits.staff_mfa.per_session_max');
    $ref = (string) Str::uuid();

    for ($i = 0; $i < $max; $i++) {
        $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => '000000'])->assertStatus(401);
    }
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => '000000'])->assertStatus(429);

    // Login has seen none of it: same email, full budget.
    mfaLogin($this, $staff)->assertOk();
});
