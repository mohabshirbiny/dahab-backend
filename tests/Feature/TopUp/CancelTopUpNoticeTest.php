<?php

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

// Spec 009 US1 scenario 7, FR-025, FR-025b, FR-018a (L2):
// POST /customer/me/wallet/topups/{topup}/cancel.

function cancelNotice($test, Customer $customer, TopUp|string $topUp, ?string $key = null)
{
    $id = $topUp instanceof TopUp ? $topUp->topup_id : $topUp;
    app('auth')->forgetGuards();

    return $test->withToken(TopUps::customerToken($customer))
        ->postJson("/api/v1/customer/me/wallet/topups/{$id}/cancel", [], TopUps::key($key));
}

it('cancels a pending notice without moving money', function () {
    $customer = Customer::factory()->verified()->create();
    $topUp = TopUp::factory()->create(['customer_id' => $customer->customer_id]);
    $ledgerRows = DB::table('ledger_transaction')->count();

    cancelNotice($this, $customer, $topUp)
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.can_cancel', false);

    $topUp->refresh();
    expect($topUp->status)->toBe(TopUpStatus::CANCELLED)
        ->and($topUp->cancelled_at)->not->toBeNull()
        ->and(DB::table('ledger_transaction')->count())->toBe($ledgerRows);
});

it('replays a repeated cancel with the same key', function () {
    $customer = Customer::factory()->verified()->create();
    $topUp = TopUp::factory()->create(['customer_id' => $customer->customer_id]);
    $key = (string) Str::uuid();

    $first = cancelNotice($this, $customer, $topUp, $key)->assertOk();
    cancelNotice($this, $customer, $topUp, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($first->json('data.status'))->toBe('cancelled');
});

it('refuses to cancel a notice that is no longer pending', function (string $state) {
    $customer = Customer::factory()->verified()->create();
    $topUp = TopUp::factory()->{$state}()->create(['customer_id' => $customer->customer_id]);

    cancelNotice($this, $customer, $topUp)->assertStatus(409)->assertJsonPath('code', 'illegal_topup_transition');

    expect($topUp->fresh()->status->value)->toBe($topUp->status->value);
})->with(['onHold', 'credited', 'rejected', 'cancelled']);

it('does not find another customer\'s notice', function () {
    $customer = Customer::factory()->verified()->create();
    $theirs = TopUp::factory()->create();

    cancelNotice($this, $customer, $theirs)->assertNotFound();
    cancelNotice($this, $customer, (string) Str::uuid())->assertNotFound();

    expect($theirs->fresh()->status)->toBe(TopUpStatus::PENDING);
});

it('lets a suspended customer cancel their pending notice but nothing else (L2)', function () {
    Storage::fake('identity_private');
    $customer = Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create();
    $topUp = TopUp::factory()->create(['customer_id' => $customer->customer_id]);
    $account = ReceivingAccount::factory()->create();

    cancelNotice($this, $customer, $topUp)->assertOk()->assertJsonPath('data.status', 'cancelled');

    $token = TopUps::customerToken($customer);
    $this->bearer($token)->getJson('/api/v1/customer/me/wallet/topup-methods')
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
    TopUps::uploadReceipt($this, $customer)->assertForbidden()->assertJsonPath('code', 'account_suspended');
    $this->bearer($token)->postJson('/api/v1/customer/me/wallet/topups', ['amount' => '100', 'receiving_account_id' => $account->receiving_account_id], TopUps::key())
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('refuses customers who are not verified', function () {
    $customer = Customer::factory()->pendingVerification()->create();
    $topUp = TopUp::factory()->create(['customer_id' => $customer->customer_id]);

    cancelNotice($this, $customer, $topUp)->assertForbidden()->assertJsonPath('code', 'verification_required');
});
