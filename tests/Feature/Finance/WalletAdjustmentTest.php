<?php

use App\Enums\SeedRole;
use App\Enums\WalletEvent;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\LedgerTransaction;
use App\Models\WalletAdjustment;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Finance;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 015 FR-005–FR-008 (Clarification Q2): the CEO's correction of a wallet
// — a credit or debit of available against external_equity, kind `reversal`
// with no reversed entry, a wallet_adjustment row; never below zero.

beforeEach(fn () => Notification::fake());

it('credits a wallet: one balanced reversal entry, its row, the audit and the message without the reason', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $customer = Finance::customer('1000');
    $equity = Finance::equity();
    $ceo = Finance::staff($this, SeedRole::CEO);

    Finance::adjust($this, $customer, 'credit', '500')->assertCreated()
        ->assertJsonPath('data.direction', 'credit')->assertJsonPath('data.amount', '500.0000')
        ->assertJsonPath('data.customer.display_ref', $customer->display_ref)
        ->assertJsonPath('data.customer_status', 'active')->assertJsonPath('data.adjusted_by.id', $ceo->staff_id);

    $row = DatabaseActor::elevate('maintenance', fn () => WalletAdjustment::query()->sole());
    $txn = DatabaseActor::elevate('maintenance', fn () => LedgerTransaction::query()->findOrFail($row->ledger_txn_id));
    expect(Finance::available($customer))->toBe('1500.0000')
        ->and(Finance::equity())->toBe(bcsub($equity, '500', 4))
        ->and(Finance::globalSum())->toBe('0.0000')
        ->and($txn->event_kind->value)->toBe('reversal')->and($txn->reverses_txn_id)->toBeNull()
        ->and($txn->staff_id)->toBe($ceo->staff_id)->and($txn->memo)->toBe('Correcting a top-up entered twice.');

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'wallet.adjusted')->sole());
    expect($audit->actor_staff_id)->toBe($ceo->staff_id)->and($audit->after_json['direction'])->toBe('credit')
        ->and($audit->reason)->toBe('Correcting a top-up entered twice.');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $customer->customer_id
        && $j->notification->event === WalletEvent::WALLET_ADJUSTED && $j->notification->reason === null);
});

it('debits within available and refuses a debit beyond it, writing nothing', function () {
    $customer = Finance::customer('1000');
    Finance::staff($this, SeedRole::CEO);

    Finance::adjust($this, $customer, 'debit', '300')->assertCreated();
    expect(Finance::available($customer))->toBe('700.0000');

    Finance::adjust($this, $customer, 'debit', '700.0001')->assertStatus(409)->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('details.available', '700.0000');
    expect(Finance::available($customer))->toBe('700.0000')
        ->and(DatabaseActor::elevate('maintenance', fn () => WalletAdjustment::query()->count()))->toBe(1);
});

it('shows the correction in the customer\'s own history and statement', function () {
    $customer = Finance::customer('1000');
    Finance::staff($this, SeedRole::CEO);
    Finance::adjust($this, $customer, 'credit', '40')->assertCreated();

    app('auth')->forgetGuards();
    $this->withToken(TopUps::customerToken($customer))->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()
        ->assertJsonPath('data.0.kind', 'reversal')->assertJsonPath('data.0.available_change', '40.0000');
});

it('validates the body and replays the same key once', function () {
    $customer = Finance::customer('100');
    Finance::staff($this, SeedRole::CEO);

    Finance::adjust($this, $customer, 'sideways', '5')->assertStatus(422)->assertJsonValidationErrors('direction');
    Finance::adjust($this, $customer, 'credit', '0')->assertStatus(422)->assertJsonValidationErrors('amount');
    Finance::adjust($this, $customer, 'credit', '1.00001')->assertStatus(422)->assertJsonValidationErrors('amount');
    Finance::adjust($this, $customer, 'credit', '5', ['reason' => 'short'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/dashboard/customers/'.Str::uuid().'/wallet-adjustments',
        ['direction' => 'credit', 'amount' => '5', 'reason' => 'Correcting a wrong amount.'], Finance::key())->assertNotFound();

    $key = (string) Str::uuid();
    Finance::adjust($this, $customer, 'credit', '5', key: $key)->assertCreated();
    Finance::adjust($this, $customer, 'credit', '5', key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(Finance::available($customer))->toBe('105.0000');
});

it('is CEO only by seed; another staff member may be given wallet.adjust', function () {
    $customer = Finance::customer('100');
    foreach ([SeedRole::FINANCE, SeedRole::COO, SeedRole::OPERATIONS] as $role) {
        Finance::staff($this, $role);
        Finance::adjust($this, $customer, 'credit', '5')->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $finance->givePermissionTo('wallet.adjust');
    Finance::adjust($this, $customer, 'credit', '5')->assertCreated();
});

it('lists adjustments newest first, filtered, for wallet.adjust or wallet.view', function () {
    $a = Finance::customer('100');
    $b = Finance::customer('100');
    Finance::staff($this, SeedRole::CEO);
    Finance::adjust($this, $a, 'credit', '1')->assertCreated();
    Finance::adjust($this, $b, 'debit', '2')->assertCreated();

    $this->getJson('/api/v1/dashboard/wallet-adjustments')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.amount', '2.0000')->assertJsonPath('data.0.direction', 'debit');
    $this->getJson('/api/v1/dashboard/wallet-adjustments?customer_id='.$a->customer_id)->assertOk()->assertJsonCount(1, 'data');

    Finance::staff($this, SeedRole::FINANCE);
    $this->getJson('/api/v1/dashboard/wallet-adjustments')->assertOk()->assertJsonCount(2, 'data');
    Finance::staff($this, SeedRole::COO);
    $this->getJson('/api/v1/dashboard/wallet-adjustments')->assertForbidden();
});
