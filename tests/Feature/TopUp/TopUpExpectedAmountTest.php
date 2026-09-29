<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\TopUp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009, post-implementation decision (2026-09-30): after "I've sent the
// transfer", both apps show what should arrive after the provider fee shown on
// the account. Display only: staff still credit what actually arrives.

function feeNotice(string $claim, ?string $feePercent, string $status = 'pending'): TopUp
{
    $account = ReceivingAccount::factory()->vodafoneCash()->create(['provider_fee_percent' => $feePercent]);
    $factory = TopUp::factory()->state(['method' => 'vodafone_cash', 'notice_account_id' => $account->receiving_account_id, 'claimed_amount' => $claim]);

    return match ($status) {
        'credited' => $factory->credited()->create(),
        'on_hold' => $factory->onHold()->create(),
        default => $factory->create(),
    };
}

it('estimates what arrives after the provider fee, rounded half-up to piastres', function (string $claim, ?string $fee, ?string $expected) {
    expect(feeNotice($claim, $fee)->expectedAmount())->toBe($expected);
})->with([
    '1% of 10,000' => ['10000.00', '1.000', '9900.0000'],
    '0.5% of 20,000' => ['20000.00', '0.500', '19900.0000'],
    'half a piastre rounds up' => ['101.00', '0.500', '100.4900'],
    'no fee on the account' => ['10000.00', null, null],
    'a zero fee' => ['10000.00', '0.000', null],
]);

it('shows no estimate once the notice is closed, and keeps it while on hold', function () {
    expect(feeNotice('10000.00', '1.000', 'credited')->expectedAmount())->toBeNull()
        ->and(feeNotice('10000.00', '1.000', 'on_hold')->expectedAmount())->toBe('9900.0000');
});

it('sends the estimate to the customer and to staff, with the fee it came from', function () {
    $topUp = feeNotice('10000.00', '1.000');
    $customer = Customer::query()->findOrFail($topUp->customer_id);

    $this->withToken(TopUps::customerToken($customer))->getJson('/api/v1/customer/me/wallet/topups')
        ->assertOk()
        ->assertJsonPath('data.0.claimed_amount', '10000.0000')
        ->assertJsonPath('data.0.expected_amount', '9900.0000');

    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->getJson("/api/v1/dashboard/topups/{$topUp->topup_id}")
        ->assertOk()
        ->assertJsonPath('data.expected_amount', '9900.0000')
        ->assertJsonPath('data.notice_account.provider_fee_percent', '1.000');
});

it('still requires a note when staff credit the estimate instead of the claim', function () {
    $topUp = feeNotice('10000.00', '1.000');
    TopUps::actAsStaff($this, SeedRole::FINANCE);

    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/match", ['amount' => '9900', 'receiving_account_id' => $topUp->notice_account_id], TopUps::key())
        ->assertStatus(422)->assertJsonValidationErrors(['note']);
});

// The fee is a snapshot taken when the notice is filed: a later change to the
// account's fee never moves the estimate of a notice that already exists.

it('snapshots the account fee when the customer files the notice', function () {
    $account = ReceivingAccount::factory()->vodafoneCash()->create(['provider_fee_percent' => '1.000']);
    $customer = Customer::factory()->verified()->create();

    $this->withToken(TopUps::customerToken($customer))
        ->postJson('/api/v1/customer/me/wallet/topups', ['amount' => '10000', 'receiving_account_id' => $account->receiving_account_id], TopUps::key())
        ->assertCreated()
        ->assertJsonPath('data.expected_amount', '9900.0000');

    expect(TopUp::query()->sole()->notice_fee_percent)->toBe('1.000');
});

it('keeps the estimate of an existing notice when the account fee changes later', function (?string $newFee) {
    $topUp = feeNotice('10000.00', '1.000');
    $topUp->noticeAccount->update(['provider_fee_percent' => $newFee]);
    $customer = Customer::query()->findOrFail($topUp->customer_id);

    expect($topUp->fresh()->expectedAmount())->toBe('9900.0000');

    $this->withToken(TopUps::customerToken($customer))->getJson('/api/v1/customer/me/wallet/topups')
        ->assertOk()->assertJsonPath('data.0.expected_amount', '9900.0000');

    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->getJson("/api/v1/dashboard/topups/{$topUp->topup_id}")
        ->assertOk()
        ->assertJsonPath('data.expected_amount', '9900.0000')
        ->assertJsonPath('data.notice_fee_percent', '1.000')
        ->assertJsonPath('data.notice_account.provider_fee_percent', $newFee);
})->with([
    'raised' => ['2.500'],
    'lowered' => ['0.500'],
    'removed' => [null],
]);

it('gives a new notice the new fee, and none to a notice filed when there was no fee', function () {
    $account = ReceivingAccount::factory()->vodafoneCash()->create(['provider_fee_percent' => null]);
    $before = TopUp::factory()->state(['method' => 'vodafone_cash', 'notice_account_id' => $account->receiving_account_id, 'claimed_amount' => '10000.00'])->create();
    $account->update(['provider_fee_percent' => '2.000']);
    $after = TopUp::factory()->state(['method' => 'vodafone_cash', 'notice_account_id' => $account->receiving_account_id, 'claimed_amount' => '10000.00'])->create();

    expect($before->notice_fee_percent)->toBeNull()
        ->and($before->expectedAmount())->toBeNull()
        ->and($after->notice_fee_percent)->toBe('2.000')
        ->and($after->expectedAmount())->toBe('9800.0000');
});

it('credits what arrived, whatever the fee snapshot says', function () {
    $topUp = feeNotice('10000.00', '1.000');
    $customer = Customer::query()->findOrFail($topUp->customer_id);
    $before = TopUps::available($customer);
    TopUps::actAsStaff($this, SeedRole::FINANCE);

    $this->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/match", ['amount' => '9875.50', 'receiving_account_id' => $topUp->notice_account_id, 'note' => 'Fee was higher than shown.'], TopUps::key())
        ->assertOk()
        ->assertJsonPath('data.credited_amount', '9875.5000')
        ->assertJsonPath('data.expected_amount', null)
        ->assertJsonPath('data.notice_fee_percent', '1.000');

    expect(bcsub(TopUps::available($customer), $before, 4))->toBe('9875.5000');
});

it('keeps a hand credit without a fee snapshot', function () {
    $topUp = TopUp::factory()->byHand()->create();

    $state = null;
    try {
        DB::transaction(fn () => DB::table('topup')->insert([
            'customer_id' => $topUp->customer_id, 'origin' => 'by_hand', 'method' => 'instapay', 'reference' => 'DAHAB-X',
            'notice_fee_percent' => '1.000', 'status' => 'credited', 'credited_amount' => '1.00', 'receiving_account_id' => $topUp->receiving_account_id,
            'credit_note' => 'n', 'credited_by' => $topUp->credited_by, 'credited_at' => now(), 'ledger_txn_id' => $topUp->ledger_txn_id,
        ]));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    // 23514: the topup_fee_on_notice CHECK refuses it (before any unique index is consulted).
    expect($topUp->notice_fee_percent)->toBeNull()->and($state)->toBe('23514');
});
