<?php

use App\Actions\TopUp\MatchTopUpAction;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\TopUp;
use App\Notifications\TopUpCreditedNotification;
use App\Notifications\TopUpRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 FR-026 (Clarification Q3): SMS, plus email when the customer has
// one, on credited and on rejected; after commit; never the staff note;
// nothing on hold, un-hold or cancel.

beforeEach(function () {
    Notification::fake();
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->account = ReceivingAccount::factory()->instapay()->create();
});

function notifiedTopUp(?string $email, string $lang = 'en'): TopUp
{
    $customer = Customer::factory()->verified()->create(['email' => $email, 'preferred_lang' => $lang]);

    return TopUp::factory()->create(['customer_id' => $customer->customer_id]);
}

it('tells the customer what was credited, by SMS and email', function () {
    $topUp = notifiedTopUp('mona@example.test');

    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/match", ['amount' => '19900', 'receiving_account_id' => $this->account->receiving_account_id, 'note' => 'Fee taken.'], TopUps::key())->assertOk();

    Notification::assertSentTo($topUp->customer, TopUpCreditedNotification::class, function (TopUpCreditedNotification $n, array $channels) use ($topUp) {
        $sms = $n->toSms($topUp->customer)->content;
        $mail = implode(' ', $n->toMail($topUp->customer)->introLines);

        return $channels === ['inbox', 'mail', 'sms']
            && str_contains($sms, '19,900.00') && str_contains($sms, 'TOP-'.$topUp->topup_no)
            && str_contains($mail, '19,900.00')
            && ! str_contains($sms.$mail, 'Fee taken.');
    });
});

it('texts only when the customer has no email, in their language', function () {
    $topUp = notifiedTopUp(null, 'ar');

    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/match", ['amount' => '20000', 'receiving_account_id' => $this->account->receiving_account_id], TopUps::key())->assertOk();

    Notification::assertSentTo($topUp->customer, TopUpCreditedNotification::class, function (TopUpCreditedNotification $n, array $channels) use ($topUp) {
        return $channels === ['inbox', 'sms'] && str_contains($n->toSms($topUp->customer)->content, 'محفظتك');
    });
});

it('tells the customer the plain reason of a rejection, never the note', function () {
    $topUp = notifiedTopUp('mona@example.test');

    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/reject", ['reason' => 'money_not_received', 'note' => 'Internal: checked CIB twice.'], TopUps::key())->assertOk();

    Notification::assertSentTo($topUp->customer, TopUpRejectedNotification::class, function (TopUpRejectedNotification $n, array $channels) use ($topUp) {
        $text = $n->toSms($topUp->customer)->content.' '.implode(' ', $n->toMail($topUp->customer)->introLines);

        return $channels === ['inbox', 'mail', 'sms']
            && str_contains($text, 'We did not receive this transfer')
            && ! str_contains($text, 'Internal');
    });
});

it('sends nothing for hold, un-hold or cancel', function () {
    $topUp = notifiedTopUp('mona@example.test');
    $cancelled = notifiedTopUp('karim@example.test');

    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/hold", ['note' => 'Checking.'], TopUps::key())->assertOk();
    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/unhold", [], TopUps::key())->assertOk();
    $this->withToken(TopUps::customerToken($cancelled->customer))
        ->postJson("/api/v1/customer/me/wallet/topups/{$cancelled->topup_id}/cancel", [], TopUps::key())->assertOk();

    Notification::assertNothingSent();
});

it('sends nothing when the credit does not commit', function () {
    $topUp = notifiedTopUp('mona@example.test');

    try {
        DB::transaction(function () use ($topUp) {
            app(MatchTopUpAction::class)->handle($this->finance, $topUp->topup_id, '20000', $this->account->receiving_account_id, null, null);
            throw new RuntimeException('the caller fails after the credit');
        });
    } catch (RuntimeException) {
    }

    expect($topUp->fresh()->status->value)->toBe('pending');
    Notification::assertNothingSent();
});
