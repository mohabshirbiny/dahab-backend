<?php

use App\Enums\PayoutAccountState;
use App\Enums\SeedRole;
use App\Enums\WithdrawalState;
use App\Models\AuditLog;
use App\Notifications\PayoutNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Listings;
use Tests\Support\TopUps;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 US6, FR-012–FR-014: the Withdrawals queue — figures, signals,
// take for review, hold / unhold, release with the bank record (held → bank),
// reject (held → available), the export, and who may do what.

beforeEach(function () {
    Notification::fake();
});

it('lists the queue oldest first with figures, signals and full numbers for Finance', function () {
    $first = Withdrawals::requested($this, '42000', '56760');
    $this->travel(1)->minutes();
    $second = Withdrawals::requested($this, '1000', '5000');
    Withdrawals::staff($this, SeedRole::FINANCE);

    $res = $this->getJson(Withdrawals::STAFF.'/withdrawals')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->withdrawal_id)
        ->assertJsonPath('data.0.customer.available', '14760.0000')
        ->assertJsonPath('data.0.account.number', Withdrawals::iban())
        ->assertJsonPath('data.0.signals.identity_verified', true)
        ->assertJsonPath('data.0.signals.first_payout_to_account', true)
        ->assertJsonPath('data.0.signals.payouts_to_account', 0)
        ->assertJsonPath('data.0.signals.topped_up_never_traded', true)
        ->assertJsonPath('data.0.can.review', true)
        ->assertJsonPath('data.0.can.release', false)
        ->assertJsonPath('meta.figures.waiting_count', 2)
        ->assertJsonPath('meta.figures.waiting_sum', '43000.0000')
        ->assertJsonPath('meta.figures.on_hold_count', 0);

    expect($res->json('data.1.id'))->toBe($second->withdrawal_id);

    $this->getJson(Withdrawals::STAFF.'/withdrawals?q='.Withdrawals::customer($second)->display_ref)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->withdrawal_id);
    $this->getJson(Withdrawals::STAFF.'/withdrawals?state=released')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(Withdrawals::STAFF.'/withdrawals?state=bogus')->assertUnprocessable();
    $this->getJson(Withdrawals::STAFF.'/withdrawals?per_page=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->withdrawal_id);
});

it('takes for review, holds, unholds, then releases to the bank with the bank record', function () {
    $w = Withdrawals::requested($this, '42000', '56760');
    $customer = Withdrawals::customer($w);
    $bankBefore = TopUps::bankCash();
    $finance = Withdrawals::staff($this, SeedRole::FINANCE);

    Withdrawals::act($this, $w, 'release', ['bank_txn_number' => 'FT1'])->assertConflict()->assertJsonPath('code', 'illegal_withdrawal_transition');

    Withdrawals::act($this, $w, 'review')->assertOk()->assertJsonPath('data.state', 'under_review')
        ->assertJsonPath('data.reviewer.id', $finance->staff_id);

    Withdrawals::act($this, $w, 'hold', ['reason' => 'account_changed_recently', 'message' => 'Please call us to confirm.', 'note' => 'new account last week'])
        ->assertOk()->assertJsonPath('data.hold.on_hold', true)->assertJsonPath('data.can.release', false);
    expect(Notification::sent($customer, PayoutNotification::class)->first(fn ($n) => $n->event->value === 'withdrawal_held')->body(false))
        ->toContain('Please call us to confirm.')->not->toContain('new account last week');

    Listings::as($this, $customer)->getJson(Withdrawals::CUSTOMER."/withdrawals/{$w->withdrawal_id}")->assertOk()
        ->assertJsonPath('data.on_hold', true)->assertJsonPath('data.hold_message', 'Please call us to confirm.');

    Withdrawals::staff($this, SeedRole::FINANCE);
    $this->getJson(Withdrawals::STAFF.'/withdrawals')->assertOk()->assertJsonPath('meta.figures.on_hold_count', 1);
    Withdrawals::release($this, $w)->assertConflict()->assertJsonPath('code', 'withdrawal_on_hold');

    Withdrawals::act($this, $w, 'unhold', ['note' => 'customer called'])->assertOk()->assertJsonPath('data.hold', null);

    $res = Withdrawals::release($this, $w)->assertOk()
        ->assertJsonPath('data.state', 'released')
        ->assertJsonPath('data.bank_txn_number', 'FT2610031234')
        ->assertJsonPath('data.transfer_reference', $w->number())
        ->assertJsonPath('data.ledger.1.kind', 'release');

    $w->refresh();
    expect($w->state)->toBe(WithdrawalState::RELEASED)
        ->and($w->reviewed_by)->not->toBeNull()
        ->and(Withdrawals::lines($w->release_txn_id))->toBe([['cust_held', '-42000.0000'], ['bank', '42000.0000']])
        ->and(bcsub($bankBefore, TopUps::bankCash(), 4))->toBe('42000.0000')
        ->and(bccomp(TopUps::globalSum(), '0', 4))->toBe(0)
        ->and(AuditLog::query()->where('action', 'withdrawal.released')->where('actor_staff_id', $w->reviewed_by)->count())->toBe(1)
        ->and(Notification::sent($customer, PayoutNotification::class)->filter(fn ($n) => $n->event->value === 'withdrawal_released'))->toHaveCount(1);

    $this->getJson(Withdrawals::STAFF.'/wallets/overview')->assertOk()
        ->assertJsonPath('data.pending_withdrawals', '0.0000')->assertJsonPath('data.system_total', '0.0000');
    expect($res->json('data.can'))->toBe(['review' => false, 'hold' => false, 'unhold' => false, 'release' => false, 'reject' => false]);

    Withdrawals::release($this, $w)->assertConflict()->assertJsonPath('code', 'illegal_withdrawal_transition');
});

it('validates the release record', function () {
    $w = Withdrawals::underReview($this);

    Withdrawals::act($this, $w, 'release', [])->assertUnprocessable()->assertJsonValidationErrors('bank_txn_number');
    Withdrawals::act($this, $w, 'release', ['bank_txn_number' => 'FT1234', 'value_date' => now()->addDay()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors('value_date');
    expect($w->refresh()->state)->toBe(WithdrawalState::UNDER_REVIEW);
});

it('rejects from requested or a held review: the money returns and the hold record stays', function () {
    $a = Withdrawals::requested($this, '1000', '5000');
    Withdrawals::staff($this, SeedRole::FINANCE);
    Withdrawals::act($this, $a, 'reject', ['reason' => 'money_in_straight_out', 'note' => 'topped up yesterday, no trades'])
        ->assertOk()->assertJsonPath('data.state', 'rejected')->assertJsonPath('data.rejection.reason', 'money_in_straight_out');
    expect(Withdrawals::lines($a->refresh()->return_txn_id))->toBe([['cust_held', '-1000.0000'], ['cust_available', '1000.0000']]);

    $customer = Withdrawals::customer($a);
    $sent = Notification::sent($customer, PayoutNotification::class)->first(fn ($n) => $n->event->value === 'withdrawal_rejected');
    expect($sent->body(false))->toContain('straight out')->not->toContain('topped up yesterday');

    $b = Withdrawals::underReview($this);
    Withdrawals::act($this, $b, 'hold', ['reason' => 'name_mismatch', 'message' => 'The name differs.', 'note' => 'check ID'])->assertOk();
    Withdrawals::act($this, $b, 'reject', ['reason' => 'account_not_in_name', 'note' => 'not hers'])->assertOk()
        ->assertJsonPath('data.hold.on_hold', false)->assertJsonPath('data.hold.reason', 'name_mismatch');

    expect($b->refresh()->held_at)->not->toBeNull()->and($b->state)->toBe(WithdrawalState::REJECTED);

    Withdrawals::act($this, $b, 'reject', ['reason' => 'other', 'note' => 'again'])->assertConflict();
});

it('exports the filtered list as audited CSV for withdrawal.release only', function () {
    Withdrawals::requested($this, '1000', '5000');
    $finance = Withdrawals::staff($this, SeedRole::FINANCE);

    $res = $this->get(Withdrawals::STAFF.'/withdrawals/export')->assertOk()->assertHeader('X-Export-Truncated', 'false');
    expect($res->getContent())->toStartWith("\xEF\xBB\xBF")->toContain(Withdrawals::iban())
        ->and(AuditLog::query()->where('action', 'withdrawal.list_exported')->where('actor_staff_id', $finance->staff_id)->count())->toBe(1);
});

it('lets wallet.view read the queue masked but never act or export', function () {
    $w = Withdrawals::requested($this, '1000', '5000');
    $reader = Withdrawals::staff($this, SeedRole::OPERATIONS);
    DB::table('model_has_permissions')->insert(['permission_id' => DB::table('permissions')->where('name', 'wallet.view')->value('id'), 'model_type' => $reader->getMorphClass(), 'model_id' => $reader->staff_id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson(Withdrawals::STAFF.'/withdrawals')->assertOk()
        ->assertJsonPath('data.0.account.number', null)
        ->assertJsonPath('data.0.account.number_masked', '•••• 0002')
        ->assertJsonPath('data.0.can.review', false);
    $this->getJson(Withdrawals::STAFF."/withdrawals/{$w->withdrawal_id}")->assertOk()->assertJsonPath('data.can.release', false);
    $this->get(Withdrawals::STAFF.'/withdrawals/export')->assertForbidden();
    Withdrawals::act($this, $w, 'review')->assertForbidden();
});

it('keeps the COO and every role but Finance and the CEO away from withdrawals', function (SeedRole $role) {
    $w = Withdrawals::requested($this, '1000', '5000');
    $staff = Withdrawals::staff($this, $role);

    $this->getJson(Withdrawals::STAFF.'/withdrawals')->assertForbidden()->assertJsonPath('code', 'permission_denied');
    foreach (['review', 'hold', 'unhold', 'release', 'reject'] as $action) {
        Withdrawals::act($this, $w, $action, ['bank_txn_number' => 'FT1', 'reason' => 'other', 'message' => 'xyz', 'note' => 'xyz'])->assertForbidden();
    }
    expect($w->refresh()->state)->toBe(WithdrawalState::REQUESTED)
        ->and(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->count())->toBeGreaterThan(0);
})->with([SeedRole::COO, SeedRole::OPERATIONS, SeedRole::VERIFICATION, SeedRole::IGI_BRANCH]);

it('lets the CEO release', function () {
    $w = Withdrawals::requested($this, '1000', '5000');
    TopUps::actAsStaff($this, SeedRole::CEO, founder: true);
    Withdrawals::act($this, $w, 'review')->assertOk();
    Withdrawals::release($this, $w)->assertOk();
});

it('refuses a release to an account that is no longer the customer\'s usable one', function () {
    $w = Withdrawals::underReview($this);
    // Only reachable through a raw change (no feature refuses an account after verification).
    DB::table('payout_account')->where('payout_account_id', $w->payout_account_id)->update(['is_in_use' => false]);
    DB::table('payout_account')->where('payout_account_id', $w->payout_account_id)->update(['state' => PayoutAccountState::REMOVED->value]);

    Withdrawals::release($this, $w)->assertConflict()->assertJsonPath('code', 'payout_account_not_active');
});
