<?php

use App\Enums\PayoutAccountState;
use App\Enums\SeedRole;
use App\Enums\SettingKey;
use App\Enums\WithdrawalState;
use App\Models\AgreementAcceptance;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PayoutAccount;
use App\Models\PayoutAccountChange;
use App\Models\Withdrawal;
use App\Models\WithdrawalPause;
use App\Notifications\PayoutNotification;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Listings;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 US1–US3, FR-001–FR-006: payout accounts — add with the
// declaration, verify / refuse by staff, the account in use, remove / keep,
// and the change that cancels open withdrawals and opens the pause.

beforeEach(function () {
    Notification::fake();
});

function payoutEvents(Customer $customer, string $event): int
{
    return Notification::sent($customer, PayoutNotification::class)->filter(fn ($n) => $n->event->value === $event)->count();
}

function setPauseHours(int $hours): void
{
    DB::table('setting')->where('setting_key', SettingKey::WITHDRAWAL_ACCOUNT_CHANGE_PAUSE_HOURS->value)->update(['value_numeric' => $hours]);
}

it('adds an account under review with the declaration, and tells the customer', function () {
    $customer = Withdrawals::funded('0');

    Withdrawals::add($this, $customer, ['account_number_or_iban' => 'eg38 0019 0005 0000 0000 2631 8000 2'])
        ->assertCreated()
        ->assertJsonPath('data.accounts.0.state', 'pending_review')
        ->assertJsonPath('data.accounts.0.in_use', false)
        ->assertJsonPath('data.accounts.0.number_masked', '•••• 0002')
        ->assertJsonPath('data.accounts.0.kind', 'iban')
        ->assertJsonPath('data.pause', null)
        ->assertJsonPath('data.recent_changes.0.kind', 'added')
        ->assertJsonPath('data.recent_changes.0.by', 'you');

    $account = PayoutAccount::query()->where('customer_id', $customer->customer_id)->sole();
    expect($account->account_number_or_iban)->toBe('EG380019000500000000263180002')
        ->and(AgreementAcceptance::query()->where('customer_id', $customer->customer_id)->where('context', 'payout_account')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payout_account.added')->where('actor_customer_id', $customer->customer_id)->count())->toBe(1)
        ->and(WithdrawalPause::query()->count())->toBe(0)
        ->and(payoutEvents($customer, 'account_added'))->toBe(1);
});

it('refuses a bad number, a missing declaration and unverified or suspended customers', function () {
    $customer = Withdrawals::funded('0');

    Withdrawals::add($this, $customer, ['account_number_or_iban' => 'EG390019000500000000263180002'])->assertUnprocessable()->assertJsonValidationErrors('account_number_or_iban');
    Withdrawals::add($this, $customer, ['account_number_or_iban' => '1234567'])->assertUnprocessable()->assertJsonValidationErrors('account_number_or_iban');
    Withdrawals::add($this, $customer, ['bank_name' => 'C'])->assertUnprocessable()->assertJsonValidationErrors('bank_name');
    Withdrawals::add($this, $customer, ['declaration_accepted' => false])->assertUnprocessable()->assertJsonValidationErrors('declaration_accepted');
    Withdrawals::add($this, $customer, ['declaration_id' => 999])->assertUnprocessable()->assertJsonPath('code', 'declaration_required');

    $unverified = Customer::factory()->pendingVerification()->create();
    Withdrawals::add($this, $unverified)->assertForbidden()->assertJsonPath('code', 'verification_required');

    $suspended = Customer::factory()->verified()->suspended(byStaffId: SystemActor::id(), note: 'test')->create();
    Withdrawals::add($this, $suspended)->assertForbidden()->assertJsonPath('code', 'account_suspended');

    expect(PayoutAccount::query()->count())->toBe(0);
});

it('takes the same submission once', function () {
    $customer = Withdrawals::funded('0');
    Withdrawals::add($this, $customer, key: 'a0000000-0000-4000-8000-000000000001')->assertCreated();
    Withdrawals::add($this, $customer, key: 'a0000000-0000-4000-8000-000000000001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(PayoutAccount::query()->count())->toBe(1);
});

it('verifies an account: the first one becomes the one in use without a pause', function () {
    $customer = Withdrawals::funded('0');
    $id = Withdrawals::add($this, $customer)->json('data.accounts.0.id');
    $verifier = Withdrawals::staff($this, SeedRole::VERIFICATION);

    $this->getJson(Withdrawals::STAFF.'/payout-accounts')->assertOk()
        ->assertJsonPath('meta.waiting', 1)
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.number', Withdrawals::iban())
        ->assertJsonPath('data.0.customer.display_ref', $customer->display_ref);

    $this->postJson(Withdrawals::STAFF."/payout-accounts/{$id}/verify", [], Listings::key())->assertOk()
        ->assertJsonPath('data.state', 'active')
        ->assertJsonPath('data.in_use', true)
        ->assertJsonPath('meta.pause_until', null);

    $account = PayoutAccount::query()->findOrFail($id);
    expect($account->name_checked_by)->toBe($verifier->staff_id)
        ->and($account->activated_at)->not->toBeNull()
        ->and(WithdrawalPause::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payout_account.verified')->where('actor_staff_id', $verifier->staff_id)->count())->toBe(1)
        ->and(payoutEvents($customer, 'account_verified'))->toBe(1);

    $this->postJson(Withdrawals::STAFF."/payout-accounts/{$id}/verify", [], Listings::key())->assertConflict()
        ->assertJsonPath('code', 'illegal_payout_account_transition');
});

it('refuses an account with a reason the customer sees, never the note', function () {
    $customer = Withdrawals::funded('0');
    $id = Withdrawals::add($this, $customer)->json('data.accounts.0.id');
    Withdrawals::staff($this, SeedRole::FINANCE);

    $this->postJson(Withdrawals::STAFF."/payout-accounts/{$id}/refuse", ['reason' => 'name_shortened', 'note' => 'ID says Mona Hassan Ibrahim Ali'], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'refused')->assertJsonPath('data.refusal.reason', 'name_shortened');

    $list = Listings::as($this, $customer)->getJson(Withdrawals::CUSTOMER.'/payout-accounts')->assertOk()
        ->assertJsonPath('data.accounts.0.state', 'refused')
        ->assertJsonPath('data.accounts.0.refusal_reason', 'name_shortened');
    expect(json_encode($list->json()))->not->toContain('ID says');

    $sent = Notification::sent($customer, PayoutNotification::class)->first(fn ($n) => $n->event->value === 'account_refused');
    expect($sent->body(false))->toContain('shortened')->not->toContain('ID says');

    Withdrawals::staff($this, SeedRole::FINANCE);
    $this->postJson(Withdrawals::STAFF."/payout-accounts/{$id}/refuse", ['reason' => 'nope', 'note' => 'x'], Listings::key())->assertUnprocessable();
});

it('keeps the COO, Operations and IGI away from verification', function (SeedRole $role) {
    $customer = Withdrawals::funded('0');
    $id = Withdrawals::add($this, $customer)->json('data.accounts.0.id');
    Withdrawals::staff($this, $role);

    $this->getJson(Withdrawals::STAFF.'/payout-accounts')->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->postJson(Withdrawals::STAFF."/payout-accounts/{$id}/verify", [], Listings::key())->assertForbidden();
    expect(PayoutAccount::query()->findOrFail($id)->state)->toBe(PayoutAccountState::PENDING_REVIEW);
})->with([SeedRole::COO, SeedRole::OPERATIONS, SeedRole::IGI_BRANCH]);

it('making another account the one in use cancels open withdrawals and opens the pause', function () {
    $w = Withdrawals::requested($this, '10000', '30000');
    $customer = Withdrawals::customer($w);
    $first = PayoutAccount::query()->findOrFail($w->payout_account_id);
    $second = Withdrawals::verifiedAccount($this, $customer, ['bank_name' => 'NBE', 'account_number_or_iban' => '1234567890']);

    expect($second->is_in_use)->toBeFalse();

    $res = Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$second->payout_account_id}/use", [], Listings::key())
        ->assertOk()
        ->assertJsonPath('meta.cancelled_withdrawals.0', $w->number())
        ->assertJsonPath('data.accounts.0.id', $second->payout_account_id)
        ->assertJsonPath('data.accounts.0.in_use', true);

    $w->refresh();
    $pause = WithdrawalPause::query()->sole();
    expect($w->state)->toBe(WithdrawalState::CANCELLED)
        ->and($w->cancelled_by_change)->toBeTrue()
        ->and(Withdrawals::lines($w->return_txn_id))->toBe([['cust_held', '-10000.0000'], ['cust_available', '10000.0000']])
        ->and($pause->pause_until->diffInHours($pause->opened_at, true))->toBe(48.0)
        ->and($res->json('data.pause.until'))->toBe($pause->pause_until->toIso8601String())
        ->and($first->refresh()->is_in_use)->toBeFalse()
        ->and(PayoutAccountChange::query()->where('kind', 'in_use')->latest('change_id')->first()->pause_id)->toBe($pause->pause_id)
        ->and(payoutEvents($customer, 'account_in_use'))->toBe(2)
        ->and(payoutEvents($customer, 'withdrawals_cancelled_by_change'))->toBe(1);

    // New withdrawals are refused until the pause ends.
    Withdrawals::requestConfirmation($this, $customer, '100', $second->payout_account_id)
        ->assertConflict()->assertJsonPath('code', 'withdrawals_paused')
        ->assertJsonPath('details.pause_until', $pause->pause_until->toIso8601String());
});

it('reads the pause window from the setting, and opens none at 0', function () {
    setPauseHours(12);
    $customer = Withdrawals::funded('1000');
    Withdrawals::verifiedAccount($this, $customer);
    $b = Withdrawals::verifiedAccount($this, $customer, ['account_number_or_iban' => '1234567890']);
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$b->payout_account_id}/use", [], Listings::key())->assertOk();
    $pause = WithdrawalPause::query()->sole();
    expect($pause->pause_until->diffInHours($pause->opened_at, true))->toBe(12.0);

    setPauseHours(0);
    $c = Withdrawals::verifiedAccount($this, $customer, ['account_number_or_iban' => '1234567891']);
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$c->payout_account_id}/use", [], Listings::key())->assertOk();
    expect(WithdrawalPause::query()->count())->toBe(1);
});

it('cancels a request, removes an account, schedules a removal and keeps it', function () {
    $customer = Withdrawals::funded('20000');
    $pending = Withdrawals::add($this, $customer)->json('data.accounts.0.id');
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$pending}/remove", [], Listings::key())->assertOk();
    expect(PayoutAccount::query()->findOrFail($pending)->state)->toBe(PayoutAccountState::REMOVED)
        ->and(PayoutAccountChange::query()->where('payout_account_id', $pending)->latest('change_id')->value('kind')->value)->toBe('request_cancelled');

    $w = Withdrawals::requested($this, '5000', '0', $customer);
    $account = PayoutAccount::query()->findOrFail($w->payout_account_id);

    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$account->payout_account_id}/remove", [], Listings::key())
        ->assertOk()->assertJsonPath('data.accounts.0.state', 'removing')->assertJsonPath('data.accounts.0.in_use', true)
        ->assertJsonPath('data.accounts.0.can.keep', true);

    // A removing account takes no new withdrawal.
    Withdrawals::requestConfirmation($this, $customer, '100', $account->payout_account_id)->assertConflict()->assertJsonPath('code', 'payout_account_not_active');

    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$account->payout_account_id}/keep", [], Listings::key())
        ->assertOk()->assertJsonPath('data.accounts.0.state', 'active');
    expect(WithdrawalPause::query()->count())->toBe(0);

    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$account->payout_account_id}/remove", [], Listings::key())->assertOk();
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}/cancel", [], Listings::key())->assertOk();

    expect($account->refresh()->state)->toBe(PayoutAccountState::REMOVED)
        ->and($account->is_in_use)->toBeFalse()
        ->and(PayoutAccountChange::query()->where('payout_account_id', $account->payout_account_id)->latest('change_id')->value('actor_customer_id'))->toBe($customer->customer_id);
});

it('a removing account still receives its in-flight withdrawal, and is removed by the release', function () {
    $w = Withdrawals::requested($this, '5000', '10000');
    $customer = Withdrawals::customer($w);
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$w->payout_account_id}/remove", [], Listings::key())->assertOk();

    $finance = Withdrawals::staff($this, SeedRole::FINANCE);
    Withdrawals::act($this, $w, 'review')->assertOk();
    Withdrawals::release($this, $w)->assertOk()->assertJsonPath('data.state', 'released');

    $account = PayoutAccount::query()->findOrFail($w->payout_account_id);
    expect($account->state)->toBe(PayoutAccountState::REMOVED)
        ->and($account->is_in_use)->toBeFalse()
        ->and(PayoutAccountChange::query()->where('payout_account_id', $account->payout_account_id)->latest('change_id')->value('actor_staff_id'))->toBe($finance->staff_id);
});

it('making another account in use while one is removing removes it in the same operation', function () {
    $w = Withdrawals::requested($this, '5000', '10000');
    $customer = Withdrawals::customer($w);
    $b = Withdrawals::verifiedAccount($this, $customer, ['account_number_or_iban' => '1234567890']);
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$w->payout_account_id}/remove", [], Listings::key())->assertOk();

    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$b->payout_account_id}/use", [], Listings::key())->assertOk();

    expect($w->refresh()->state)->toBe(WithdrawalState::CANCELLED)
        ->and(PayoutAccount::query()->findOrFail($w->payout_account_id)->state)->toBe(PayoutAccountState::REMOVED)
        ->and($b->refresh()->is_in_use)->toBeTrue();
});

it('lets a suspended customer read but not change accounts', function () {
    $customer = Withdrawals::funded('0');
    $account = Withdrawals::verifiedAccount($this, $customer);
    DB::table('customer')->where('customer_id', $customer->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(), 'suspended_note' => 'test',
    ]);

    Listings::as($this, $customer)->getJson(Withdrawals::CUSTOMER.'/payout-accounts')->assertOk();
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$account->payout_account_id}/remove", [], Listings::key())
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('never shows another customer\'s accounts', function () {
    $a = Withdrawals::funded('0');
    $b = Withdrawals::funded('0');
    $accountA = Withdrawals::add($this, $a)->json('data.accounts.0.id');

    Listings::as($this, $b)->getJson(Withdrawals::CUSTOMER.'/payout-accounts')->assertOk()->assertJsonCount(0, 'data.accounts');
    Listings::as($this, $b)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$accountA}/remove", [], Listings::key())->assertNotFound();
    expect(Withdrawal::query()->count())->toBe(0);
});
