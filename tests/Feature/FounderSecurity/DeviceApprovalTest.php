<?php

use App\Actions\Auth\Staff\FounderDeviceGate;
use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\AuditLog;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Models\StaffDeviceFingerprint;
use App\Notifications\FounderSecurityNotification;
use App\Support\Auth\DeviceFingerprint;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

// Spec 021 Story 1 (T028): after password + MFA, a founder on a device not yet known is held
// until the other founder approves; the device polls and collects its session once.

const FDA_PASSWORD = 'founder-passphrase-1';
const FDA_SECRET = 'JBSWY3DPEHPK3PXP';
const FDA_RECOVERY = ['aaaaa-bbbbb', 'ccccc-ddddd', 'eeeee-fffff'];
const FDA_LOGIN = '/api/v1/dashboard/auth/login';
const FDA_VERIFY = '/api/v1/dashboard/auth/mfa/verify';
const FDA_ENROLL = '/api/v1/dashboard/auth/mfa/enroll';
const FDA_STATUS = '/api/v1/dashboard/auth/device-approval/status';
const FDA_LIST = '/api/v1/dashboard/founder/device-approvals';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(FDA_PASSWORD)->withMfa(FDA_SECRET, FDA_RECOVERY)
        ->withKnownDevice('coo-laptop')->create(['full_name' => 'Founder COO']);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->withPassword(FDA_PASSWORD)->withMfa(FDA_SECRET, FDA_RECOVERY)
        ->withKnownDevice('ceo-laptop')->create(['full_name' => 'Founder CEO']);
});

/** Password + TOTP from `$device`; returns the MFA verify response. */
function fdaSignIn($test, Staff $staff, ?string $device, array $mfa = []): TestResponse
{
    app('auth')->forgetGuards();
    Cache::forget('staff-mfa-last-timestep:'.$staff->staff_id);
    $headers = $device !== null ? deviceHeaders($device) : [];

    $ref = $test->postJson(FDA_LOGIN, ['email' => $staff->email, 'password' => FDA_PASSWORD], $headers)
        ->assertOk()->json('data.session_ref');

    return $test->postJson(FDA_VERIFY, ['session_ref' => $ref, ...($mfa ?: ['code' => app(Google2FA::class)->getCurrentOtp(FDA_SECRET)])], $headers);
}

function fdaPoll($test, string $ref, string $device): TestResponse
{
    app('auth')->forgetGuards();

    return $test->postJson(FDA_STATUS, ['approval_ref' => $ref], deviceHeaders($device));
}

function fdaDecide($test, Staff $actor, string $approvalId, string $decision, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($actor))
        ->postJson(FDA_LIST.'/'.$approvalId.'/'.$decision, $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

function fdaTokens(Staff $staff): int
{
    return DB::table('personal_access_tokens')->where('tokenable_id', $staff->staff_id)->count();
}

it('leaves a non-founder and a founder on a known device unchanged', function () {
    $finance = Staff::factory()->role(SeedRole::FINANCE)->withPassword(FDA_PASSWORD)->withMfa(FDA_SECRET, FDA_RECOVERY)->create();

    fdaSignIn($this, $finance, null)->assertOk()->assertJsonStructure(['data' => ['staff', 'session' => ['access_token']]]);
    fdaSignIn($this, $this->coo, 'coo-laptop')->assertOk()->assertJsonStructure(['data' => ['staff', 'session' => ['access_token']]]);

    expect(FounderDeviceApproval::query()->count())->toBe(0);
});

it('holds a founder on an unknown device after MFA: no token, a row, an audit and the founders told', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->assertOk();

    $held->assertJsonPath('data.device_approval_required', true)
        ->assertJsonPath('data.poll_interval_seconds', 5)
        ->assertJsonMissingPath('data.session')
        ->assertJsonMissingPath('data.staff');
    expect($held->json('data.approval_ref'))->toBeString()->toHaveLength(43)
        ->and(fdaTokens($this->coo))->toBe(0);

    $row = FounderDeviceApproval::query()->findOrFail($held->json('data.approval_id'));
    expect($row->staff_id)->toBe($this->coo->staff_id)
        ->and($row->device_fingerprint)->toBe(DeviceFingerprint::hash('coo-new-phone'))
        ->and($row->ref_hash)->toBe(FounderDeviceGate::refHash($held->json('data.approval_ref')))
        ->and($row->ref_hash)->not->toBe($held->json('data.approval_ref'))
        ->and($row->expires_at->diffInMinutes($row->requested_at, true))->toEqual(15.0)
        ->and($row->status())->toBe('pending');

    $audit = AuditLog::forEvent(AuditEvent::FOUNDER_DEVICE_APPROVAL_REQUESTED);
    expect($audit->actor_staff_id)->toBe($this->coo->staff_id)
        ->and(json_encode($audit->after_json))->not->toContain($held->json('data.approval_ref'))
        ->and(AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN->value)->count())->toBe(0);

    Notification::assertSentTo([$this->ceo, $this->coo], FounderSecurityNotification::class,
        fn (FounderSecurityNotification $n) => $n->facts['event'] === 'founder.device_approval_requested' && $n->link === '/dashboard/security');
});

it('refuses a founder without X-Device-Id with 422 and writes no row', function () {
    fdaSignIn($this, $this->coo, null)->assertStatus(422)->assertJsonPath('code', 'device_id_required');

    expect(FounderDeviceApproval::query()->count())->toBe(0)->and(fdaTokens($this->coo))->toBe(0);
});

it('polls pending, then collects the session once after approval, and the device becomes known', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');

    fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertOk()
        ->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.session');

    fdaDecide($this, $this->ceo, $held['approval_id'], 'approve', ['note' => 'Called her'])->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by.id', $this->ceo->staff_id)
        ->assertJsonPath('data.decision_note', 'Called her')
        ->assertJsonPath('data.can_decide', false)
        ->assertJsonMissingPath('data.approval_ref');

    $collected = fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.staff.id', $this->coo->staff_id);
    expect($collected->json('data.session.access_token'))->toBeString();

    // The session works.
    app('auth')->forgetGuards();
    $this->withToken($collected->json('data.session.access_token'))->getJson('/api/v1/dashboard/auth/me')->assertOk();

    // Single use.
    fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertStatus(401)->assertJsonPath('code', 'device_approval_invalid');

    expect($this->coo->isKnownDevice(DeviceFingerprint::hash('coo-new-phone')))->toBeTrue()
        ->and(FounderDeviceApproval::query()->find($held['approval_id'])->consumed_at)->not->toBeNull()
        ->and(AuditLog::forEvent(AuditEvent::FOUNDER_DEVICE_APPROVAL_COLLECTED)->actor_staff_id)->toBe($this->coo->staff_id)
        ->and(AuditLog::query()->where('action', AuditEvent::STAFF_SIGN_IN->value)->latest('audit_id')->first()->after_json['via'])->toBe('device_approval')
        ->and(AuditLog::forEvent(AuditEvent::FOUNDER_DEVICE_APPROVED)->reason)->toBe('Called her');

    // The next sign-in from that device goes straight through.
    fdaSignIn($this, $this->coo, 'coo-new-phone', ['recovery_code' => FDA_RECOVERY[0]])->assertOk()
        ->assertJsonStructure(['data' => ['session' => ['access_token']]]);
});

it('answers a refusal with 403 and mints nothing', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');

    fdaDecide($this, $this->ceo, $held['approval_id'], 'refuse', ['note' => 'Not her'])->assertOk()
        ->assertJsonPath('data.status', 'refused');

    fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertForbidden()->assertJsonPath('code', 'device_approval_refused');
    expect(fdaTokens($this->coo))->toBe(0)
        ->and(AuditLog::forEvent(AuditEvent::FOUNDER_DEVICE_REFUSED)->reason)->toBe('Not her');
});

it('answers an expired request, an unknown ref and another device alike with 401', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');

    fdaPoll($this, $held['approval_ref'], 'someone-else')->assertStatus(401)->assertJsonPath('code', 'device_approval_invalid');
    fdaPoll($this, 'not-a-real-ref', 'coo-new-phone')->assertStatus(401)->assertJsonPath('code', 'device_approval_invalid');

    $this->travel(16)->minutes();
    fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertStatus(401)->assertJsonPath('code', 'device_approval_invalid');
});

it('refuses to approve your own request, in the action and in the database', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');

    fdaDecide($this, $this->coo, $held['approval_id'], 'approve')->assertForbidden()->assertJsonPath('code', 'founder_self_action');

    $state = null;
    try {
        DB::transaction(fn () => DB::table('founder_device_approval')->where('approval_id', $held['approval_id'])
            ->update(['approved_by' => $this->coo->staff_id, 'approved_at' => now()]));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0];
    }
    expect($state)->toBe('23514');
});

it('refuses a second decision and a decision after 15 minutes with 409', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');
    fdaDecide($this, $this->ceo, $held['approval_id'], 'approve')->assertOk();
    fdaDecide($this, $this->ceo, $held['approval_id'], 'refuse')->assertStatus(409)->assertJsonPath('code', 'device_approval_not_pending');

    $late = fdaSignIn($this, $this->coo, 'coo-other-phone', ['recovery_code' => FDA_RECOVERY[1]])->json('data');
    $this->travel(16)->minutes();
    fdaDecide($this, $this->ceo, $late['approval_id'], 'approve')->assertStatus(409)->assertJsonPath('code', 'device_approval_not_pending');
});

it('ends an earlier held request from the same device when a new one starts', function () {
    $first = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');
    $second = fdaSignIn($this, $this->coo, 'coo-new-phone', ['recovery_code' => FDA_RECOVERY[0]])->json('data');

    expect(FounderDeviceApproval::query()->find($first['approval_id'])->status())->toBe('expired')
        ->and(FounderDeviceApproval::query()->find($second['approval_id'])->status())->toBe('pending');
    fdaPoll($this, $first['approval_ref'], 'coo-new-phone')->assertStatus(401);
    fdaDecide($this, $this->ceo, $first['approval_id'], 'approve')->assertStatus(409);
});

it('mints nothing when the founder was frozen or disabled while waiting', function (string $how) {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');
    fdaDecide($this, $this->ceo, $held['approval_id'], 'approve')->assertOk();

    if ($how === 'frozen') {
        AccountFreeze::query()->create(['frozen_staff_id' => $this->coo->staff_id, 'frozen_by' => $this->ceo->staff_id, 'reason' => 'Lost phone']);
        fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertForbidden()->assertJsonPath('code', 'account_frozen');
    } else {
        $this->coo->forceFill(['is_active' => false])->save();
        fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    expect(fdaTokens($this->coo))->toBe(0)
        ->and(StaffDeviceFingerprint::query()->where('fingerprint_hash', DeviceFingerprint::hash('coo-new-phone'))->exists())->toBeFalse();
})->with(['frozen', 'disabled']);

it('holds the enrollment path too, after storing the enrollment', function () {
    $fresh = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(FDA_PASSWORD)->create();

    $begin = $this->postJson(FDA_LOGIN, ['email' => $fresh->email, 'password' => FDA_PASSWORD], deviceHeaders('fresh-laptop'))
        ->assertOk()->json('data');
    parse_str((string) parse_url($begin['otpauth_url'], PHP_URL_QUERY), $query);

    $this->postJson(FDA_ENROLL, ['session_ref' => $begin['session_ref'], 'code' => app(Google2FA::class)->getCurrentOtp($query['secret'])], deviceHeaders('fresh-laptop'))
        ->assertOk()->assertJsonPath('data.device_approval_required', true)->assertJsonMissingPath('data.session');

    expect($fresh->mfa()->exists())->toBeTrue()->and(fdaTokens($fresh))->toBe(0);
});

it('holds a founder on the password-only path when MFA is off locally', function () {
    config(['dahab-auth.mfa_enforced' => false]);
    $plain = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(FDA_PASSWORD)->create();

    $this->postJson(FDA_LOGIN, ['email' => $plain->email, 'password' => FDA_PASSWORD], deviceHeaders('plain-laptop'))
        ->assertOk()->assertJsonPath('data.device_approval_required', true);
});

it('does not hold anyone when the hold is switched off', function () {
    config(['dahab-auth.founder_device_approval_enforced' => false]);

    fdaSignIn($this, $this->coo, 'coo-new-phone')->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);
    expect(FounderDeviceApproval::query()->count())->toBe(0);
});

it('throttles the poll per ref', function () {
    $held = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');

    for ($i = 0; $i < config('dahab-auth.rate_limits.staff_device_approval.per_ref_max'); $i++) {
        fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertOk();
    }

    fdaPoll($this, $held['approval_ref'], 'coo-new-phone')->assertStatus(429);
});

it('lists every founder\'s requests with filters and can_decide, founders only', function () {
    $coo = fdaSignIn($this, $this->coo, 'coo-new-phone')->json('data');
    $ceo = fdaSignIn($this, $this->ceo, 'ceo-new-phone')->json('data');
    FounderDeviceApproval::factory()->refused($this->ceo)->create(['staff_id' => $this->coo->staff_id]);

    app('auth')->forgetGuards();
    $page = $this->withToken(staffAccessToken($this->ceo))->getJson(FDA_LIST)->assertOk()
        ->assertJsonPath('meta.pending_for_me', 1)
        ->assertJsonPath('meta.total', 2);
    $rows = collect($page->json('data'))->keyBy('id');
    expect($rows[$coo['approval_id']]['can_decide'])->toBeTrue()
        ->and($rows[$ceo['approval_id']]['can_decide'])->toBeFalse()
        ->and($rows[$coo['approval_id']]['device_ref'])->toBe(substr(DeviceFingerprint::hash('coo-new-phone'), 0, 8))
        ->and(json_encode($page->json()))->not->toContain($coo['approval_ref'])
        ->and(json_encode($page->json()))->not->toContain('coo-new-phone');

    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->ceo))->getJson(FDA_LIST.'?status=refused')->assertOk()->assertJsonPath('meta.total', 1);
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->ceo))->getJson(FDA_LIST.'?status=all')->assertOk()->assertJsonPath('meta.total', 3);
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->ceo))->getJson(FDA_LIST.'?status=nope')->assertStatus(422);

    $operations = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($operations))->getJson(FDA_LIST)->assertForbidden()->assertJsonPath('code', 'founder_only');
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($operations))->getJson('/api/v1/dashboard/founder/devices')->assertForbidden();
});

it('lists each founder\'s known devices by ref', function () {
    app('auth')->forgetGuards();
    $devices = $this->withToken(staffAccessToken($this->ceo))->getJson('/api/v1/dashboard/founder/devices')->assertOk()->json('data');

    $byName = collect($devices)->keyBy('founder.full_name');
    expect($byName['Founder COO']['devices'][0]['device_ref'])->toBe(substr(DeviceFingerprint::hash('coo-laptop'), 0, 8))
        ->and(json_encode($devices))->not->toContain('coo-laptop');
});

it('keeps first_seen_at when a known device signs in again', function () {
    $device = StaffDeviceFingerprint::query()->where('staff_id', $this->coo->staff_id)->firstOrFail();
    $device->forceFill(['first_seen_at' => now()->subYear()])->save();

    fdaSignIn($this, $this->coo, 'coo-laptop')->assertOk();

    expect($device->fresh()->first_seen_at->lt(now()->subMonths(6)))->toBeTrue()
        ->and($device->fresh()->last_seen_at->gt(now()->subMinute()))->toBeTrue();
});
