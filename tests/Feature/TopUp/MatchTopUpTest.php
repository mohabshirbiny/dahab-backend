<?php

use App\Enums\AccountKind;
use App\Enums\CustomerStatus;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Enums\TopUpStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\ReceivingAccount;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US2 scenarios 1–6, FR-016, FR-017, FR-019, FR-020 (M1): POST
// /dashboard/topups/{topup}/match. The first money-moving endpoint: these
// HTTP-boundary tests close the spec 008 Constitution Principle V waiver.

beforeEach(function () {
    Notification::fake();
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->customer = Customer::factory()->verified()->create(['display_ref' => '004417']);
    $this->insta = ReceivingAccount::factory()->instapay()->create();
    $this->topUp = TopUp::factory()->create([
        'customer_id' => $this->customer->customer_id,
        'notice_account_id' => $this->insta->receiving_account_id,
        'claimed_amount' => '20000.00',
    ]);
});

function matchCall($test, TopUp $topUp, array $body, ?string $key = null)
{
    return $test->postJson("/api/v1/dashboard/topups/{$topUp->topup_id}/match", $body, TopUps::key($key));
}

function matchAudits(TopUp $topUp)
{
    return AuditLog::query()->where('action', 'topup.matched')->where('entity_id', $topUp->topup_id)->get();
}

it('credits what arrived in one balanced, attributed ledger entry', function () {
    $bankBefore = TopUps::bankCash();

    $res = matchCall($this, $this->topUp, ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertOk()
        ->assertJsonPath('data.status', 'credited')
        ->assertJsonPath('data.credited_amount', '20000.0000')
        ->assertJsonPath('data.credited_by.id', $this->finance->staff_id)
        ->assertJsonPath('data.allowed_actions', []);

    $this->topUp->refresh();
    $txn = LedgerTransaction::query()->findOrFail($this->topUp->ledger_txn_id);
    $postings = DB::table('ledger_posting')->where('ledger_txn_id', $txn->ledger_txn_id)->orderBy('posting_id')->get(['account_id', 'amount']);

    expect($res->json('data.ledger_txn_id'))->toBe($txn->ledger_txn_id)
        ->and($this->topUp->status)->toBe(TopUpStatus::CREDITED)
        ->and($this->topUp->credited_by)->toBe($this->finance->staff_id)
        ->and((int) $this->topUp->receiving_account_id)->toBe($this->insta->receiving_account_id)
        ->and($txn->event_kind)->toBe(LedgerEventKind::TOPUP)
        ->and($txn->staff_id)->toBe($this->finance->staff_id)
        ->and($txn->customer_id)->toBeNull()
        ->and($txn->memo)->toBe("Top-up TOP-{$this->topUp->topup_no} · InstaPay · matched from notice DAHAB-004417")
        ->and($postings->map(fn ($p) => [$p->account_id, $p->amount])->all())->toBe([
            [Account::internal(AccountKind::BANK), '-20000.0000'],
            [Account::forCustomerKind($this->customer->customer_id, AccountKind::CUST_AVAILABLE), '20000.0000'],
        ])
        ->and(TopUps::available($this->customer))->toBe('20000.0000')
        ->and(bcsub(TopUps::bankCash(), $bankBefore, 4))->toBe('20000.0000')
        ->and(bccomp(TopUps::globalSum(), '0', 4))->toBe(0);

    $this->getJson('/api/v1/dashboard/wallets/overview')->assertOk()->assertJsonPath('data.system_total', '0.0000');

    $audit = matchAudits($this->topUp)->sole();
    expect($audit->actor_staff_id)->toBe($this->finance->staff_id)
        ->and($audit->entity_type)->toBe('topup')
        ->and($audit->before_json)->toMatchArray(['status' => 'pending'])
        ->and($audit->after_json)->toMatchArray([
            'status' => 'credited', 'number' => 'TOP-'.$this->topUp->topup_no, 'customer_ref' => '004417',
            'claimed_amount' => '20000.0000', 'credited_amount' => '20000.0000',
            'receiving_account_id' => $this->insta->receiving_account_id, 'ledger_txn_id' => $txn->ledger_txn_id,
            'customer_status' => 'active', 'arrival_reference' => null,
        ]);

    // The customer sees the credit in their history with the top-up number.
    $this->withToken(TopUps::customerToken($this->customer))->getJson('/api/v1/customer/me/wallet/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'topup')
        ->assertJsonPath('data.0.available_change', '20000.0000')
        ->assertJsonPath('data.0.reference', 'TOP-'.$this->topUp->topup_no);
});

it('matches a notice that is on hold', function () {
    $held = TopUp::factory()->onHold()->create(['customer_id' => $this->customer->customer_id, 'notice_account_id' => $this->insta->receiving_account_id]);

    matchCall($this, $held, ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertOk()->assertJsonPath('data.status', 'credited');
});

it('credits a different amount only with a note, keeping the claim', function () {
    matchCall($this, $this->topUp, ['amount' => '19900', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertStatus(422)->assertJsonValidationErrors(['note']);
    expect($this->topUp->fresh()->status)->toBe(TopUpStatus::PENDING)
        ->and(TopUps::available($this->customer))->toBe('0');

    matchCall($this, $this->topUp, ['amount' => '19900', 'receiving_account_id' => $this->insta->receiving_account_id, 'note' => 'InstaPay fee taken'])
        ->assertOk()
        ->assertJsonPath('data.credited_amount', '19900.0000')
        ->assertJsonPath('data.claimed_amount', '20000.0000')
        ->assertJsonPath('data.credit_note', 'InstaPay fee taken');

    expect(TopUps::available($this->customer))->toBe('19900.0000')
        ->and(matchAudits($this->topUp)->sole()->reason)->toBe('InstaPay fee taken');
});

it('checks the receiving account', function () {
    $bank = ReceivingAccount::factory()->bankTransfer()->create();
    $inactiveInsta = ReceivingAccount::factory()->instapay()->inactive()->create();

    matchCall($this, $this->topUp, ['amount' => '20000', 'receiving_account_id' => $bank->receiving_account_id])
        ->assertStatus(422)->assertJsonValidationErrors(['receiving_account_id']);
    matchCall($this, $this->topUp, ['amount' => '20000', 'receiving_account_id' => 999999])
        ->assertStatus(422)->assertJsonValidationErrors(['receiving_account_id']);

    // Money already arrived there, so an inactive account of the right method is fine.
    matchCall($this, $this->topUp, ['amount' => '20000', 'receiving_account_id' => $inactiveInsta->receiving_account_id])
        ->assertOk();
});

it('validates the amount', function (mixed $amount) {
    matchCall($this, $this->topUp, ['amount' => $amount, 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertStatus(422)->assertJsonValidationErrors(['amount']);
})->with(['0', '-1', '10.555', '100000000', null]);

it('replays the same match for the same key and credits once', function () {
    $key = (string) Str::uuid();
    $body = ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id];

    $first = matchCall($this, $this->topUp, $body, $key)->assertOk();
    matchCall($this, $this->topUp, $body, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($first->json('data.status'))->toBe('credited')
        ->and(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(1)
        ->and(TopUps::available($this->customer))->toBe('20000.0000');
});

it('refuses a second match with a new key', function () {
    $body = ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id];
    matchCall($this, $this->topUp, $body)->assertOk();

    matchCall($this, $this->topUp, $body)->assertStatus(409)->assertJsonPath('code', 'illegal_topup_transition');

    expect(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(1)
        ->and(TopUps::available($this->customer))->toBe('20000.0000');
});

it('refuses to match a rejected or cancelled notice', function (string $state) {
    $closed = TopUp::factory()->{$state}()->create(['customer_id' => $this->customer->customer_id, 'notice_account_id' => $this->insta->receiving_account_id]);

    matchCall($this, $closed, ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_topup_transition');

    expect(TopUps::available($this->customer))->toBe('0');
})->with(['rejected', 'cancelled']);

it('credits a customer suspended after the notice only with the arrival reference (M1)', function () {
    $this->customer->forceFill([
        'status' => CustomerStatus::SUSPENDED, 'status_before_suspension' => CustomerStatus::ACTIVE, 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => $this->finance->staff_id, 'suspended_at' => now(),
    ])->save();
    $body = ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id];

    matchCall($this, $this->topUp, $body)->assertStatus(422)->assertJsonValidationErrors(['arrival_reference']);
    expect($this->topUp->fresh()->status)->toBe(TopUpStatus::PENDING)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'topup')->count())->toBe(0);

    matchCall($this, $this->topUp, $body + ['arrival_reference' => 'IPN-883120'])
        ->assertOk()
        ->assertJsonPath('data.status', 'credited')
        ->assertJsonPath('data.arrival_reference', 'IPN-883120');

    expect(TopUps::available($this->customer))->toBe('20000.0000')
        ->and(matchAudits($this->topUp)->sole()->after_json)->toMatchArray(['customer_status' => 'suspended', 'arrival_reference' => 'IPN-883120']);
});

it('keeps the arrival reference optional for an active customer, and stores it', function () {
    matchCall($this, $this->topUp, ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id, 'arrival_reference' => 'FT-1'])
        ->assertOk()->assertJsonPath('data.arrival_reference', 'FT-1');
});

it('matches a notice of a customer later rejected on re-review without the arrival reference (L4)', function () {
    $this->customer->forceFill(['status' => CustomerStatus::REJECTED, 'is_verified' => false])->save();

    matchCall($this, $this->topUp, ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertOk()->assertJsonPath('data.status', 'credited');
});

it('requires an idempotency key', function () {
    $this->postJson("/api/v1/dashboard/topups/{$this->topUp->topup_id}/match", ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    expect($this->topUp->fresh()->status)->toBe(TopUpStatus::PENDING);
});

it('answers 404 for an unknown notice', function () {
    $this->postJson('/api/v1/dashboard/topups/'.Str::uuid().'/match', ['amount' => '1', 'receiving_account_id' => $this->insta->receiving_account_id], TopUps::key())
        ->assertNotFound();
});
