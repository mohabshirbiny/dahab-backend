<?php

use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US1 scenarios 1, 3, 4; FR-004, FR-005, FR-008, FR-018a; contract
// GET /customer/me/wallet/topup-methods.

const TOPUP_METHODS_URL = '/api/v1/customer/me/wallet/topup-methods';

it('shows the reference and the active accounts grouped by method in display order', function () {
    $customer = Customer::factory()->verified()->create(['display_ref' => '004417']);
    $bank = ReceivingAccount::factory()->bankTransfer()->create(['iban' => 'EG'.str_repeat('1', 27)]);
    $instaSecond = ReceivingAccount::factory()->instapay()->create(['sort_order' => 2, 'label' => 'Second', 'instapay_address' => 'two@instapay']);
    $instaFirst = ReceivingAccount::factory()->instapay()->create(['sort_order' => 1, 'label' => 'First', 'account_holder' => 'Dahab Trading']);

    $res = $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_METHODS_URL)->assertOk();

    $res->assertJsonPath('data.reference', 'DAHAB-004417')
        ->assertJsonPath('data.methods.0.method', 'bank_transfer')
        ->assertJsonPath('data.methods.0.accounts.0.id', $bank->receiving_account_id)
        ->assertJsonPath('data.methods.0.accounts.0.details', [
            ['key' => 'bank_name', 'value' => 'Test Bank'],
            ['key' => 'account_holder', 'value' => 'Dahab Test'],
            ['key' => 'account_number', 'value' => '0000 0000 0000'],
            ['key' => 'iban', 'value' => 'EG'.str_repeat('1', 27)],
        ])
        ->assertJsonPath('data.methods.1.method', 'instapay')
        ->assertJsonPath('data.methods.1.accounts.0.id', $instaFirst->receiving_account_id)
        ->assertJsonPath('data.methods.1.accounts.0.details', [
            ['key' => 'instapay_address', 'value' => 'dahab.test@instapay'],
            ['key' => 'account_holder', 'value' => 'Dahab Trading'],
        ])
        ->assertJsonPath('data.methods.1.accounts.0.daily_limit', '70000.0000')
        ->assertJsonPath('data.methods.1.accounts.0.provider_fee_percent', '0.500')
        ->assertJsonPath('data.methods.1.accounts.0.note', 'The fee is charged by InstaPay, not by Dahab.')
        ->assertJsonPath('data.methods.1.accounts.1.id', $instaSecond->receiving_account_id)
        ->assertJsonCount(2, 'data.methods');
});

it('hides inactive accounts and methods without an active account', function () {
    $customer = Customer::factory()->verified()->create();
    ReceivingAccount::factory()->instapay()->create();
    ReceivingAccount::factory()->vodafoneCash()->inactive()->create();

    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_METHODS_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data.methods')
        ->assertJsonPath('data.methods.0.method', 'instapay')
        ->assertJsonCount(1, 'data.methods.0.accounts');
});

it('refuses customers who are not verified', function (string $state) {
    $customer = Customer::factory()->{$state}()->create();

    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_METHODS_URL)
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
})->with(['pendingVerification', 'rejected']);

it('refuses a suspended customer the receiving details', function () {
    $customer = Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create();
    ReceivingAccount::factory()->instapay()->create();

    $this->bearer(TopUps::customerToken($customer))->getJson(TOPUP_METHODS_URL)
        ->assertForbidden()
        ->assertJsonPath('code', 'account_suspended')
        ->assertJsonMissingPath('data');
});

it('requires a signed-in customer', function () {
    $this->getJson(TOPUP_METHODS_URL)->assertUnauthorized();
});
