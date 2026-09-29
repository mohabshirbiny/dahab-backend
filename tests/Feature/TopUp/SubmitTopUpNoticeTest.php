<?php

use App\Enums\TopUpOrigin;
use App\Enums\TopUpStatus;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US1 scenarios 2–5; FR-007–FR-011; contract POST /customer/me/wallet/topups.

const TOPUP_SUBMIT_URL = '/api/v1/customer/me/wallet/topups';

beforeEach(function () {
    Storage::fake('identity_private');
    $this->customer = Customer::factory()->verified()->create(['display_ref' => '004417']);
    $this->insta = ReceivingAccount::factory()->instapay()->create();
});

function submitNotice($test, Customer $customer, array $body, ?string $key = null)
{
    app('auth')->forgetGuards();

    return $test->withToken(TopUps::customerToken($customer))->postJson(TOPUP_SUBMIT_URL, $body, TopUps::key($key));
}

it('records a pending notice without moving money', function () {
    $ledgerRows = DB::table('ledger_transaction')->count();

    $res = submitNotice($this, $this->customer, ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.method', 'instapay')
        ->assertJsonPath('data.reference', 'DAHAB-004417')
        ->assertJsonPath('data.claimed_amount', '20000.0000')
        ->assertJsonPath('data.credited_amount', null)
        ->assertJsonPath('data.has_receipt', false)
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.reject_reason', null);

    $topUp = TopUp::query()->sole();
    expect($res->json('data.id'))->toBe($topUp->topup_id)
        ->and($res->json('data.number'))->toBe('TOP-'.$topUp->topup_no)
        ->and($topUp->origin)->toBe(TopUpOrigin::NOTICE)
        ->and($topUp->status)->toBe(TopUpStatus::PENDING)
        ->and($topUp->customer_id)->toBe($this->customer->customer_id)
        ->and((int) $topUp->notice_account_id)->toBe($this->insta->receiving_account_id)
        ->and($topUp->reference)->toBe('DAHAB-004417')
        ->and($topUp->receipt_ref)->toBeNull()
        ->and(DB::table('ledger_transaction')->count())->toBe($ledgerRows)
        ->and(TopUps::available($this->customer))->toBe('0');
});

it('takes the method from the chosen account', function () {
    $bank = ReceivingAccount::factory()->bankTransfer()->create();

    submitNotice($this, $this->customer, ['amount' => '5000.50', 'receiving_account_id' => $bank->receiving_account_id])
        ->assertCreated()
        ->assertJsonPath('data.method', 'bank_transfer')
        ->assertJsonPath('data.claimed_amount', '5000.5000');
});

it('attaches an uploaded receipt, once', function (string $kind) {
    $file = $kind === 'pdf' ? TopUps::receiptPdf() : TopUps::receiptPng();
    $token = TopUps::uploadReceipt($this, $this->customer, $file)->assertCreated()->json('data.upload_token');

    submitNotice($this, $this->customer, ['amount' => '100', 'receiving_account_id' => $this->insta->receiving_account_id, 'receipt_upload_token' => $token])
        ->assertCreated()->assertJsonPath('data.has_receipt', true);

    $topUp = TopUp::query()->sole();
    expect($topUp->receipt_ref)->toStartWith("topup-receipts/{$this->customer->customer_id}/")
        ->and($topUp->receipt_mime)->toBe($kind === 'pdf' ? 'application/pdf' : 'image/png');
    Storage::disk('identity_private')->assertExists($topUp->receipt_ref);

    // Single use.
    submitNotice($this, $this->customer, ['amount' => '100', 'receiving_account_id' => $this->insta->receiving_account_id, 'receipt_upload_token' => $token])
        ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');
})->with(['png', 'pdf']);

it('refuses receipt tokens that are not this customer\'s top-up receipts', function () {
    $other = Customer::factory()->verified()->create();
    $othersToken = TopUps::uploadReceipt($this, $other)->assertCreated()->json('data.upload_token');

    $this->app['auth']->forgetGuards();
    $identityToken = $this->withToken(TopUps::customerToken($this->customer))
        ->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => TopUps::receiptPng()], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');

    foreach ([$othersToken, $identityToken, 'not-a-token'] as $token) {
        submitNotice($this, $this->customer, ['amount' => '100', 'receiving_account_id' => $this->insta->receiving_account_id, 'receipt_upload_token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');
    }

    expect(TopUp::query()->count())->toBe(0);
});

it('replays the same submission for the same key', function () {
    $key = (string) Str::uuid();
    $body = ['amount' => '20000', 'receiving_account_id' => $this->insta->receiving_account_id];

    $first = submitNotice($this, $this->customer, $body, $key)->assertCreated();
    $second = submitNotice($this, $this->customer, $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(TopUp::query()->count())->toBe(1);
});

it('requires an idempotency key', function () {
    $this->bearer(TopUps::customerToken($this->customer))
        ->postJson(TOPUP_SUBMIT_URL, ['amount' => '100', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    expect(TopUp::query()->count())->toBe(0);
});

it('validates the amount and the account', function (array $body, string $field) {
    $inactive = ReceivingAccount::factory()->vodafoneCash()->inactive()->create();
    $body = array_map(fn ($v) => match ($v) {
        'ACTIVE' => $this->insta->receiving_account_id,
        'INACTIVE' => $inactive->receiving_account_id,
        default => $v,
    }, $body);

    submitNotice($this, $this->customer, $body)->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(TopUp::query()->count())->toBe(0);
})->with([
    'zero' => [['amount' => '0', 'receiving_account_id' => 'ACTIVE'], 'amount'],
    'negative' => [['amount' => '-5', 'receiving_account_id' => 'ACTIVE'], 'amount'],
    '3 decimals' => [['amount' => '10.123', 'receiving_account_id' => 'ACTIVE'], 'amount'],
    'too large' => [['amount' => '100000000', 'receiving_account_id' => 'ACTIVE'], 'amount'],
    'missing amount' => [['receiving_account_id' => 'ACTIVE'], 'amount'],
    'missing account' => [['amount' => '100'], 'receiving_account_id'],
    'unknown account' => [['amount' => '100', 'receiving_account_id' => 999999], 'receiving_account_id'],
    'inactive account' => [['amount' => '100', 'receiving_account_id' => 'INACTIVE'], 'receiving_account_id'],
]);

it('refuses customers who are not verified', function (string $state) {
    $customer = Customer::factory()->{$state}()->create();

    submitNotice($this, $customer, ['amount' => '100', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertForbidden()->assertJsonPath('code', 'verification_required');

    expect(TopUp::query()->count())->toBe(0);
})->with(['pendingVerification', 'rejected']);

it('refuses a suspended customer', function () {
    $customer = Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create();

    submitNotice($this, $customer, ['amount' => '100', 'receiving_account_id' => $this->insta->receiving_account_id])
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');

    expect(TopUp::query()->count())->toBe(0);
});

it('throttles notices per customer', function () {
    config(['dahab-wallet.topups_per_minute' => 2]);

    submitNotice($this, $this->customer, ['amount' => '1', 'receiving_account_id' => $this->insta->receiving_account_id])->assertCreated();
    submitNotice($this, $this->customer, ['amount' => '2', 'receiving_account_id' => $this->insta->receiving_account_id])->assertCreated();
    submitNotice($this, $this->customer, ['amount' => '3', 'receiving_account_id' => $this->insta->receiving_account_id])->assertStatus(429);

    expect(TopUp::query()->count())->toBe(2);
});
