<?php

use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 FR-017 / FR-018: the Overview figures and the customer file panel.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
});

it('shows what customers hold, the bank\'s cash, the safety figure and a zero system total', function () {
    $mona = Customer::factory()->verified()->create();
    $omar = Customer::factory()->verified()->create();
    Ledger::topUp($mona, '1000');
    Ledger::topUp($omar, '250.5');
    Ledger::hold($mona, '400');

    $this->bearer(staffAccessToken($this->finance))->getJson('/api/v1/dashboard/wallets/overview')
        ->assertOk()
        ->assertExactJson(['data' => [
            'available' => '850.5000',
            'held' => '400.0000',
            'total_owed' => '1250.5000',
            'bank' => '1250.5000',
            'headroom' => '0.0000',
            'system_total' => '0.0000',
        ]]);
});

it('shows a positive headroom when Dahab holds more cash than it owes customers', function () {
    // Capital paid into the bank (external equity), not owed to any customer.
    Ledger::post(LedgerEventKind::EXTERNAL_BANK_MOVEMENT, null, [
        [Account::internal(AccountKind::BANK), '-5000'],
        [Account::internal(AccountKind::EXTERNAL_EQUITY), '5000'],
    ], staffId: $this->finance->staff_id);
    Ledger::topUp(Customer::factory()->verified()->create(), '100');

    $this->bearer(staffAccessToken($this->finance))->getJson('/api/v1/dashboard/wallets/overview')
        ->assertOk()
        ->assertJsonPath('data.bank', '5100.0000')
        ->assertJsonPath('data.total_owed', '100.0000')
        ->assertJsonPath('data.headroom', '5000.0000');
});

it('shows one customer\'s wallet to the customer file, without an audit entry', function () {
    $customer = Customer::factory()->verified()->create();
    Ledger::topUp($customer, '700');
    Ledger::hold($customer, '200');
    $before = AuditLog::query()->count();

    $this->bearer(staffAccessToken($this->finance))->getJson("/api/v1/dashboard/customers/{$customer->customer_id}/wallet")
        ->assertOk()
        ->assertExactJson(['data' => ['available' => '500.0000', 'held' => '200.0000', 'total' => '700.0000', 'currency' => 'EGP']]);

    expect(AuditLog::query()->count())->toBe($before)
        ->and(AuditLog::query()->where('action', AuditEvent::LEDGER_STATEMENT_VIEWED->value)->exists())->toBeFalse();
});

it('answers 404 for an unknown customer', function () {
    $this->bearer(staffAccessToken($this->finance))->getJson('/api/v1/dashboard/customers/01a0e932-511b-72d9-b702-512e081c3000/wallet')
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});
