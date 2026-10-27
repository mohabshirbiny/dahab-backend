<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\FounderDeviceApproval;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffDeviceFingerprint;
use App\Notifications\CustomerLoginOtpNotification;
use App\Notifications\FounderSecurityNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

// Settings page and relist: a staff member on a device not yet known confirms it with a code
// sent by SMS to the staff phone (setting auth.staff_new_device_sms); a founder's first device
// too, instead of founder:trust-device, and any later new founder device (with the setting off
// a founder waits for the other founder).

const SDC_PASSWORD = 'staff-passphrase-1';
const SDC_SECRET = 'JBSWY3DPEHPK3PXP';
const SDC_LOGIN = '/api/v1/dashboard/auth/login';
const SDC_MFA = '/api/v1/dashboard/auth/mfa/verify';
const SDC_VERIFY = '/api/v1/dashboard/auth/device-code/verify';
const SDC_RESEND = '/api/v1/dashboard/auth/device-code/resend';

beforeEach(function () {
    config(['dahab-auth.staff_device_sms_enforced' => true]);
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ops = Staff::factory()->role(SeedRole::OPERATIONS)->withPassword(SDC_PASSWORD)->create(['phone' => '+201012345678']);
});

function sdcLogin($test, Staff $staff, ?string $device = 'new-laptop'): TestResponse
{
    app('auth')->forgetGuards();

    return $test->postJson(SDC_LOGIN, ['email' => $staff->email, 'password' => SDC_PASSWORD], $device === null ? [] : deviceHeaders($device));
}

/** The code in the last SMS sent to `$phone`. */
function sdcLastCode(string $phone): string
{
    $code = null;
    Notification::assertSentOnDemand(CustomerLoginOtpNotification::class, function (CustomerLoginOtpNotification $n, array $channels, AnonymousNotifiable $to) use ($phone, &$code) {
        if (($to->routes['sms'] ?? null) !== $phone) {
            return false;
        }
        preg_match('/\b(\d{6})\b/', $n->toSms($to)->content, $m);
        $code = $m[1];

        return true;
    });

    return (string) $code;
}

it('sends a code to a staff member on a new device and signs in once it is typed', function () {
    $held = sdcLogin($this, $this->ops)->assertOk()
        ->assertJsonPath('data.device_code_required', true)
        ->assertJsonPath('data.phone_hint', '••• 678')
        ->assertJsonMissingPath('data.session');

    $code = sdcLastCode('+201012345678');
    expect(StaffDeviceFingerprint::query()->where('staff_id', $this->ops->staff_id)->count())->toBe(0);

    $this->postJson(SDC_VERIFY, ['challenge_id' => $held->json('data.challenge_id'), 'code' => $code], deviceHeaders('new-laptop'))
        ->assertOk()->assertJsonStructure(['data' => ['staff' => ['id'], 'session' => ['access_token']]]);

    expect(StaffDeviceFingerprint::query()->where('staff_id', $this->ops->staff_id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::STAFF_DEVICE_CODE_SENT->value)->count())->toBe(1);

    // The device is known now: the next sign-in goes straight through.
    sdcLogin($this, $this->ops)->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);
});

it('refuses a wrong code, voids the challenge after the last try, and binds it to the device', function () {
    // Three tries here: the route allows five requests per challenge in all.
    config(['dahab-auth.otp.max_verify_attempts' => 3]);
    $id = sdcLogin($this, $this->ops)->json('data.challenge_id');
    $code = sdcLastCode('+201012345678');
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->postJson(SDC_VERIFY, ['challenge_id' => $id, 'code' => $code], deviceHeaders('another-laptop'))
        ->assertStatus(401)->assertJsonPath('code', 'otp_invalid');

    foreach (range(1, 3) as $_) {
        $this->postJson(SDC_VERIFY, ['challenge_id' => $id, 'code' => $wrong], deviceHeaders('new-laptop'))
            ->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    }

    $this->postJson(SDC_VERIFY, ['challenge_id' => $id, 'code' => $code], deviceHeaders('new-laptop'))
        ->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    expect(AuditLog::query()->where('action', AuditEvent::STAFF_DEVICE_CODE_FAILED->value)->count())->toBe(3);
});

it('resends a fresh code only after the cooldown', function () {
    $id = sdcLogin($this, $this->ops)->json('data.challenge_id');

    $this->postJson(SDC_RESEND, ['challenge_id' => $id], deviceHeaders('new-laptop'))
        ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    $this->travel((int) config('dahab-auth.otp.send_cooldown_seconds') + 1)->seconds();

    $this->postJson(SDC_RESEND, ['challenge_id' => $id], deviceHeaders('new-laptop'))
        ->assertOk()->assertJsonPath('data.device_code_required', true);
    Notification::assertSentOnDemandTimes(CustomerLoginOtpNotification::class, 2);
});

it('asks nothing when the setting is off or the staff member has no phone', function () {
    Setting::query()->whereKey('auth.staff_new_device_sms')->update(['value_bool' => false]);
    sdcLogin($this, $this->ops)->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);

    Setting::query()->whereKey('auth.staff_new_device_sms')->update(['value_bool' => true]);
    $noPhone = Staff::factory()->role(SeedRole::OPERATIONS)->withPassword(SDC_PASSWORD)->create(['phone' => null]);
    sdcLogin($this, $noPhone)->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);
    Notification::assertNothingSent();
});

it('needs the device id to send a code', function () {
    sdcLogin($this, $this->ops, null)->assertStatus(422)->assertJsonPath('code', 'device_id_required');
});

it('confirms a founder first device by SMS after MFA, and tells the founders', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(SDC_PASSWORD)->withMfa(SDC_SECRET, ['aaaaa-bbbbb'])
        ->create(['phone' => '+201099999111']);
    Staff::factory()->role(SeedRole::CEO)->founder()->withPassword(SDC_PASSWORD)->withKnownDevice('ceo-laptop')->create();

    $ref = sdcLogin($this, $coo, 'coo-first')->assertOk()->json('data.session_ref');
    Cache::forget('staff-mfa-last-timestep:'.$coo->staff_id);
    $held = $this->postJson(SDC_MFA, ['session_ref' => $ref, 'code' => app(Google2FA::class)->getCurrentOtp(SDC_SECRET)], deviceHeaders('coo-first'))
        ->assertOk()->assertJsonPath('data.device_code_required', true);
    expect(FounderDeviceApproval::query()->count())->toBe(0);

    $this->postJson(SDC_VERIFY, ['challenge_id' => $held->json('data.challenge_id'), 'code' => sdcLastCode('+201099999111')], deviceHeaders('coo-first'))
        ->assertOk()->assertJsonStructure(['data' => ['session' => ['access_token']]]);

    expect(AuditLog::query()->where('action', AuditEvent::FOUNDER_DEVICE_TRUSTED_BY_SMS->value)->count())->toBe(1);
    Notification::assertSentTo(Staff::query()->where('is_founder', true)->get(), FounderSecurityNotification::class);
});

it('confirms a later new founder device by SMS too, not by the other founder', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(SDC_PASSWORD)->withMfa(SDC_SECRET, ['aaaaa-bbbbb'])
        ->withKnownDevice('coo-laptop')->create(['phone' => '+201099999111']);

    $ref = sdcLogin($this, $coo, 'coo-new-phone')->json('data.session_ref');
    Cache::forget('staff-mfa-last-timestep:'.$coo->staff_id);
    $this->postJson(SDC_MFA, ['session_ref' => $ref, 'code' => app(Google2FA::class)->getCurrentOtp(SDC_SECRET)], deviceHeaders('coo-new-phone'))
        ->assertOk()->assertJsonPath('data.device_code_required', true);
    expect(FounderDeviceApproval::query()->count())->toBe(0);
});

it('holds a new founder device for the other founder when the setting is off', function () {
    Setting::query()->whereKey('auth.staff_new_device_sms')->update(['value_bool' => false]);
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(SDC_PASSWORD)->withMfa(SDC_SECRET, ['aaaaa-bbbbb'])
        ->withKnownDevice('coo-laptop')->create(['phone' => '+201099999111']);

    $ref = sdcLogin($this, $coo, 'coo-new-phone')->json('data.session_ref');
    Cache::forget('staff-mfa-last-timestep:'.$coo->staff_id);
    $this->postJson(SDC_MFA, ['session_ref' => $ref, 'code' => app(Google2FA::class)->getCurrentOtp(SDC_SECRET)], deviceHeaders('coo-new-phone'))
        ->assertOk()->assertJsonPath('data.device_approval_required', true);
    Notification::assertNotSentTo(new AnonymousNotifiable, CustomerLoginOtpNotification::class);
});
