<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 US4, FR-002–FR-004, FR-014: Dahab's receiving accounts.

const RA_URL = '/api/v1/dashboard/receiving-accounts';

beforeEach(function () {
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
});

it('adds an account per method and records it', function (array $body, array $expected) {
    $res = $this->postJson(RA_URL, $body)->assertCreated();

    foreach ($expected as $path => $value) {
        $res->assertJsonPath("data.{$path}", $value);
    }
    $res->assertJsonPath('data.updated_by.id', $this->finance->staff_id);

    $audit = AuditLog::query()->where('action', 'receiving_account.created')->sole();
    expect($audit->actor_staff_id)->toBe($this->finance->staff_id)
        ->and($audit->entity_type)->toBe('receiving_account')
        ->and($audit->after_json)->toMatchArray(['receiving_account_id' => $res->json('data.id'), 'method' => $body['method'], 'label' => $body['label']]);
})->with([
    'bank transfer' => [
        ['method' => 'bank_transfer', 'label' => 'CIB main', 'bank_name' => 'CIB', 'account_holder' => 'Dahab Trading', 'account_number' => '1000 4417 2026', 'iban' => 'EG380019000500000000263180002'],
        ['method' => 'bank_transfer', 'bank_name' => 'CIB', 'iban' => 'EG380019000500000000263180002', 'instapay_address' => null, 'is_active' => true],
    ],
    'instapay' => [
        ['method' => 'instapay', 'label' => 'InstaPay', 'instapay_address' => 'dahab@instapay', 'daily_limit' => '70000', 'provider_fee_percent' => '0.5', 'customer_note' => 'The fee is charged by InstaPay.'],
        ['method' => 'instapay', 'instapay_address' => 'dahab@instapay', 'daily_limit' => '70000.0000', 'provider_fee_percent' => '0.500'],
    ],
    'vodafone cash (other methods\' details dropped)' => [
        ['method' => 'vodafone_cash', 'label' => 'VF', 'wallet_number' => '01044172026', 'bank_name' => 'ignored'],
        ['method' => 'vodafone_cash', 'wallet_number' => '01044172026', 'bank_name' => null],
    ],
]);

it('validates the details each method needs', function (array $body, string $field) {
    $this->postJson(RA_URL, $body)->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(ReceivingAccount::query()->count())->toBe(0);
})->with([
    'bank without number' => [['method' => 'bank_transfer', 'label' => 'x', 'bank_name' => 'B', 'account_holder' => 'H'], 'account_number'],
    'bad wallet number' => [['method' => 'vodafone_cash', 'label' => 'x', 'wallet_number' => '12345'], 'wallet_number'],
    'bad iban' => [['method' => 'bank_transfer', 'label' => 'x', 'bank_name' => 'B', 'account_holder' => 'H', 'account_number' => '123456', 'iban' => 'EG12'], 'iban'],
    'instapay without address' => [['method' => 'instapay', 'label' => 'x'], 'instapay_address'],
    'long label' => [['method' => 'instapay', 'label' => str_repeat('x', 81), 'instapay_address' => 'a@instapay'], 'label'],
    'long note' => [['method' => 'instapay', 'label' => 'x', 'instapay_address' => 'a@instapay', 'customer_note' => str_repeat('x', 301)], 'customer_note'],
    'fee over 100' => [['method' => 'instapay', 'label' => 'x', 'instapay_address' => 'a@instapay', 'provider_fee_percent' => '101'], 'provider_fee_percent'],
    'unknown method' => [['method' => 'paypal', 'label' => 'x'], 'method'],
]);

it('changes an account, deactivates it, and records before and after', function () {
    $account = ReceivingAccount::factory()->instapay()->create(['label' => 'Old']);
    $customer = Customer::factory()->verified()->create();

    $this->patchJson(RA_URL."/{$account->receiving_account_id}", ['label' => 'New', 'instapay_address' => 'new@instapay', 'sort_order' => 3])
        ->assertOk()
        ->assertJsonPath('data.label', 'New')
        ->assertJsonPath('data.instapay_address', 'new@instapay')
        ->assertJsonPath('data.sort_order', 3);

    $audit = AuditLog::query()->where('action', 'receiving_account.updated')->sole();
    expect($audit->before_json)->toMatchArray(['label' => 'Old', 'instapay_address' => 'dahab.test@instapay'])
        ->and($audit->after_json)->toMatchArray(['label' => 'New', 'instapay_address' => 'new@instapay']);

    $customerToken = TopUps::customerToken($customer);
    $this->withToken($customerToken)->getJson('/api/v1/customer/me/wallet/topup-methods')
        ->assertOk()->assertJsonPath('data.methods.0.accounts.0.details.0.value', 'new@instapay');

    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->patchJson(RA_URL."/{$account->receiving_account_id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $this->withToken($customerToken)->getJson('/api/v1/customer/me/wallet/topup-methods')
        ->assertOk()->assertJsonCount(0, 'data.methods');
});

it('never changes the method or clears a detail the method needs', function () {
    $account = ReceivingAccount::factory()->instapay()->create();

    $this->patchJson(RA_URL."/{$account->receiving_account_id}", ['method' => 'vodafone_cash'])
        ->assertStatus(422)->assertJsonValidationErrors(['method']);
    $this->patchJson(RA_URL."/{$account->receiving_account_id}", ['instapay_address' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['instapay_address']);

    expect($account->fresh()->method->value)->toBe('instapay');
});

it('lists active accounts, or all of them on request', function () {
    ReceivingAccount::factory()->instapay()->create();
    ReceivingAccount::factory()->vodafoneCash()->inactive()->create();

    $this->getJson(RA_URL)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(RA_URL.'?include_inactive=1')->assertOk()->assertJsonCount(2, 'data');
});

it('has no delete route', function () {
    $account = ReceivingAccount::factory()->create();

    $this->deleteJson(RA_URL."/{$account->receiving_account_id}")->assertStatus(405);

    expect(ReceivingAccount::query()->count())->toBe(1);
});

it('refuses the COO everywhere, and lets a match-only role read but not change', function () {
    $account = ReceivingAccount::factory()->create();

    TopUps::actAsStaff($this, SeedRole::COO, founder: true);
    $this->getJson(RA_URL)->assertForbidden();
    $this->postJson(RA_URL, ['method' => 'instapay', 'label' => 'x', 'instapay_address' => 'a@instapay'])->assertForbidden();
    $this->patchJson(RA_URL."/{$account->receiving_account_id}", ['label' => 'x'])->assertForbidden();

    Role::findByName(SeedRole::OPERATIONS->value, 'staff')->givePermissionTo('topup.match');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    $this->getJson(RA_URL)->assertOk();
    $this->patchJson(RA_URL."/{$account->receiving_account_id}", ['label' => 'x'])->assertForbidden()->assertJsonPath('code', 'permission_denied');
});
