<?php

use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Enums\PauseTrigger;
use App\Enums\WithdrawalState;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\CustomerTrustedDevice;
use App\Models\WithdrawalPause;
use App\Notifications\AccountNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Account;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 020 US1: a customer who forgot the password asks for an SMS code and sets a new one.

const PWR_NEW = 'brand-new-passphrase-77';
const PWR_REQUEST = '/api/v1/customer/auth/password/reset-request';
const PWR_CONFIRM = '/api/v1/customer/auth/password/reset-confirm';

beforeEach(function () {
    Notification::fake();
    Cache::flush();
});

function pwrRequest($test, string $phone, string $device = 'dev-1')
{
    return $test->withHeaders(['X-Device-Id' => $device])->postJson(PWR_REQUEST, ['phone' => $phone]);
}

/** The code last texted to `$phone` (Notification::fake()). */
function pwrCode(string $phone): string
{
    $mine = null;
    Notification::assertSentOnDemand(PasswordResetCodeNotification::class,
        function (PasswordResetCodeNotification $n, array $channels, AnonymousNotifiable $to) use ($phone, &$mine) {
            if (($to->routes['sms'] ?? null) !== $phone) {
                return false;
            }
            $mine = $n;

            return true;
        });
    preg_match('/\b(\d{6})\b/', $mine->toSms(new AnonymousNotifiable)->content, $m);

    return $m[1];
}

function pwrConfirm($test, string $phone, string $code, string $password = PWR_NEW, ?string $confirmation = null)
{
    return $test->postJson(PWR_CONFIRM, [
        'phone' => $phone,
        'code' => $code,
        'password' => $password,
        'password_confirmation' => $confirmation ?? $password,
    ]);
}

function pwrCanSignIn($test, Customer $customer, string $password, string $device = 'dev-1')
{
    app('auth')->forgetGuards();

    return $test->withoutToken()->withHeaders(['X-Device-Id' => $device])
        ->postJson('/api/v1/customer/auth/login', ['phone' => $customer->phone, 'password' => $password]);
}

it('answers the same for a registered, unregistered, closed and cooling-down phone', function () {
    $real = Account::customer();
    $closed = Account::customer(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished']);

    $bodies = [
        pwrRequest($this, $real->phone)->assertStatus(202)->json('data'),
        pwrRequest($this, '+201099998888')->assertStatus(202)->json('data'),
        pwrRequest($this, $closed->phone)->assertStatus(202)->json('data'),
        // A second request for the same number while the first code is fresh.
        pwrRequest($this, $real->phone)->assertStatus(202)->json('data'),
    ];

    foreach ($bodies as $body) {
        expect(array_keys($body))->toBe(['expires_in_seconds', 'resend_available_at'])
            ->and($body['expires_in_seconds'])->toBe((int) config('dahab-auth.otp.ttl_seconds'));
    }
    expect(array_unique(array_map(fn ($b) => $b['expires_in_seconds'], $bodies)))->toHaveCount(1);
});

it('texts a code only to an account that exists and is not closed', function () {
    $real = Account::customer();
    $closed = Account::customer(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished']);

    pwrRequest($this, $real->phone)->assertStatus(202);
    pwrRequest($this, '+201099998888')->assertStatus(202);
    pwrRequest($this, $closed->phone)->assertStatus(202);

    Notification::assertSentOnDemandTimes(PasswordResetCodeNotification::class, 1);
    expect(pwrCode($real->phone))->toMatch('/^\d{6}$/');

    $row = AuditLog::query()->where('action', AuditEvent::CUSTOMER_PASSWORD_RESET_REQUESTED->value)->sole();
    expect($row->actor_customer_id)->toBe($real->customer_id)
        ->and(json_encode($row->after_json))->not->toContain(pwrCode($real->phone));
});

it('does not send a second code inside the cooldown, and replaces the code after it', function () {
    $customer = Account::customer();

    pwrRequest($this, $customer->phone)->assertStatus(202);
    pwrRequest($this, $customer->phone)->assertStatus(202);
    Notification::assertSentOnDemandTimes(PasswordResetCodeNotification::class, 1);

    $first = pwrCode($customer->phone);
    $this->travel((int) config('dahab-auth.otp.send_cooldown_seconds') + 1)->seconds();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    Notification::assertSentOnDemandTimes(PasswordResetCodeNotification::class, 2);

    // The new code replaces the old one: the first is refused if it differs.
    $second = pwrCode($customer->phone);
    if ($second !== $first) {
        pwrConfirm($this, $customer->phone, $first)->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    }
    pwrConfirm($this, $customer->phone, $second)->assertOk();
});

it('limits requests per phone, per device and per IP', function () {
    // Per phone: 3 an hour.
    $customer = Account::customer();
    for ($i = 0; $i < 3; $i++) {
        pwrRequest($this, $customer->phone, "dev-phone-{$i}")->assertStatus(202);
    }
    pwrRequest($this, $customer->phone, 'dev-phone-9')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    // Per device: 10 an hour across different numbers.
    RateLimiter::clear('x');
    Cache::flush();
    for ($i = 0; $i < 10; $i++) {
        pwrRequest($this, '+2010000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'one-device')->assertStatus(202);
    }
    pwrRequest($this, '+201000000099', 'one-device')->assertStatus(429);
    // Another device is unaffected until the IP bucket (20) is spent.
    pwrRequest($this, '+201000000098', 'other-device')->assertStatus(202);
});

it('validates the phone format without revealing the account', function () {
    $this->postJson(PWR_REQUEST, [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->postJson(PWR_REQUEST, ['phone' => '01000000001'])->assertStatus(422);
});

it('resets the password, ends sessions and devices, stops withdrawals and tells the customer', function () {
    $withdrawal = Withdrawals::requested($this, '42000', '56760');
    $customer = Withdrawals::customer($withdrawal);
    $customer->password()->create(['password_hash' => bcrypt(Account::PASSWORD), 'password_changed_at' => now()]);
    $customer->forceFill(['email' => 'mona.h@email.com'])->save();

    $here = Account::signIn($this, $customer, 'phone-1');
    $there = Account::signIn($this, $customer, 'laptop-1', 'web');

    // The sign-in lockout is cleared by a successful reset.
    $window = (int) config('dahab-auth.rate_limits.customer_login.per_identity_window');
    RateLimiter::hit(md5('auth.customer.login'.'cust-login-id:'.mb_strtolower($customer->phone)), $window);

    $families = DB::table('personal_access_tokens')->where('tokenable_id', $customer->customer_id)
        ->whereNotNull('family_id')->distinct()->count('family_id');
    expect($families)->toBeGreaterThanOrEqual(2);

    pwrRequest($this, $customer->phone, 'phone-1')->assertStatus(202);
    $code = pwrCode($customer->phone);

    $res = pwrConfirm($this, $customer->phone, $code)->assertOk()
        ->assertJsonPath('data.password_changed', true)
        ->assertJsonPath('data.signed_out_sessions', $families)
        ->assertJsonPath('data.cancelled_withdrawals', 1);
    expect($res->json('data.withdrawals_paused_until'))->not->toBeNull();

    // Hash changed; the old password is dead, the new one works (from a forgotten device: a code is asked).
    expect(Hash::check(PWR_NEW, CustomerPassword::query()->findOrFail($customer->customer_id)->password_hash))->toBeTrue();
    pwrCanSignIn($this, $customer, Account::PASSWORD)->assertStatus(401);
    pwrCanSignIn($this, $customer, PWR_NEW, 'phone-1')->assertOk()->assertJsonPath('data.otp_required', true);

    // Every session is gone, every device forgotten.
    Account::as($this, $here['access'], 'phone-1')->getJson(Account::ME.'/sessions')->assertUnauthorized();
    Account::as($this, $there['access'], 'laptop-1', 'web')->getJson(Account::ME.'/sessions')->assertUnauthorized();
    expect(CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)->count())->toBe(0);

    // The pause and the cancelled withdrawal, with a balanced ledger entry.
    $pause = WithdrawalPause::query()->where('customer_id', $customer->customer_id)->latest('opened_at')->firstOrFail();
    expect($pause->trigger_kind)->toBe(PauseTrigger::PASSWORD_RESET)
        ->and($pause->triggered_by_account)->toBeNull()
        ->and($withdrawal->fresh()->state)->toBe(WithdrawalState::CANCELLED)
        ->and(Withdrawals::lines($withdrawal->fresh()->return_txn_id))->not->toBeEmpty();

    // Told on every channel (SMS + email + inbox come from the customer's own routes).
    Notification::assertSentTo($customer, AccountNotification::class,
        fn (AccountNotification $n) => $n->event === AccountEvent::PASSWORD_RESET && $n->cancelledWithdrawals === 1 && $n->pauseUntil !== null);

    $audit = AuditLog::query()->where('action', AuditEvent::CUSTOMER_PASSWORD_RESET_COMPLETED->value)->sole();
    expect($audit->actor_customer_id)->toBe($customer->customer_id)
        ->and($audit->after_json['sessions_ended'])->toBe($families)
        ->and($audit->after_json['cancelled_withdrawals'])->toHaveCount(1);

    // No body, audit row or notice carries the code or a hash.
    expect($res->getContent())->not->toContain($code)->not->toContain('$2y$')->not->toContain('password_hash')
        ->and(json_encode($audit->after_json))->not->toContain($code);
});

it('counts wrong codes and voids the code at five', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    $code = pwrCode($customer->phone);
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < (int) config('dahab-auth.otp.max_verify_attempts'); $i++) {
        pwrConfirm($this, $customer->phone, $wrong)->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    }

    // Voided: even the right code is now refused, and the password is unchanged.
    pwrConfirm($this, $customer->phone, $code)->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    pwrCanSignIn($this, $customer, Account::PASSWORD)->assertSuccessful();
});

it('refuses an expired code', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    $code = pwrCode($customer->phone);

    $this->travel((int) config('dahab-auth.otp.ttl_seconds') + 5)->seconds();

    pwrConfirm($this, $customer->phone, $code)->assertStatus(401)->assertJsonPath('code', 'otp_expired');
});

it('spends the code once: a second use is refused', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    $code = pwrCode($customer->phone);

    pwrConfirm($this, $customer->phone, $code)->assertOk();
    pwrConfirm($this, $customer->phone, $code, 'another-passphrase-88')->assertStatus(401)->assertJsonPath('code', 'otp_invalid');

    expect(Hash::check(PWR_NEW, CustomerPassword::query()->findOrFail($customer->customer_id)->password_hash))->toBeTrue();
});

it('answers otp_invalid for an unknown phone, a closed account and a number never asked', function () {
    $closed = Account::customer(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished']);
    $real = Account::customer();

    pwrConfirm($this, '+201099998888', '123456')->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    pwrConfirm($this, $closed->phone, '123456')->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
    pwrConfirm($this, $real->phone, '123456')->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
});

it('refuses a code whose account was closed after it was sent', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    $code = pwrCode($customer->phone);

    $customer->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished'])->save();

    pwrConfirm($this, $customer->phone, $code)->assertStatus(401)->assertJsonPath('code', 'otp_invalid');
});

it('lets a pending and a suspended customer reset', function (string $state) {
    $customer = $state === 'pending'
        ? Account::customer(verified: false)
        : Customer::factory()->withPassword(Account::PASSWORD)->suspended(byStaffId: SystemActor::id())->create();

    pwrRequest($this, $customer->phone)->assertStatus(202);
    pwrConfirm($this, $customer->phone, pwrCode($customer->phone))->assertOk();
})->with(['pending', 'suspended']);

it('rejects a weak password, a mismatched confirmation and a malformed code', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    $code = pwrCode($customer->phone);

    pwrConfirm($this, $customer->phone, $code, 'short')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    pwrConfirm($this, $customer->phone, $code, PWR_NEW, 'different-passphrase-99')->assertStatus(422);
    pwrConfirm($this, $customer->phone, '12ab56')->assertStatus(422);
    pwrConfirm($this, $customer->phone, '12345')->assertStatus(422);

    // None of the refusals spent the code.
    pwrConfirm($this, $customer->phone, $code)->assertOk();
});

it('keeps a customer with no withdrawals free of any pause error', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);

    $this->postJson(PWR_CONFIRM, [
        'phone' => $customer->phone, 'code' => pwrCode($customer->phone),
        'password' => PWR_NEW, 'password_confirmation' => PWR_NEW,
    ])->assertOk()->assertJsonPath('data.cancelled_withdrawals', 0);
});

it('lets exactly one of two uses of the same code win', function () {
    $customer = Account::customer();
    pwrRequest($this, $customer->phone)->assertStatus(202);
    $code = pwrCode($customer->phone);

    $codes = [
        pwrConfirm($this, $customer->phone, $code, 'first-passphrase-111')->status(),
        pwrConfirm($this, $customer->phone, $code, 'second-passphrase-222')->status(),
    ];

    expect($codes)->toBe([200, 401]);
    expect(Hash::check('first-passphrase-111', CustomerPassword::query()->findOrFail($customer->customer_id)->password_hash))->toBeTrue();
});
