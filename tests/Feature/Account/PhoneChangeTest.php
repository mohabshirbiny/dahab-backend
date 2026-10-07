<?php

use App\Enums\AccountEvent;
use App\Enums\AuditEvent;
use App\Enums\PauseTrigger;
use App\Enums\WithdrawalState;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerTrustedDevice;
use App\Models\WithdrawalPause;
use App\Notifications\AccountNotification;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\PhoneChangeCodeNotification;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Account;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 017 US1, FR-001, FR-002, FR-013, FR-014: change the phone number with a
// code sent to the new number; the old number is told; withdrawals stop.

beforeEach(function () {
    Notification::fake();
});

function acc017RequestPhone($test, string $access, string $phone, ?string $key = null)
{
    return Account::post($test, $access, '/phone-change', ['phone' => $phone], $key);
}

it('sends a code to the new number only and replaces an earlier request', function () {
    $customer = Account::customer();
    $s = Account::signIn($this, $customer);

    $first = acc017RequestPhone($this, $s['access'], '+201011112222')->assertCreated()
        ->assertJsonPath('data.phone_masked', '+20 10 •••• 2222')->json('data.challenge_id');
    $second = acc017RequestPhone($this, $s['access'], '+201033334444')->assertCreated()->json('data.challenge_id');

    Notification::assertSentOnDemand(PhoneChangeCodeNotification::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['sms'] === '+201011112222');
    Notification::assertNotSentTo($customer, PhoneChangeCodeNotification::class);

    $code = Account::phoneCode('+201011112222');
    Account::post($this, $s['access'], "/phone-change/{$first}/confirm", ['code' => $code])
        ->assertUnprocessable()->assertJsonPath('code', 'change_code_invalid');
    expect($second)->not->toBe($first)
        ->and(AuditLog::query()->where('action', AuditEvent::CUSTOMER_PHONE_CHANGE_REQUESTED->value)->count())->toBe(2);
});

it('refuses the same number and a number another customer holds, before any SMS', function () {
    $customer = Account::customer();
    $other = Account::customer();
    $s = Account::signIn($this, $customer);

    acc017RequestPhone($this, $s['access'], $customer->phone)->assertUnprocessable()->assertJsonPath('code', 'same_contact');
    acc017RequestPhone($this, $s['access'], $other->phone)->assertConflict()->assertJsonPath('code', 'contact_taken');
    acc017RequestPhone($this, $s['access'], '01011112222')->assertUnprocessable()->assertJsonPath('code', 'validation_failed');

    Notification::assertNothingSentTo(new AnonymousNotifiable);
    Notification::assertNotSentTo($customer, PhoneChangeCodeNotification::class);
});

it('counts wrong codes and locks the code after five', function () {
    $customer = Account::customer();
    $s = Account::signIn($this, $customer);
    $id = acc017RequestPhone($this, $s['access'], '+201055556666')->json('data.challenge_id');

    $max = (int) config('dahab-auth.otp.max_verify_attempts');
    foreach (range($max - 1, 1) as $left) {
        Account::post($this, $s['access'], "/phone-change/{$id}/confirm", ['code' => '000000'])
            ->assertUnprocessable()->assertJsonPath('code', 'change_code_invalid')->assertJsonPath('details.tries_left', $left);
    }
    Account::post($this, $s['access'], "/phone-change/{$id}/confirm", ['code' => '000000'])->assertStatus(429)->assertJsonPath('code', 'change_code_locked');
    // A locked code is gone: a new request is needed (the confirm limiter may answer first).
    expect(Account::post($this, $s['access'], "/phone-change/{$id}/confirm", ['code' => Account::phoneCode('+201055556666')])->status())
        ->toBeIn([422, 429]);

    expect($customer->fresh()->phone)->not->toBe('+201055556666');
});

it('refuses an expired code', function () {
    $customer = Account::customer();
    $s = Account::signIn($this, $customer);
    $id = acc017RequestPhone($this, $s['access'], '+201077778888')->json('data.challenge_id');
    $code = Account::phoneCode('+201077778888');

    $this->travel((int) config('dahab-auth.otp.ttl_seconds') + 5)->seconds();

    Account::post($this, $s['access'], "/phone-change/{$id}/confirm", ['code' => $code])
        ->assertUnprocessable()->assertJsonPath('code', 'change_code_invalid');
});

it('moves the account, stops withdrawals, ends other sessions and tells the old number', function () {
    $withdrawal = Withdrawals::requested($this, '42000', '56760');
    $customer = Withdrawals::customer($withdrawal);
    $customer->password()->create(['password_hash' => bcrypt(Account::PASSWORD), 'password_changed_at' => now()]);
    $customer->forceFill(['email' => 'mona.h@email.com'])->save();
    $oldPhone = $customer->phone;

    $here = Account::signIn($this, $customer, 'phone-1');
    $there = Account::signIn($this, $customer, 'laptop-1', 'web');

    $id = acc017RequestPhone($this, $here['access'], '+201099990000', null)->assertCreated()->json('data.challenge_id');
    $res = Account::post($this, $here['access'], "/phone-change/{$id}/confirm", ['code' => Account::phoneCode('+201099990000')], deviceId: 'phone-1')
        ->assertOk()
        ->assertJsonPath('data.customer.phone', '+201099990000')
        ->assertJsonPath('data.cancelled_withdrawals', [$withdrawal->number()]);

    $pause = WithdrawalPause::query()->where('customer_id', $customer->customer_id)->latest('opened_at')->firstOrFail();
    expect($res->json('data.pause_until'))->not->toBeNull()
        ->and($pause->trigger_kind)->toBe(PauseTrigger::PHONE_CHANGE)
        ->and($pause->triggered_by_account)->toBeNull()
        ->and((int) round($pause->opened_at->diffInHours($pause->pause_until, true)))->toBe(48)
        ->and($withdrawal->fresh()->state)->toBe(WithdrawalState::CANCELLED)
        ->and(Withdrawals::lines($withdrawal->fresh()->return_txn_id))->not->toBeEmpty();

    // The other session is gone at once; this one keeps working.
    Account::as($this, $there['access'], 'laptop-1', 'web')->getJson(Account::ME.'/sessions')->assertUnauthorized();
    Account::as($this, $here['access'], 'phone-1')->getJson(Account::ME.'/sessions')->assertOk()->assertJsonCount(1, 'data');
    expect(CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)->pluck('fingerprint_hash')->all())
        ->toBe([Account::fingerprint('phone-1')]);

    // The old number is told by SMS; the email and the inbox through the customer.
    Notification::assertSentOnDemand(AccountNotification::class, fn ($n, $channels, AnonymousNotifiable $to) => ($to->routes['sms'] ?? null) === $oldPhone
        && $channels === ['sms'] && str_contains($n->body(false), '+20 10 •••• 0000'));
    Notification::assertSentTo($customer, AccountNotification::class, fn ($n, $channels) => $channels === ['inbox', 'mail']);

    $audit = AuditLog::query()->where('action', AuditEvent::CUSTOMER_PHONE_CHANGED->value)->sole();
    expect($audit->before_json['phone'])->toContain('••••')->not->toContain(substr($oldPhone, 4, 4))
        ->and($audit->actor_customer_id)->toBe($customer->customer_id);
});

it('writes the change notice to the inbox with both texts, once', function () {
    $customer = Account::customer(['preferred_lang' => 'en']);
    $notice = new AccountNotification(AccountEvent::PHONE_CHANGED, 'en', detail: '+20 10 •••• 0000');
    $notice->id = 'acc017-notice';

    app(InboxChannel::class)->send($customer, $notice);
    app(InboxChannel::class)->send($customer, $notice);

    $item = DatabaseActor::elevate('system', fn () => CustomerNotification::query()->where('customer_id', $customer->customer_id)->sole());
    expect($item->type)->toBe('account.phone_changed')
        ->and($item->link_kind->value)->toBe('account')
        ->and($item->title_en)->toBe('Your phone number was changed')
        ->and($item->title_ar)->toBe('رقم موبايلك اتغير')
        ->and($item->body_en)->toContain('+20 10 •••• 0000')
        ->and($item->body_ar)->toContain('+20 10 •••• 0000')
        ->and($item->read_at)->toBeNull();
});

it('lets a pending or suspended customer change the number', function (string $state) {
    $customer = $state === 'pending'
        ? Account::customer([], verified: false)
        : Customer::factory()->withPassword(Account::PASSWORD)->suspended(byStaffId: SystemActor::id())->create();
    $s = Account::signIn($this, $customer);

    $id = acc017RequestPhone($this, $s['access'], '+201024680000')->assertCreated()->json('data.challenge_id');
    Account::post($this, $s['access'], "/phone-change/{$id}/confirm", ['code' => Account::phoneCode('+201024680000')])->assertOk();

    expect($customer->fresh()->phone)->toBe('+201024680000');
})->with(['pending', 'suspended']);

it('needs an Idempotency-Key and limits requests to three an hour', function () {
    $customer = Account::customer();
    $s = Account::signIn($this, $customer);

    Account::as($this, $s['access'])->postJson(Account::ME.'/phone-change', ['phone' => '+201000000011'])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    foreach (['+201000000012', '+201000000013'] as $phone) {
        acc017RequestPhone($this, $s['access'], $phone)->assertCreated();
    }
    acc017RequestPhone($this, $s['access'], '+201000000015')->assertStatus(429);
});
