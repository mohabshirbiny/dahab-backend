<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\BankMovement;
use App\Models\LedgerTransaction;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Finance;

uses(RefreshDatabase::class);

// Spec 015 FR-009–FR-010 (Clarifications): money in or out of Dahab's bank
// outside the app — one balanced external_bank_movement entry bank ↔
// external_equity, or a record only for an own-account transfer.

beforeEach(fn () => Storage::fake('identity_private'));

it('records capital paid in: the bank\'s cash and equity move, a BM number, audited', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $cash = Finance::bankCash();
    $equity = Finance::equity();

    $res = Finance::record($this, 'capital_in', 'in', '500000', ['occurred_on' => now('Africa/Cairo')->subDay()->toDateString(),
        'reason' => 'From the founders, second round.'])->assertCreated()
        ->assertJsonPath('data.kind', 'capital_in')->assertJsonPath('data.direction', 'in')->assertJsonPath('data.amount', '500000.0000')
        ->assertJsonPath('data.has_proof', false)->assertJsonPath('data.posts_to_ledger', true)
        ->assertJsonPath('data.recorded_by.id', $finance->staff_id);

    $row = DatabaseActor::elevate('maintenance', fn () => BankMovement::query()->sole());
    $txn = DatabaseActor::elevate('maintenance', fn () => LedgerTransaction::query()->findOrFail($row->ledger_txn_id));
    expect($res->json('data.number'))->toBe('BM-'.$row->movement_no)
        ->and(Finance::bankCash())->toBe(bcadd($cash, '500000', 4))
        ->and(Finance::equity())->toBe(bcadd($equity, '500000', 4))
        ->and(Finance::globalSum())->toBe('0.0000')
        ->and($txn->event_kind->value)->toBe('external_bank_movement')->and($txn->staff_id)->toBe($finance->staff_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'bank.movement_recorded')->sole()->actor_staff_id))->toBe($finance->staff_id);
});

it('records an expense out with a proof, and streams the proof back, audited', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $cash = Finance::bankCash();
    $token = Finance::proofToken($this);

    $id = Finance::record($this, 'operating_expense', 'out', '14800', ['reason' => 'IGI inspection invoice, August.', 'proof_upload_token' => $token])
        ->assertCreated()->assertJsonPath('data.has_proof', true)->assertJsonPath('data.direction', 'out')->json('data.id');
    expect(Finance::bankCash())->toBe(bcsub($cash, '14800', 4));

    $this->get("/api/v1/dashboard/bank-movements/{$id}/proof")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'bank.movement_proof_viewed')->count()))->toBe(1);

    // The token is single use.
    Finance::record($this, 'bank_charge', 'out', '5', ['proof_upload_token' => $token])->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');

    $plain = Finance::record($this, 'bank_charge', 'out', '250')->assertCreated()->json('data.id');
    $this->getJson("/api/v1/dashboard/bank-movements/{$plain}/proof")->assertNotFound();
});

it('records an own-account transfer without any entry', function () {
    Finance::staff($this, SeedRole::FINANCE);
    $cash = Finance::bankCash();

    Finance::record($this, 'own_transfer', 'out', '100000', ['reason' => 'CIB to NBE, to pay suppliers from NBE.'])->assertCreated()
        ->assertJsonPath('data.posts_to_ledger', false);
    expect(Finance::bankCash())->toBe($cash)
        ->and(DatabaseActor::elevate('maintenance', fn () => BankMovement::query()->sole()->ledger_txn_id))->toBeNull();
});

it('accepts a statement date on a closed day: the entry posts now', function () {
    Finance::staff($this, SeedRole::FINANCE);
    $day = now('Africa/Cairo')->subDays(3)->toDateString();
    Finance::close($this, $day, Finance::bankCash())->assertOk()->assertJsonPath('data.close.is_locked', true);

    Finance::record($this, 'bank_charge', 'out', '250', ['occurred_on' => $day, 'reason' => 'Monthly fee, found late.'])->assertCreated()
        ->assertJsonPath('data.occurred_on', $day);
    $posted = DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction')->where('event_kind', 'external_bank_movement')->value('created_at'));
    expect(substr((string) $posted, 0, 10))->toBe(now('Africa/Cairo')->toDateString());
});

it('validates the body', function () {
    Finance::staff($this, SeedRole::FINANCE);

    Finance::record($this, 'rent', 'out', '5')->assertStatus(422)->assertJsonValidationErrors('kind');
    Finance::record($this, 'bank_charge', 'sideways', '5')->assertStatus(422)->assertJsonValidationErrors('direction');
    Finance::record($this, 'bank_charge', 'out', '0')->assertStatus(422)->assertJsonValidationErrors('amount');
    Finance::record($this, 'bank_charge', 'out', '-5')->assertStatus(422)->assertJsonValidationErrors('amount');
    Finance::record($this, 'other', 'out', '5', ['reason' => 'short'])->assertStatus(422)->assertJsonValidationErrors('reason');
    Finance::record($this, 'bank_charge', 'out', '5', ['occurred_on' => now('Africa/Cairo')->addDay()->toDateString()])
        ->assertStatus(422)->assertJsonValidationErrors('occurred_on');

    $key = (string) Str::uuid();
    Finance::record($this, 'bank_charge', 'out', '5', key: $key)->assertCreated();
    Finance::record($this, 'bank_charge', 'out', '5', key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(DatabaseActor::elevate('maintenance', fn () => BankMovement::query()->count()))->toBe(1);
});

it('lists the recorded movements by statement date with totals, exports them, and keeps the COO out', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Finance::record($this, 'capital_in', 'in', '1000')->assertCreated();
    Finance::record($this, 'bank_charge', 'out', '250')->assertCreated();
    Finance::record($this, 'bank_charge', 'out', '9', ['occurred_on' => now('Africa/Cairo')->subDays(45)->toDateString()])->assertCreated();

    $this->getJson('/api/v1/dashboard/bank-movements')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.totals.in', '1000.0000')->assertJsonPath('meta.totals.out', '250.0000');
    $this->getJson('/api/v1/dashboard/bank-movements?kind=bank_charge&from='.now('Africa/Cairo')->subDays(60)->toDateString())
        ->assertOk()->assertJsonCount(2, 'data');

    $csv = (string) $this->get('/api/v1/dashboard/bank-movements/export')->assertOk()->getContent();
    expect($csv)->toContain('Capital paid in')->toContain('1000.0000');
    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'bank.movements_exported')->count()))->toBe(1);

    Finance::staff($this, SeedRole::COO);
    $this->getJson('/api/v1/dashboard/bank-movements')->assertForbidden();
    Finance::record($this, 'bank_charge', 'out', '5')->assertForbidden();
});
