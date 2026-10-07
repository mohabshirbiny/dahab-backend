<?php

use App\Enums\AuditEvent;
use App\Enums\PauseTrigger;
use App\Enums\WithdrawalState;
use App\Models\AuditLog;
use App\Models\WithdrawalPause;
use App\Notifications\AccountNotification;
use App\Notifications\EmailChangeLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Account;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 017 US2, FR-010, FR-011: change the email with a single-use link sent to
// the new address; the old one is told; email is a withdrawal check.

beforeEach(function () {
    Notification::fake();
});

/** The token of the last link mailed to `$email`. */
function acc017EmailToken(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(EmailChangeLinkNotification::class,
        function (EmailChangeLinkNotification $n, array $channels, AnonymousNotifiable $to) use ($email, &$url) {
            if (($to->routes['mail'] ?? null) !== $email) {
                return false;
            }
            $url = $n->toMail($to)->actionUrl;

            return true;
        });
    expect($url)->toContain('/#/email-confirm?token=');

    return substr($url, strpos($url, 'token=') + 6);
}

function acc017PublicEmail($test, string $action, string $token)
{
    app('auth')->forgetGuards();

    return $test->withoutToken()->postJson('/api/v1/contact-changes/email/'.$action, ['token' => $token]);
}

it('mails a single-use link to the new address, storing only its hash', function () {
    $customer = Account::customer(['email' => 'old@example.com']);
    $s = Account::signIn($this, $customer);

    Account::post($this, $s['access'], '/email-change', ['email' => 'new@example.com'])
        ->assertCreated()->assertJsonPath('data.email_masked', 'n•••@example.com');
    $token = acc017EmailToken('new@example.com');

    $row = DB::table('one_time_token')->where('purpose', 'email_change')->sole();
    expect($row->token_hash)->not->toBe($token)
        ->and((int) round(now()->diffInMinutes($row->expires_at, true)))->toBe(30);
    acc017PublicEmail($this, 'read', $token)->assertOk()->assertJsonPath('data.email_masked', 'n•••@example.com');
    expect($customer->fresh()->email)->toBe('old@example.com');
});

it('refuses the same address and one another customer holds', function () {
    $customer = Account::customer(['email' => 'me@example.com']);
    Account::customer(['email' => 'taken@example.com']);
    $s = Account::signIn($this, $customer);

    Account::post($this, $s['access'], '/email-change', ['email' => 'ME@example.com'])->assertUnprocessable()->assertJsonPath('code', 'same_contact');
    Account::post($this, $s['access'], '/email-change', ['email' => 'Taken@Example.com'])->assertConflict()->assertJsonPath('code', 'contact_taken');
    Notification::assertNothingSentTo(new AnonymousNotifiable);
});

it('changes the email, stops withdrawal links and withdrawals, and tells the old address', function () {
    $withdrawal = Withdrawals::requested($this, '42000', '56760');
    $customer = Withdrawals::customer($withdrawal);
    $customer->password()->create(['password_hash' => bcrypt(Account::PASSWORD), 'password_changed_at' => now()]);
    $oldEmail = $customer->email;
    $openLink = Withdrawals::requestConfirmation($this, $customer, '1000', $withdrawal->payout_account_id)->assertCreated()->json('data.id');
    $s = Account::signIn($this, $customer);

    Account::post($this, $s['access'], '/email-change', ['email' => 'fresh@example.com'])->assertCreated();
    $res = acc017PublicEmail($this, 'confirm', acc017EmailToken('fresh@example.com'))->assertOk();

    $fresh = $customer->fresh();
    $pause = WithdrawalPause::query()->where('customer_id', $customer->customer_id)->latest('opened_at')->firstOrFail();
    expect($fresh->email)->toBe('fresh@example.com')
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($res->json('data.pause_until'))->not->toBeNull()
        ->and($pause->trigger_kind)->toBe(PauseTrigger::EMAIL_CHANGE)
        ->and($withdrawal->fresh()->state)->toBe(WithdrawalState::CANCELLED)
        ->and(DB::table('withdrawal_confirmation')->where('confirmation_id', $openLink)->value('replaced_at'))->not->toBeNull();

    Notification::assertSentOnDemand(AccountNotification::class, fn ($n, $channels, AnonymousNotifiable $to) => ($to->routes['mail'] ?? null) === $oldEmail && $channels === ['mail']);
    Notification::assertSentTo($customer, AccountNotification::class, fn ($n, $channels) => $channels === ['inbox', 'sms']);
    expect(AuditLog::query()->where('action', AuditEvent::CUSTOMER_EMAIL_CHANGED->value)->sole()->actor_customer_id)->toBe($customer->customer_id);
});

it('adds a first email without a pause or an old-address notice', function () {
    $customer = Account::customer(['email' => null]);
    $s = Account::signIn($this, $customer);

    Account::post($this, $s['access'], '/email-change', ['email' => 'first@example.com'])->assertCreated();
    acc017PublicEmail($this, 'confirm', acc017EmailToken('first@example.com'))->assertOk()->assertJsonPath('data.pause_until', null);

    expect($customer->fresh()->email)->toBe('first@example.com')
        ->and(WithdrawalPause::query()->where('customer_id', $customer->customer_id)->exists())->toBeFalse();
    Notification::assertNotSentTo(new AnonymousNotifiable, AccountNotification::class);
});

it('refuses a link used twice, an expired link, an older link and an unknown one', function () {
    $customer = Account::customer(['email' => 'a@example.com']);
    $s = Account::signIn($this, $customer);

    Account::post($this, $s['access'], '/email-change', ['email' => 'b@example.com'])->assertCreated();
    $older = acc017EmailToken('b@example.com');
    Account::post($this, $s['access'], '/email-change', ['email' => 'c@example.com'])->assertCreated();
    $newer = acc017EmailToken('c@example.com');

    acc017PublicEmail($this, 'confirm', $older)->assertStatus(410)->assertJsonPath('code', 'change_link_invalid');
    acc017PublicEmail($this, 'confirm', $newer)->assertOk();
    acc017PublicEmail($this, 'confirm', $newer)->assertStatus(410);
    acc017PublicEmail($this, 'read', str_repeat('x', 43))->assertStatus(410);

    Account::post($this, $s['access'], '/email-change', ['email' => 'd@example.com'])->assertCreated();
    $late = acc017EmailToken('d@example.com');
    $this->travel(31)->minutes();
    acc017PublicEmail($this, 'confirm', $late)->assertStatus(410);
    expect($customer->fresh()->email)->toBe('c@example.com');
});
