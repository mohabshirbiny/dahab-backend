<?php

use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\CustomerTrustedDevice;
use App\Notifications\AccountNotification;
use App\Notifications\CustomerLoginOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Account;

uses(RefreshDatabase::class);

// Spec 017 US3, FR-012, FR-020, FR-021: the password, the devices signed in,
// and the alert on a sign-in from a new device.

beforeEach(function () {
    Notification::fake();
});

function acc017Login($test, $customer, string $password, string $device = 'device-z', string $platform = 'ios')
{
    app('auth')->forgetGuards();

    return $test->withoutToken()->withHeaders(['X-Device-Id' => $device, 'X-Device-Platform' => $platform])
        ->postJson('/api/v1/customer/auth/login', ['phone' => $customer->phone, 'password' => $password]);
}

it('changes the password with the current one and signs every other session out', function () {
    $customer = Account::customer();
    $here = Account::signIn($this, $customer, 'phone-1');
    $there = Account::signIn($this, $customer, 'laptop-1', 'web');

    Account::post($this, $here['access'], '/password', [
        'current_password' => Account::PASSWORD, 'password' => 'a-brand-new-secret-9', 'password_confirmation' => 'a-brand-new-secret-9',
    ], deviceId: 'phone-1')->assertOk()->assertJsonPath('data.signed_out_sessions', 1);

    Account::as($this, $there['access'], 'laptop-1', 'web')->getJson(Account::ME.'/sessions')->assertUnauthorized();
    Account::as($this, $here['access'], 'phone-1')->getJson(Account::ME.'/sessions')->assertOk();
    expect(CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)->count())->toBe(2);
    acc017Login($this, $customer, Account::PASSWORD, 'phone-1')->assertUnauthorized();
    acc017Login($this, $customer, 'a-brand-new-secret-9', 'phone-1')->assertOk();

    Notification::assertSentTo($customer, AccountNotification::class, fn ($n) => $n->event === AccountEvent::PASSWORD_CHANGED);
    expect(AuditLog::query()->where('action', AuditEvent::CUSTOMER_PASSWORD_CHANGED->value)->where('after_json->outcome', 'success')->count())->toBe(1);
});

it('refuses a wrong current password, the same password and a weak one', function () {
    $customer = Account::customer();
    $s = Account::signIn($this, $customer);

    Account::post($this, $s['access'], '/password', ['current_password' => 'nope-nope-nope', 'password' => 'a-brand-new-secret-9', 'password_confirmation' => 'a-brand-new-secret-9'])
        ->assertUnprocessable()->assertJsonPath('code', 'current_password_wrong');
    Account::post($this, $s['access'], '/password', ['current_password' => Account::PASSWORD, 'password' => Account::PASSWORD, 'password_confirmation' => Account::PASSWORD])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    Account::post($this, $s['access'], '/password', ['current_password' => Account::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');

    expect(AuditLog::query()->where('action', AuditEvent::CUSTOMER_PASSWORD_CHANGED->value)->where('after_json->outcome', 'failure')->count())->toBe(1);
    Notification::assertNotSentTo($customer, AccountNotification::class);
});

it('lists the open sessions with the current one marked', function () {
    $customer = Account::customer();
    $here = Account::signIn($this, $customer, 'phone-1');
    Account::signIn($this, $customer, 'laptop-1', 'web');

    $rows = Account::as($this, $here['access'], 'phone-1')->getJson(Account::ME.'/sessions')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->where('is_current', true)->pluck('session_id')->all())->toBe([$here['family']])
        ->and(collect($rows)->pluck('platform')->sort()->values()->all())->toBe(['ios', 'web'])
        ->and(collect($rows)->every(fn ($r) => $r['device_known']))->toBeTrue();
});

it('signs another device out at once and forgets it, never the current one', function () {
    $customer = Account::customer();
    $other = Account::customer();
    $here = Account::signIn($this, $customer, 'phone-1');
    $there = Account::signIn($this, $customer, 'laptop-1', 'web');
    $foreign = Account::signIn($this, $other, 'phone-9');

    Account::post($this, $here['access'], "/sessions/{$here['family']}/sign-out", deviceId: 'phone-1')
        ->assertUnprocessable()->assertJsonPath('code', 'current_session');
    Account::post($this, $here['access'], "/sessions/{$foreign['family']}/sign-out", deviceId: 'phone-1')->assertNotFound();

    Account::post($this, $here['access'], "/sessions/{$there['family']}/sign-out", deviceId: 'phone-1')
        ->assertOk()->assertJsonPath('data.signed_out_sessions', 1)->assertJsonPath('data.device_forgotten', true);

    Account::as($this, $there['access'], 'laptop-1', 'web')->getJson(Account::ME.'/sessions')->assertUnauthorized();
    expect(CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)->pluck('fingerprint_hash')->all())
        ->toBe([Account::fingerprint('phone-1')]);

    // The forgotten device needs a code again.
    app('auth')->forgetGuards();
    $this->withoutToken()->withHeaders(['X-Device-Id' => 'laptop-1', 'X-Device-Platform' => 'web'])
        ->postJson('/api/v1/customer/auth/login', ['phone' => $customer->phone, 'password' => Account::PASSWORD])
        ->assertOk()->assertJsonStructure(['data' => ['challenge_id']]);
    expect(AuditLog::query()->where('action', AuditEvent::CUSTOMER_SESSION_SIGNED_OUT->value)->count())->toBe(1);
});

it('keeps the device of a session through a refresh', function () {
    $customer = Account::customer();
    Account::trustDevice($customer, 'phone-1');
    app('auth')->forgetGuards();
    $session = $this->withHeaders(['X-Device-Id' => 'phone-1', 'X-Device-Platform' => 'ios'])
        ->postJson('/api/v1/customer/auth/login', ['phone' => $customer->phone, 'password' => Account::PASSWORD])->json('data.session');

    app('auth')->forgetGuards();
    $this->withToken($session['refresh_token'])->postJson('/api/v1/customer/auth/refresh')->assertOk();

    $devices = DB::table('personal_access_tokens')->where('family_id', $session['family_id'])->pluck('device_fingerprint_hash')->unique()->all();
    expect($devices)->toBe([Account::fingerprint('phone-1')]);
});

it('alerts the customer on every channel after a sign-in from a new device', function () {
    $customer = Account::customer(['email' => 'me@example.com']);
    $device = ['X-Device-Id' => 'brand-new', 'X-Device-Platform' => 'android'];

    $challenge = acc017Login($this, $customer, Account::PASSWORD, 'brand-new', 'android')->assertOk()->json('data.challenge_id');
    Notification::assertNotSentTo($customer, AccountNotification::class);

    $code = null;
    Notification::assertSentOnDemand(CustomerLoginOtpNotification::class, function ($n) use (&$code) {
        preg_match('/(\d{6})/', $n->toSms(null)->content, $m);
        $code = $m[1];

        return true;
    });
    app('auth')->forgetGuards();
    $this->withoutToken()->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code], $device)->assertOk();

    Notification::assertSentTo($customer, AccountNotification::class, fn ($n, $channels) => $n->event === AccountEvent::NEW_DEVICE
        && $channels === ['inbox', 'mail', 'sms'] && $n->detail === 'android');
    expect(CustomerTrustedDevice::query()->where('fingerprint_hash', Account::fingerprint('brand-new', 'android'))->value('platform'))->toBe('android');
});
