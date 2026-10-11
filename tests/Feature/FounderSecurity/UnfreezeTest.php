<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\AccountFreeze;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use App\Notifications\FounderSecurityNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

// Spec 021 Story 2 (T044): two confirmations in order — slot 1 the frozen founder (tokenless)
// or a holder of founder.unfreeze_second, slot 2 always last, the founder who is not frozen.

const UFZ_PASSWORD = 'founder-passphrase-1';
const UFZ_SECRET = 'JBSWY3DPEHPK3PXP';
const UFZ_RECOVERY = ['aaaaa-bbbbb', 'ccccc-ddddd'];
const UFZ_NARROW = '/api/v1/dashboard/auth/frozen/confirm-unfreeze';
const UFZ_CONFIRM = '/api/v1/dashboard/founder/unfreeze';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->withPassword(UFZ_PASSWORD)->withMfa(UFZ_SECRET)
        ->withKnownDevice('ceo-laptop')->create(['full_name' => 'Founder CEO']);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(UFZ_PASSWORD)->withMfa(UFZ_SECRET, UFZ_RECOVERY)
        ->withKnownDevice('coo-laptop')->create(['full_name' => 'Founder COO']);
    $this->freeze = AccountFreeze::factory()->create(['frozen_staff_id' => $this->coo->staff_id, 'frozen_by' => $this->ceo->staff_id]);
});

function ufzNarrow($test, array $override = [], ?string $device = 'coo-laptop')
{
    app('auth')->forgetGuards();
    $body = array_merge([
        'email' => test()->coo->email,
        'password' => UFZ_PASSWORD,
        'code' => app(Google2FA::class)->getCurrentOtp(UFZ_SECRET),
        'note' => 'It was me, phone found',
    ], $override);

    return $test->postJson(UFZ_NARROW, array_filter($body, fn ($v) => $v !== null), $device !== null ? deviceHeaders($device) : []);
}

function ufzConfirm($test, Staff $actor, string $freezeId, ?string $note = null)
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($actor))
        ->postJson(UFZ_CONFIRM, ['freeze_id' => $freezeId, 'note' => $note], ['Idempotency-Key' => (string) Str::uuid()]);
}

/** A non-founder who holds founder.unfreeze_second through a role (the CEO names them). */
function ufzStandIn(): Staff
{
    $role = StaffRoleModel::query()->firstOrCreate(['name' => 'stand_in', 'guard_name' => Staff::GUARD], ['display_name' => 'Stand-in', 'requires_mfa' => false]);
    $role->givePermissionTo(StaffPermission::FOUNDER_UNFREEZE_SECOND->value);

    return Staff::factory()->withRole('stand_in')->create(['full_name' => 'Stand In']);
}

it('records the frozen founder\'s slot 1 without any token, then the other founder unfreezes', function () {
    ufzNarrow($this)->assertOk()
        ->assertJsonPath('data.freeze_id', $this->freeze->freeze_id)
        ->assertJsonPath('data.slot', 1)
        ->assertJsonPath('data.waiting_for', 'founder')
        ->assertJsonMissingPath('data.session');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $this->coo->staff_id)->count())->toBe(0);
    $fresh = $this->freeze->fresh();
    expect($fresh->unfreeze_confirm_1)->toBe($this->coo->staff_id)
        ->and($fresh->unfreeze_confirm_1_kind)->toBe('frozen_founder');

    ufzConfirm($this, $this->ceo, $this->freeze->freeze_id, 'Checked together')->assertOk()
        ->assertJsonPath('data.stage', 'unfrozen')
        ->assertJsonPath('data.confirmations.0.kind', 'frozen_founder')
        ->assertJsonPath('data.confirmations.1.slot', 2)
        ->assertJsonPath('data.confirmations.1.by.id', $this->ceo->staff_id);

    expect($this->freeze->fresh()->unfrozen_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditEvent::FOUNDER_UNFREEZE_CONFIRMED->value)->count())->toBe(2)
        ->and(AuditLog::forEvent(AuditEvent::FOUNDER_UNFROZEN)->actor_staff_id)->toBe($this->ceo->staff_id);

    Notification::assertSentTo($this->ceo, FounderSecurityNotification::class, fn ($n) => $n->facts['event'] === 'founder.unfrozen');
    Notification::assertSentTo($this->coo, FounderSecurityNotification::class, fn ($n) => $n->facts['event'] === 'founder.unfreeze_confirmed');
});

it('answers a wrong password like sign-in', function () {
    ufzNarrow($this, ['password' => 'not-the-password-1'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    ufzNarrow($this, ['email' => 'nobody@dahab.test'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

    expect(AuditLog::query()->where('action', AuditEvent::FOUNDER_UNFREEZE_CONFIRM_FAILED->value)
        ->where('actor_staff_id', $this->coo->staff_id)->value('after_json'))->toContain('wrong_password');
});

it('answers not_frozen for a founder with no open freeze', function () {
    liftFreeze($this->freeze);

    ufzNarrow($this)->assertStatus(409)->assertJsonPath('code', 'not_frozen');
});

it('needs a device already known for the founder', function () {
    ufzNarrow($this, [], null)->assertStatus(422)->assertJsonPath('code', 'device_id_required');
    ufzNarrow($this, [], 'stolen-laptop')->assertForbidden()->assertJsonPath('code', 'device_not_known');
    ufzNarrow($this, [], 'ceo-laptop')->assertForbidden()->assertJsonPath('code', 'device_not_known');

    expect($this->freeze->fresh()->unfreeze_confirm_1)->toBeNull();
});

it('refuses a wrong and a replayed authenticator code', function () {
    ufzNarrow($this, ['code' => '000000'])->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');

    Cache::put('staff-mfa-last-timestep:'.$this->coo->staff_id, app(Google2FA::class)->getTimestamp(), now()->addMinutes(5));
    ufzNarrow($this)->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');

    expect($this->freeze->fresh()->unfreeze_confirm_1)->toBeNull();
});

it('takes a recovery code, once', function () {
    ufzNarrow($this, ['code' => null, 'recovery_code' => UFZ_RECOVERY[0]])->assertOk();
    ufzNarrow($this, ['code' => null, 'recovery_code' => UFZ_RECOVERY[0]])->assertStatus(401)->assertJsonPath('code', 'mfa_invalid');
});

it('shares the sign-in limiter', function () {
    for ($i = 0; $i < config('dahab-auth.rate_limits.staff_login.per_identity_max'); $i++) {
        ufzNarrow($this, ['password' => 'not-the-password-1'])->assertStatus(401);
    }

    ufzNarrow($this)->assertStatus(429);
});

it('lets the named stand-in give slot 1 when the frozen founder cannot, then the founder unfreezes and sign-in works', function () {
    $standIn = ufzStandIn();

    ufzConfirm($this, $standIn, $this->freeze->freeze_id, 'Mona is abroad')->assertOk()
        ->assertJsonPath('data.stage', 'awaiting_founder')
        ->assertJsonPath('data.confirmations.0.kind', 'second_approver')
        ->assertJsonPath('data.confirmations.0.by.id', $standIn->staff_id);

    ufzConfirm($this, $this->ceo, $this->freeze->freeze_id)->assertOk()->assertJsonPath('data.stage', 'unfrozen');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $this->coo->staff_id)->count())->toBe(0);

    app('auth')->forgetGuards();
    Cache::forget('staff-mfa-last-timestep:'.$this->coo->staff_id);
    $ref = $this->postJson('/api/v1/dashboard/auth/login', ['email' => $this->coo->email, 'password' => UFZ_PASSWORD], deviceHeaders('coo-laptop'))
        ->assertOk()->json('data.session_ref');
    $this->postJson('/api/v1/dashboard/auth/mfa/verify', ['session_ref' => $ref, 'code' => app(Google2FA::class)->getCurrentOtp(UFZ_SECRET)], deviceHeaders('coo-laptop'))
        ->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);
});

it('keeps the founder last: before slot 1 is unfreeze_order', function () {
    ufzConfirm($this, $this->ceo, $this->freeze->freeze_id)->assertStatus(409)->assertJsonPath('code', 'unfreeze_order');
});

it('refuses slot 1 twice', function () {
    ufzNarrow($this)->assertOk();
    ufzConfirm($this, ufzStandIn(), $this->freeze->freeze_id)->assertStatus(409)->assertJsonPath('code', 'unfreeze_already_confirmed');

    Cache::forget('staff-mfa-last-timestep:'.$this->coo->staff_id);
    ufzNarrow($this, ['code' => null, 'recovery_code' => UFZ_RECOVERY[1]])->assertStatus(409)->assertJsonPath('code', 'unfreeze_already_confirmed');
});

it('never lets one person give both confirmations', function () {
    // The CEO holds founder.unfreeze_second (plan Q1) but is a founder: always slot 2.
    expect($this->ceo->can(StaffPermission::FOUNDER_UNFREEZE_SECOND->value))->toBeTrue();
    ufzConfirm($this, $this->ceo, $this->freeze->freeze_id)->assertStatus(409)->assertJsonPath('code', 'unfreeze_order');

    // A founder who somehow gave slot 1 (a direct write) cannot also give slot 2.
    DB::table('account_freeze')->where('freeze_id', $this->freeze->freeze_id)->update([
        'unfreeze_confirm_1' => $this->ceo->staff_id, 'unfreeze_confirm_1_at' => now(), 'unfreeze_confirm_1_kind' => 'second_approver',
    ]);
    ufzConfirm($this, $this->ceo, $this->freeze->freeze_id)->assertStatus(409)->assertJsonPath('code', 'unfreeze_already_confirmed');
});

it('refuses someone who is neither a founder nor a holder', function () {
    ufzConfirm($this, Staff::factory()->role(SeedRole::FINANCE)->create(), $this->freeze->freeze_id)
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('refuses a confirmation on a closed freeze', function () {
    liftFreeze($this->freeze);

    ufzConfirm($this, $this->ceo, $this->freeze->freeze_id)->assertStatus(409)->assertJsonPath('code', 'not_frozen');
    ufzConfirm($this, ufzStandIn(), $this->freeze->freeze_id)->assertStatus(409)->assertJsonPath('code', 'not_frozen');
});

it('lists the freezes with the viewer\'s slot', function () {
    $standIn = ufzStandIn();

    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($standIn))->getJson('/api/v1/dashboard/founder/freezes')->assertOk()
        ->assertJsonPath('data.0.id', $this->freeze->freeze_id)
        ->assertJsonPath('data.0.my_slot', 1)
        ->assertJsonPath('data.0.can_confirm', true);

    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->ceo))->getJson('/api/v1/dashboard/founder/freezes')->assertOk()
        ->assertJsonPath('data.0.my_slot', 2)
        ->assertJsonPath('data.0.can_confirm', false);

    liftFreeze($this->freeze);
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->ceo))->getJson('/api/v1/dashboard/founder/freezes')->assertOk()->assertJsonCount(0, 'data');
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->ceo))->getJson('/api/v1/dashboard/founder/freezes?state=all')->assertOk()
        ->assertJsonPath('data.0.stage', 'unfrozen');

    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken(Staff::factory()->role(SeedRole::FINANCE)->create()))->getJson('/api/v1/dashboard/founder/freezes')
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});
