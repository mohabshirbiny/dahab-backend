<?php

use App\Enums\CustomerStatus;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Enums\TopUpOrigin;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Notifications\TopUpCreditedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US3, FR-018, FR-018a (post-analysis clarification): POST
// /dashboard/topups — money that arrived without a notice.

const BY_HAND_URL = '/api/v1/dashboard/topups';

beforeEach(function () {
    Notification::fake();
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->vf = ReceivingAccount::factory()->vodafoneCash()->create();
});

function byHand($test, Customer $customer, array $extra = [], ?string $key = null)
{
    return $test->postJson(BY_HAND_URL, array_merge([
        'customer_id' => $customer->customer_id,
        'amount' => '15000',
        'receiving_account_id' => $test->vf->receiving_account_id,
        'note' => 'Phone matches 01x…8842, no reference.',
    ], $extra), TopUps::key($key));
}

function suspendedFrom(CustomerStatus $before): Customer
{
    return Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id, before: $before)->create();
}

it('credits an active customer by hand in one balanced, audited entry', function () {
    $customer = Customer::factory()->verified()->create(['display_ref' => '008842']);

    $res = byHand($this, $customer)
        ->assertCreated()
        ->assertJsonPath('data.origin', 'by_hand')
        ->assertJsonPath('data.status', 'credited')
        ->assertJsonPath('data.method', 'vodafone_cash')
        ->assertJsonPath('data.claimed_amount', null)
        ->assertJsonPath('data.credited_amount', '15000.0000')
        ->assertJsonPath('data.reference', 'DAHAB-008842')
        ->assertJsonPath('data.credit_note', 'Phone matches 01x…8842, no reference.')
        ->assertJsonPath('data.credited_by.id', $this->finance->staff_id);

    $topUp = TopUp::query()->sole();
    $txn = LedgerTransaction::query()->findOrFail($topUp->ledger_txn_id);

    expect($topUp->origin)->toBe(TopUpOrigin::BY_HAND)
        ->and($res->json('data.number'))->toBe('TOP-'.$topUp->topup_no)
        ->and($txn->event_kind)->toBe(LedgerEventKind::TOPUP)
        ->and($txn->staff_id)->toBe($this->finance->staff_id)
        ->and($txn->memo)->toBe("Top-up TOP-{$topUp->topup_no} · Vodafone Cash · credited by hand")
        ->and(TopUps::available($customer))->toBe('15000.0000')
        ->and(bccomp(TopUps::globalSum(), '0', 4))->toBe(0);

    $audit = AuditLog::query()->where('action', 'topup.credited_by_hand')->sole();
    expect($audit->actor_staff_id)->toBe($this->finance->staff_id)
        ->and($audit->reason)->toBe('Phone matches 01x…8842, no reference.')
        ->and($audit->after_json)->toMatchArray([
            'customer_id' => $customer->customer_id, 'customer_status' => 'active', 'method' => 'vodafone_cash',
            'credited_amount' => '15000.0000', 'ledger_txn_id' => $txn->ledger_txn_id, 'arrival_reference' => null,
        ]);

    Notification::assertSentTo($customer, TopUpCreditedNotification::class);
});

it('credits a customer suspended from active only with the arrival reference, and keeps the customer side closed', function () {
    Storage::fake('identity_private');
    $customer = suspendedFrom(CustomerStatus::ACTIVE);

    byHand($this, $customer)->assertStatus(422)->assertJsonValidationErrors(['arrival_reference']);
    expect(TopUp::query()->count())->toBe(0)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(0);

    byHand($this, $customer, ['arrival_reference' => 'VF-TXN-55120'])
        ->assertCreated()->assertJsonPath('data.arrival_reference', 'VF-TXN-55120');

    expect(TopUps::available($customer))->toBe('15000.0000')
        ->and(AuditLog::query()->where('action', 'topup.credited_by_hand')->sole()->after_json)
        ->toMatchArray(['customer_status' => 'suspended', 'arrival_reference' => 'VF-TXN-55120']);

    // FR-018a: the staff-side allowance never opens the customer side.
    $token = TopUps::customerToken($customer);
    $this->withToken($token)->getJson('/api/v1/customer/me/wallet/topup-methods')
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
    $this->withToken($token)->postJson('/api/v1/customer/me/wallet/topups', ['amount' => '1', 'receiving_account_id' => $this->vf->receiving_account_id], TopUps::key())
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
    TopUps::uploadReceipt($this, $customer)->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('refuses customers who are not verified, writing nothing', function (Customer $customer) {

    byHand($this, $customer, ['arrival_reference' => 'X'])
        ->assertForbidden()->assertJsonPath('code', 'verification_required');

    expect(TopUp::query()->count())->toBe(0)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'topup.credited_by_hand')->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'awaiting verification' => fn () => Customer::factory()->pendingVerification()->create(),
    'rejected' => fn () => Customer::factory()->rejected()->create(),
    'suspended while waiting' => fn () => suspendedFrom(CustomerStatus::PENDING_VERIFICATION),
    'suspended after rejection' => fn () => suspendedFrom(CustomerStatus::REJECTED),
]);

it('validates the request', function (array $extra, string $field) {
    $customer = Customer::factory()->verified()->create();

    byHand($this, $customer, $extra)->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(TopUp::query()->count())->toBe(0);
})->with([
    'no note' => [['note' => '  '], 'note'],
    'unknown customer' => [['customer_id' => '00000000-0000-4000-8000-000000000000'], 'customer_id'],
    'bad amount' => [['amount' => '1.005'], 'amount'],
    'unknown account' => [['receiving_account_id' => 999999], 'receiving_account_id'],
    'long reference' => [['arrival_reference' => str_repeat('x', 101)], 'arrival_reference'],
]);

it('accepts an inactive receiving account (the money already arrived there)', function () {
    $customer = Customer::factory()->verified()->create();
    $old = ReceivingAccount::factory()->bankTransfer()->inactive()->create();

    byHand($this, $customer, ['receiving_account_id' => $old->receiving_account_id])
        ->assertCreated()->assertJsonPath('data.method', 'bank_transfer');
});

it('replays the same credit for the same key and credits once', function () {
    $customer = Customer::factory()->verified()->create();
    $key = (string) Str::uuid();

    byHand($this, $customer, [], $key)->assertCreated();
    byHand($this, $customer, [], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(TopUp::query()->count())->toBe(1)
        ->and(TopUps::available($customer))->toBe('15000.0000');
});

it('refuses the COO', function () {
    $customer = Customer::factory()->verified()->create();
    TopUps::actAsStaff($this, SeedRole::COO, founder: true);

    byHand($this, $customer)->assertForbidden()->assertJsonPath('code', 'permission_denied');

    expect(TopUp::query()->count())->toBe(0);
});
