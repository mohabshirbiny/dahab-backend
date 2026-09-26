<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\CustomerTrustedDevice;
use App\Notifications\CustomerLoginOtpNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Sign-in from a new device (Part 1 §2.3, tasks T081–T084): the session is
 * held until the SMS code is verified, then the device is trusted.
 */

// DatabaseTransactions, not RefreshDatabase: phpunit.xml points at the same
// Postgres database as local development, and RefreshDatabase would run
// migrate:fresh on it. Each test here rolls back instead.
uses(DatabaseTransactions::class);

const NDO_PHONE = '+201000002222';
const NDO_PASSWORD = 'correct-horse-battery';

beforeEach(function () {
    Notification::fake();

    $customer = Customer::factory()->create(['phone' => NDO_PHONE]);
    CustomerPassword::query()->create([
        'customer_id' => $customer->customer_id,
        'password_hash' => Hash::make(NDO_PASSWORD),
        'password_changed_at' => now(),
    ]);
    $this->customer = $customer;
    $this->device = ['X-Device-Id' => str_repeat('a', 32), 'X-Device-Platform' => 'web'];
});

function ndoLogin(array $headers)
{
    return test()->postJson('/api/v1/customer/auth/login', ['phone' => NDO_PHONE, 'password' => NDO_PASSWORD], $headers);
}

/** The code carried by the n-th (1-based) sign-in SMS sent so far. */
function ndoSentCode(int $nth = 1): string
{
    $codes = [];
    Notification::assertSentOnDemand(CustomerLoginOtpNotification::class, function ($n) use (&$codes) {
        $codes[] = (new ReflectionProperty($n, 'code'))->getValue($n);

        return true;
    });

    return $codes[$nth - 1];
}

it('answers a new device with an OTP challenge and no session (T081)', function () {
    $response = ndoLogin($this->device);

    $response->assertOk()
        ->assertJsonPath('data.otp_required', true)
        ->assertJsonPath('data.otp_channel', 'sms')
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at', 'resend_available_at']])
        ->assertJsonMissingPath('data.session');

    Notification::assertSentOnDemandTimes(CustomerLoginOtpNotification::class, 1);
    expect(CustomerTrustedDevice::query()->count())->toBe(0);
});

it('issues the session, trusts the device, and skips the challenge next time (T082)', function () {
    $challenge = ndoLogin($this->device)->json('data.challenge_id');

    $this->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => ndoSentCode()], $this->device)
        ->assertOk()
        ->assertJsonPath('data.customer.phone', NDO_PHONE)
        ->assertJsonStructure(['data' => ['session' => ['access_token', 'refresh_token', 'family_id']]]);

    expect(CustomerTrustedDevice::query()
        ->where('customer_id', $this->customer->customer_id)
        ->where('fingerprint_hash', hash('sha256', str_repeat('a', 32).'|web'))
        ->exists())->toBeTrue();

    ndoLogin($this->device)
        ->assertOk()
        ->assertJsonStructure(['data' => ['session' => ['access_token']]])
        ->assertJsonMissingPath('data.otp_required');

    Notification::assertSentOnDemandTimes(CustomerLoginOtpNotification::class, 1);
});

it('refuses a wrong code, then voids the challenge after the attempt limit (T083)', function () {
    $challenge = ndoLogin($this->device)->json('data.challenge_id');
    $code = ndoSentCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    $max = (int) config('dahab-auth.otp.max_verify_attempts');

    for ($i = 0; $i < $max; $i++) {
        $this->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $wrong], $this->device)
            ->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    }

    // Voided: even the right code no longer works (or the limiter stops it).
    $response = $this->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code], $this->device);
    expect($response->status())->toBeIn([401, 429]);
    expect(CustomerTrustedDevice::query()->count())->toBe(0);
});

it('returns otp_expired once the code has lapsed (T083)', function () {
    $challenge = ndoLogin($this->device)->json('data.challenge_id');
    $code = ndoSentCode();

    Carbon::setTestNow(now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds') + 60));

    $this->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code], $this->device)
        ->assertStatus(401)->assertJsonPath('code', 'otp_expired');

    Carbon::setTestNow();
});

it('only accepts the code from the device that opened the challenge', function () {
    $challenge = ndoLogin($this->device)->json('data.challenge_id');

    $this->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => ndoSentCode()], ['X-Device-Id' => str_repeat('b', 32)])
        ->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
});

it('rate-limits resend: cooldown first, then a fresh code that works (T084)', function () {
    $challenge = ndoLogin($this->device)->json('data.challenge_id');

    $this->postJson('/api/v1/customer/auth/otp/resend', ['challenge_id' => $challenge], $this->device)
        ->assertStatus(429)->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');

    Carbon::setTestNow(now()->addSeconds((int) config('dahab-auth.otp.send_cooldown_seconds') + 1));

    $this->postJson('/api/v1/customer/auth/otp/resend', ['challenge_id' => $challenge], $this->device)
        ->assertOk()->assertJsonPath('data.challenge_id', $challenge);

    Notification::assertSentOnDemandTimes(CustomerLoginOtpNotification::class, 2);

    $this->postJson('/api/v1/customer/auth/otp/verify', ['challenge_id' => $challenge, 'code' => ndoSentCode(2)], $this->device)
        ->assertOk();

    Carbon::setTestNow();
});

it('caps resends per challenge at max_sends_per_hour (T084)', function () {
    $challenge = ndoLogin($this->device)->json('data.challenge_id');
    $cooldown = (int) config('dahab-auth.otp.send_cooldown_seconds') + 1;
    $max = (int) config('dahab-auth.otp.max_sends_per_hour');

    for ($i = 1; $i < $max; $i++) {
        Carbon::setTestNow(now()->addSeconds($cooldown));
        $this->postJson('/api/v1/customer/auth/otp/resend', ['challenge_id' => $challenge], $this->device)->assertOk();
    }

    Carbon::setTestNow(now()->addSeconds($cooldown));
    $this->postJson('/api/v1/customer/auth/otp/resend', ['challenge_id' => $challenge], $this->device)
        ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    Carbon::setTestNow();
});

it('refuses a sign-in without X-Device-Id with invalid_credentials', function () {
    ndoLogin([])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    Notification::assertNothingSent();
});

it('lets a pending applicant sign in from a new device (spec 002: verification does not gate sign-in)', function () {
    $this->customer->forceFill(['status' => CustomerStatus::PENDING_VERIFICATION, 'is_verified' => false])->save();

    ndoLogin($this->device)->assertOk()->assertJsonPath('data.otp_required', true);
});
