<?php

use App\Actions\Ledger\ReverseLedgerEntryAction;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\TopUp;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 015 FR-011 (Clarification): every posting on the bank account in a
// Cairo period — top-ups in (matched notice or by hand), withdrawals out,
// movements recorded by hand, reversals — with the opening, in, out, closing
// cash and the cash after each row.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

const BOOK_URL = '/api/v1/dashboard/bank-book';

it('lists every bank posting with its source and a running cash figure', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $customer = Customer::factory()->verified()->create();
    $account = ReceivingAccount::factory()->instapay()->create();

    $matched = TopUp::factory()->credited('20000.00', $finance->staff_id)->create(['customer_id' => $customer->customer_id,
        'notice_account_id' => $account->receiving_account_id, 'claimed_amount' => '20000.00']);
    $byHand = TopUp::factory()->byHand('5000.00', $finance->staff_id)->create(['customer_id' => $customer->customer_id]);
    $withdrawal = Withdrawals::requested($this, '4000', '4000');
    Finance::actAs($finance);
    Withdrawals::underReview($this, $withdrawal);
    Withdrawals::release($this, $withdrawal->refresh())->assertOk();
    Finance::actAs($finance);
    Finance::record($this, 'bank_charge', 'out', '250')->assertCreated();

    $res = $this->getJson(BOOK_URL)->assertOk();
    $rows = collect($res->json('data'));
    $closing = Finance::bankCash();

    expect($rows->pluck('source.type')->all())->toBe(['topup', 'topup', 'topup', 'withdrawal', 'bank_movement'])
        ->and($rows[0]['source']['how'])->toBe('matched_notice')->and($rows[0]['source']['reference'])->toBe($matched->reference)
        ->and($rows[0]['source']['receiving_account'])->toBe($account->label)
        ->and($rows[1]['source']['how'])->toBe('credited_by_hand')
        ->and($rows[3]['direction'])->toBe('out')->and($rows[3]['amount'])->toBe('4000.0000')
        ->and($rows[3]['source']['number'])->toBe($withdrawal->refresh()->number())->and($rows[3]['source']['bank_txn_number'])->toBe('FT2610031234')
        ->and($rows[4]['source']['kind'])->toBe('bank_charge')->and($rows[4]['actor']['name'])->toBe($finance->full_name)
        ->and($rows->last()['cash_after'])->toBe($closing)
        ->and($res->json('meta.summary.opening'))->toBe('0.0000')
        ->and($res->json('meta.summary.closing'))->toBe($closing)
        ->and(bcsub(bcadd($res->json('meta.summary.opening'), $res->json('meta.summary.in'), 4), $res->json('meta.summary.out'), 4))->toBe($closing);
    expect($byHand->refresh()->ledger_txn_id)->toBe($rows[1]['ledger_txn_id']);
});

it('opens a later period from the cash before it, shows a reversal, and pages by keyset', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    Finance::record($this, 'capital_in', 'in', '1000')->assertCreated();
    $txn = Finance::record($this, 'bank_charge', 'out', '100')->assertCreated()->json('data.ledger_txn_id');
    DB::transaction(fn () => app(ReverseLedgerEntryAction::class)->handle($txn, $finance->staff_id, 'Charge reversed by the bank.'));

    $res = $this->getJson(BOOK_URL.'?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $next = $this->getJson(BOOK_URL.'?per_page=2&cursor='.$res->json('meta.next_cursor'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source.type', 'reversal')->assertJsonPath('data.0.source.reverses_txn_id', $txn)
        ->assertJsonPath('data.0.direction', 'in')->assertJsonPath('data.0.cash_after', '1000.0000');

    $tomorrow = now('Africa/Cairo')->addDay()->toDateString();
    $this->getJson(BOOK_URL."?from={$tomorrow}&to={$tomorrow}")->assertOk()->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.summary.opening', '1000.0000')->assertJsonPath('meta.summary.closing', '1000.0000');
});

it('exports the period as CSV, audited, for bank.record or wallet.view only', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Finance::record($this, 'capital_in', 'in', '1000')->assertCreated();

    $csv = (string) $this->get(BOOK_URL.'/export')->assertOk()->getContent();
    expect($csv)->toContain('1000.0000')
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'bank.book_exported')->count()))->toBe(1);

    $viewer = Finance::staff($this, SeedRole::OPERATIONS);
    $this->getJson(BOOK_URL)->assertForbidden();
    $viewer->givePermissionTo('wallet.view');
    $this->getJson(BOOK_URL)->assertOk();
});
