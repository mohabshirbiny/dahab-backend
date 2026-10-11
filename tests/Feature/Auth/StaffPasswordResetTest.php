<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffPassword;
use App\Notifications\StaffPasswordLinkNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

// Spec 020 US2: a staff member recovers a password through an emailed single-use link.

const SPW_NEW = 'staff-new-passphrase-55';
const SPW_REQUEST = '/api/v1/dashboard/auth/password/reset-request';
const SPW_CONFIRM = '/api/v1/dashboard/auth/password/reset-confirm';
const SPW_LOGIN = '/api/v1/dashboard/auth/login';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

function spwStaff(array $attributes = [], string $role = SeedRole::OPERATIONS->value): Staff
{
    return Staff::factory()->withRole($role)->withPassword('old-passphrase-123')->create($attributes);
}

/** The token of the link last mailed to `$email`. */
function spwToken(string $email): string
{
    $mine = null;
    Notification::assertSentOnDemand(StaffPasswordLinkNotification::class,
        function (StaffPasswordLinkNotification $n, array $channels, AnonymousNotifiable $to) use ($email, &$mine) {
            if (($to->routes['mail'] ?? null) !== $email) {
                return false;
            }
            $mine = $n;

            return true;
        });
    parse_str((string) parse_url($mine->link, PHP_URL_QUERY), $query);

    return $query['token'];
}

function spwConfirm($test, string $token, string $password = SPW_NEW, ?string $confirmation = null)
{
    return $test->postJson(SPW_CONFIRM, ['token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password]);
}

function spwRequest($test, string $email)
{
    return $test->postJson(SPW_REQUEST, ['email' => $email]);
}

it('answers the same for an unknown, disabled, system and active address, and mails only the active one', function () {
    $active = spwStaff();
    $disabled = spwStaff(['is_active' => false]);
    $system = Staff::query()->where('is_system', true)->firstOrFail();

    $bodies = [
        spwRequest($this, $active->email)->assertStatus(202)->json(),
        spwRequest($this, 'nobody@dahab.test')->assertStatus(202)->json(),
        spwRequest($this, $disabled->email)->assertStatus(202)->json(),
        spwRequest($this, $system->email)->assertStatus(202)->json(),
    ];

    expect(array_unique(array_map('json_encode', $bodies)))->toHaveCount(1);
    Notification::assertSentOnDemandTimes(StaffPasswordLinkNotification::class, 1);

    expect(spwToken($active->email))->not->toBeEmpty();
    $row = AuditLog::query()->where('action', AuditEvent::STAFF_PASSWORD_RESET_REQUESTED->value)->sole();
    expect($row->actor_staff_id)->toBe($active->staff_id);
});

it('sends the link to a frozen member too', function () {
    $staff = Staff::factory()->withRole(SeedRole::OPERATIONS->value)->withPassword('old-passphrase-123')->frozen()->create();

    spwRequest($this, $staff->email)->assertStatus(202);

    Notification::assertSentOnDemandTimes(StaffPasswordLinkNotification::class, 1);
});

it('validates the email format', function () {
    $this->postJson(SPW_REQUEST, [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->postJson(SPW_REQUEST, ['email' => 'not-an-email'])->assertStatus(422);
});

it('sets the password once, revokes old tokens and lets the member sign in', function () {
    $staff = spwStaff();
    $old = staffAccessToken($staff);
    $this->bearer($old)->getJson('/api/v1/dashboard/auth/me')->assertOk();

    spwRequest($this, $staff->email)->assertStatus(202);
    $token = spwToken($staff->email);

    spwConfirm($this, $token)->assertOk()->assertJsonPath('data.password_changed', true);

    expect(Hash::check(SPW_NEW, StaffPassword::query()->findOrFail($staff->staff_id)->password_hash))->toBeTrue();
    app('auth')->forgetGuards();
    $this->withToken($old)->getJson('/api/v1/dashboard/auth/me')->assertUnauthorized();

    $this->withoutToken()->postJson(SPW_LOGIN, ['email' => $staff->email, 'password' => 'old-passphrase-123'])->assertStatus(401);
    $this->withoutToken()->postJson(SPW_LOGIN, ['email' => $staff->email, 'password' => SPW_NEW])->assertOk()->assertJsonStructure(['data' => ['session']]);

    $done = AuditLog::query()->where('action', AuditEvent::STAFF_PASSWORD_RESET_COMPLETED->value)->sole();
    expect($done->actor_staff_id)->toBe($staff->staff_id)
        ->and($done->after_json['origin'])->toBe('recovery');
});

it('refuses a used link and answers the same code for unknown ones', function () {
    $staff = spwStaff();
    spwRequest($this, $staff->email)->assertStatus(202);
    $token = spwToken($staff->email);

    spwConfirm($this, $token)->assertOk();
    spwConfirm($this, $token, 'another-passphrase-66')->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
    spwConfirm($this, 'not-a-real-token-at-all-0123456789')->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
});

it('expires the link after the recovery lifetime', function () {
    $staff = spwStaff();
    spwRequest($this, $staff->email)->assertStatus(202);
    $token = spwToken($staff->email);

    $this->travel((int) config('dahab-auth.password_reset.staff_link.recovery_minutes') + 1)->minutes();

    spwConfirm($this, $token)->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
});

it('voids the older link when a newer one is asked for', function () {
    $staff = spwStaff();
    spwRequest($this, $staff->email)->assertStatus(202);
    $first = spwToken($staff->email);
    spwRequest($this, $staff->email)->assertStatus(202);
    $second = spwToken($staff->email);

    expect($second)->not->toBe($first);
    spwConfirm($this, $first)->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
    spwConfirm($this, $second)->assertOk();
});

it('leaves MFA alone: an enrolled member is still challenged, a founder still enrols', function () {
    $enrolled = Staff::factory()->role(SeedRole::FINANCE)->withPassword('old-passphrase-123')->withMfa()->create();
    spwRequest($this, $enrolled->email)->assertStatus(202);
    spwConfirm($this, spwToken($enrolled->email))->assertOk();

    expect(StaffPassword::query()->findOrFail($enrolled->staff_id)->force_reenroll_mfa_at)->toBeNull();
    $this->postJson(SPW_LOGIN, ['email' => $enrolled->email, 'password' => SPW_NEW])
        ->assertOk()->assertJsonPath('data.mfa_required', true);

    $founder = Staff::factory()->role(SeedRole::CEO)->founder()->withPassword('old-passphrase-123')->create();
    spwRequest($this, $founder->email)->assertStatus(202);
    spwConfirm($this, spwToken($founder->email))->assertOk();

    $this->postJson(SPW_LOGIN, ['email' => $founder->email, 'password' => SPW_NEW])
        ->assertOk()->assertJsonPath('data.mfa_enrollment_required', true);
});

it('refuses the link of a member disabled after it was sent', function () {
    $staff = spwStaff();
    spwRequest($this, $staff->email)->assertStatus(202);
    $token = spwToken($staff->email);

    $staff->forceFill(['is_active' => false])->save();

    spwConfirm($this, $token)->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
    expect(Hash::check('old-passphrase-123', StaffPassword::query()->findOrFail($staff->staff_id)->password_hash))->toBeTrue();
});

it('creates the password of a member who has none yet', function () {
    $staff = Staff::factory()->withRole(SeedRole::OPERATIONS->value)->create();
    expect(StaffPassword::query()->whereKey($staff->staff_id)->exists())->toBeFalse();

    spwRequest($this, $staff->email)->assertStatus(202);
    spwConfirm($this, spwToken($staff->email))->assertOk();

    expect(Hash::check(SPW_NEW, StaffPassword::query()->findOrFail($staff->staff_id)->password_hash))->toBeTrue();
    $this->postJson(SPW_LOGIN, ['email' => $staff->email, 'password' => SPW_NEW])->assertOk();
});

it('clears the sign-in lockout', function () {
    $staff = spwStaff();
    $key = md5('auth.staff.login'.'staff-login-id:'.mb_strtolower($staff->email));
    $window = (int) config('dahab-auth.rate_limits.staff_login.per_identity_window');
    for ($i = 0; $i < (int) config('dahab-auth.rate_limits.staff_login.per_identity_max'); $i++) {
        RateLimiter::hit($key, $window);
    }
    $this->postJson(SPW_LOGIN, ['email' => $staff->email, 'password' => SPW_NEW])->assertStatus(429);

    spwRequest($this, $staff->email)->assertStatus(202);
    spwConfirm($this, spwToken($staff->email))->assertOk();

    $this->postJson(SPW_LOGIN, ['email' => $staff->email, 'password' => SPW_NEW])->assertOk();
});

it('rejects a weak password and a mismatched confirmation without spending the link', function () {
    $staff = spwStaff();
    spwRequest($this, $staff->email)->assertStatus(202);
    $token = spwToken($staff->email);

    spwConfirm($this, $token, 'short')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    spwConfirm($this, $token, SPW_NEW, 'something-else-entirely-99')->assertStatus(422);

    spwConfirm($this, $token)->assertOk();
});

it('lets exactly one of two uses of the same link win', function () {
    $staff = spwStaff();
    spwRequest($this, $staff->email)->assertStatus(202);
    $token = spwToken($staff->email);

    $statuses = [
        spwConfirm($this, $token, 'first-passphrase-1111')->status(),
        spwConfirm($this, $token, 'second-passphrase-222')->status(),
    ];

    expect($statuses)->toBe([200, 422])
        ->and(DB::table('one_time_token')->where('actor_staff_id', $staff->staff_id)->whereNull('consumed_at')->count())->toBe(0);
    expect(Hash::check('first-passphrase-1111', StaffPassword::query()->findOrFail($staff->staff_id)->password_hash))->toBeTrue();
});

it('limits link requests per address', function () {
    $staff = spwStaff();
    for ($i = 0; $i < 3; $i++) {
        spwRequest($this, $staff->email)->assertStatus(202);
    }
    spwRequest($this, $staff->email)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});
