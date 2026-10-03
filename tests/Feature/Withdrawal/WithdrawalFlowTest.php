<?php

use App\Enums\LedgerEventKind;
use App\Enums\WithdrawalState;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\Withdrawal;
use App\Models\WithdrawalConfirmation;
use App\Notifications\WithdrawalConfirmationNotification;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Ledger;
use Tests\Support\Listings;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 US4, US5, FR-007–FR-011, FR-017: the email second-check (confirm,
// then submit), the hold, the customer's cancel, and the wallet split.

beforeEach(function () {
    Notification::fake();
    $this->customer = Withdrawals::funded('56760', ['email' => 'mona.h@email.com']);
    $this->account = Withdrawals::verifiedAccount($this, $this->customer);
});

function walletOf($test, Customer $customer): array
{
    return Listings::as($test, $customer)->getJson(Withdrawals::CUSTOMER.'/wallet')->assertOk()->json('data');
}

it('emails a single-use link tied to the amount and the account, storing only its hash', function () {
    $res = Withdrawals::requestConfirmation($this, $this->customer, '42000', $this->account->payout_account_id)
        ->assertCreated()
        ->assertJsonPath('data.state', 'sent')
        ->assertJsonPath('data.amount', '42000.0000')
        ->assertJsonPath('data.account.number_masked', '•••• 0002')
        ->assertJsonPath('data.email_masked', 'm•••@email.com');

    $sent = Notification::sent($this->customer, WithdrawalConfirmationNotification::class);
    $token = Withdrawals::emailedToken($this->customer);
    $row = WithdrawalConfirmation::query()->findOrFail($res->json('data.id'));

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->link)->toContain('/#/withdraw-confirm?token=')
        ->and($sent->first()->via($this->customer))->toBe(['mail'])
        ->and($row->token_hash)->not->toBe($token)
        ->and((int) round($row->expires_at->diffInMinutes($row->created_at, true)))->toBe(30)
        ->and(json_encode($res->json()))->not->toContain($token);
});

it('checks the gates before sending a link', function () {
    Withdrawals::requestConfirmation($this, $this->customer, '60000', $this->account->payout_account_id)
        ->assertConflict()->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('details.available', '56760.0000')->assertJsonPath('details.shortfall', '3240.0000');
    Withdrawals::requestConfirmation($this, $this->customer, '0', $this->account->payout_account_id)->assertUnprocessable();
    Withdrawals::requestConfirmation($this, $this->customer, '10.001', $this->account->payout_account_id)->assertUnprocessable();
    Withdrawals::requestConfirmation($this, $this->customer, '100', (string) Str::uuid())
        ->assertConflict()->assertJsonPath('code', 'payout_account_not_active');

    Notification::assertNotSentTo($this->customer, WithdrawalConfirmationNotification::class);
});

it('reads the link with no side effect, confirms it once, and refuses expired, replaced and unknown tokens', function () {
    $id = Withdrawals::requestConfirmation($this, $this->customer, '1000', $this->account->payout_account_id)->json('data.id');
    $token = Withdrawals::emailedToken($this->customer);

    Listings::anonymous($this);
    $this->postJson(Withdrawals::PUBLIC.'/read', ['token' => $token])->assertOk()
        ->assertJsonPath('data.state', 'sent')->assertJsonPath('data.account_masked', 'CIB •••• 0002')
        ->assertJsonMissingPath('data.customer_id');
    expect(WithdrawalConfirmation::query()->findOrFail($id)->confirmed_at)->toBeNull();

    Withdrawals::confirmLink($this, $token)->assertOk()->assertJsonPath('data.state', 'confirmed');
    Withdrawals::confirmLink($this, $token)->assertOk()->assertJsonPath('data.state', 'confirmed');
    expect(AuditLog::query()->where('action', 'withdrawal.email_confirmed')->where('actor_customer_id', $this->customer->customer_id)->count())->toBe(1);

    Listings::as($this, $this->customer)->getJson(Withdrawals::CUSTOMER."/withdrawals/confirmations/{$id}")->assertOk()->assertJsonPath('data.state', 'confirmed');

    // A newer request replaces it.
    Withdrawals::requestConfirmation($this, $this->customer, '2000', $this->account->payout_account_id)->assertCreated();
    Withdrawals::confirmLink($this, $token)->assertUnprocessable()->assertJsonPath('code', 'confirmation_invalid');

    // Expired.
    $newToken = Withdrawals::emailedToken($this->customer);
    $this->travel(31)->minutes();
    Withdrawals::confirmLink($this, $newToken)->assertUnprocessable()->assertJsonPath('code', 'confirmation_invalid');

    Withdrawals::confirmLink($this, str_repeat('x', 43))->assertUnprocessable()->assertJsonPath('code', 'confirmation_invalid');
});

it('holds the money on submit in one balanced withdrawal entry', function () {
    $confirmation = Withdrawals::confirmedFor($this, $this->customer, '42000', $this->account->payout_account_id);

    $res = Withdrawals::submit($this, $this->customer, $confirmation, '42000.00', $this->account->payout_account_id)
        ->assertCreated()
        ->assertJsonPath('data.state', 'requested')
        ->assertJsonPath('data.amount', '42000.0000')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonMissingPath('data.bank_txn_number');

    $w = Withdrawal::query()->findOrFail($res->json('data.id'));
    $txn = LedgerTransaction::query()->findOrFail($w->hold_txn_id);

    expect($res->json('data.number'))->toBe($w->number())
        ->and($txn->event_kind)->toBe(LedgerEventKind::WITHDRAWAL)
        ->and($txn->withdrawal_id)->toBe($w->withdrawal_id)
        ->and($txn->customer_id)->toBe($this->customer->customer_id)
        ->and(Withdrawals::lines($txn->ledger_txn_id))->toBe([['cust_available', '-42000.0000'], ['cust_held', '42000.0000']])
        ->and(WithdrawalConfirmation::query()->findOrFail($confirmation)->withdrawal_id)->toBe($w->withdrawal_id)
        ->and(AuditLog::query()->where('action', 'withdrawal.requested')->count())->toBe(1);

    $wallet = walletOf($this, $this->customer);
    expect($wallet['available'])->toBe('14760.0000')
        ->and($wallet['held'])->toBe('42000.0000')
        ->and($wallet['pending_withdrawals'])->toBe('42000.0000')
        ->and($wallet['held_on_orders'])->toBe('0.0000');

    $history = Listings::as($this, $this->customer)->getJson(Withdrawals::CUSTOMER.'/wallet/transactions')->assertOk()->json('data.0');
    expect($history['kind'])->toBe('withdrawal')->and($history['reference'])->toBe($w->number());

    // The confirmation is used: it cannot carry a second withdrawal.
    Withdrawals::submit($this, $this->customer, $confirmation, '42000', $this->account->payout_account_id)
        ->assertForbidden()->assertJsonPath('code', 'email_confirmation_required');
});

it('refuses a submit without a usable confirmation of the same customer, amount and account', function () {
    $unconfirmed = Withdrawals::requestConfirmation($this, $this->customer, '1000', $this->account->payout_account_id)->json('data.id');
    Withdrawals::submit($this, $this->customer, $unconfirmed, '1000', $this->account->payout_account_id)
        ->assertForbidden()->assertJsonPath('code', 'email_confirmation_required');

    $confirmed = Withdrawals::confirmedFor($this, $this->customer, '1000', $this->account->payout_account_id);
    Withdrawals::submit($this, $this->customer, $confirmed, '999', $this->account->payout_account_id)
        ->assertForbidden()->assertJsonPath('code', 'email_confirmation_required');

    $other = Withdrawals::funded('5000');
    Withdrawals::submit($this, $other, $confirmed, '1000', $this->account->payout_account_id)
        ->assertForbidden()->assertJsonPath('code', 'email_confirmation_required');

    $this->travel(31)->minutes();
    Withdrawals::submit($this, $this->customer, $confirmed, '1000', $this->account->payout_account_id)
        ->assertForbidden()->assertJsonPath('code', 'email_confirmation_required');

    expect(Withdrawal::query()->count())->toBe(0);
});

it('re-checks the money at submit', function () {
    $confirmed = Withdrawals::confirmedFor($this, $this->customer, '50000', $this->account->payout_account_id);
    Ledger::hold($this->customer, '10000');

    Withdrawals::submit($this, $this->customer, $confirmed, '50000', $this->account->payout_account_id)
        ->assertConflict()->assertJsonPath('code', 'insufficient_funds')->assertJsonPath('details.shortfall', '3240.0000');
    expect(Withdrawal::query()->count())->toBe(0);
});

it('takes the same submission once', function () {
    $confirmed = Withdrawals::confirmedFor($this, $this->customer, '1000', $this->account->payout_account_id);
    $key = 'b0000000-0000-4000-8000-000000000001';
    Withdrawals::submit($this, $this->customer, $confirmed, '1000', $this->account->payout_account_id, $key)->assertCreated();
    Withdrawals::submit($this, $this->customer, $confirmed, '1000', $this->account->payout_account_id, $key)->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(Withdrawal::query()->count())->toBe(1);
});

it('lets a suspended customer withdraw a remaining balance and cancel', function () {
    DB::table('customer')->where('customer_id', $this->customer->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(), 'suspended_note' => 'test',
    ]);

    $w = Withdrawals::requested($this, '1000', '0', $this->customer);
    Listings::as($this, $this->customer)->postJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}/cancel", [], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'cancelled');
});

it('cancels before release: the money returns to available', function () {
    $w = Withdrawals::requested($this, '42000', '0', $this->customer);

    Listings::as($this, $this->customer)->postJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}/cancel", [], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'cancelled')->assertJsonPath('data.can_cancel', false);

    $w->refresh();
    expect($w->state)->toBe(WithdrawalState::CANCELLED)
        ->and($w->cancelled_by_change)->toBeFalse()
        ->and($w->ended_at)->not->toBeNull()
        ->and(Withdrawals::lines($w->return_txn_id))->toBe([['cust_held', '-42000.0000'], ['cust_available', '42000.0000']])
        ->and(walletOf($this, $this->customer)['available'])->toBe('56760.0000')
        ->and(AuditLog::query()->where('action', 'withdrawal.cancelled')->count())->toBe(1);

    Listings::as($this, $this->customer)->postJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}/cancel", [], Listings::key())
        ->assertConflict()->assertJsonPath('code', 'illegal_withdrawal_transition');
});

it('lists and shows only the customer\'s own withdrawals', function () {
    $w = Withdrawals::requested($this, '1000', '0', $this->customer);
    $other = Withdrawals::funded('5000');

    Listings::as($this, $this->customer)->getJson(Withdrawals::CUSTOMER.'/withdrawals')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $w->withdrawal_id);
    Listings::as($this, $this->customer)->getJson(Withdrawals::CUSTOMER.'/withdrawals?state=closed')->assertOk()->assertJsonCount(0, 'data');
    Listings::as($this, $other)->getJson(Withdrawals::CUSTOMER.'/withdrawals')->assertOk()->assertJsonCount(0, 'data');
    Listings::as($this, $other)->getJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}")->assertNotFound();
    Listings::as($this, $other)->postJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}/cancel", [], Listings::key())->assertNotFound();
});

it('refuses unverified customers', function () {
    $unverified = Customer::factory()->pendingVerification()->create();
    Listings::as($this, $unverified)->getJson(Withdrawals::CUSTOMER.'/withdrawals')->assertForbidden()->assertJsonPath('code', 'verification_required');
});
