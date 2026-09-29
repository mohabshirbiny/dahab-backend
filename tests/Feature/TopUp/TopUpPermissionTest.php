<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\TopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 FR-013, FR-014, SC-006, Part 1 §4.2: the top-up codes are seeded
// to CEO and Finance only — never the COO — and stay data (spec 002).

function topUpRoutes(TopUp $topUp, ReceivingAccount $account, Customer $customer): array
{
    $id = $topUp->topup_id;

    return [
        ['GET', '/api/v1/dashboard/topups', []],
        ['GET', '/api/v1/dashboard/topups/export', []],
        ['GET', "/api/v1/dashboard/topups/{$id}", []],
        ['GET', "/api/v1/dashboard/topups/{$id}/receipt", []],
        ['POST', "/api/v1/dashboard/topups/{$id}/match", ['amount' => '1', 'receiving_account_id' => $account->receiving_account_id]],
        ['POST', "/api/v1/dashboard/topups/{$id}/hold", ['note' => 'x']],
        ['POST', "/api/v1/dashboard/topups/{$id}/unhold", []],
        ['POST', "/api/v1/dashboard/topups/{$id}/reject", ['reason' => 'other', 'note' => 'x']],
        ['POST', '/api/v1/dashboard/topups', ['customer_id' => $customer->customer_id, 'amount' => '1', 'receiving_account_id' => $account->receiving_account_id, 'note' => 'x']],
    ];
}

it('refuses every top-up route to roles without the match permission, and records it', function (SeedRole $role, bool $founder) {
    $customer = Customer::factory()->verified()->create();
    $topUp = TopUp::factory()->withReceipt()->create(['customer_id' => $customer->customer_id]);
    $account = ReceivingAccount::factory()->create();
    $staff = TopUps::actAsStaff($this, $role, $founder);

    foreach (topUpRoutes($topUp, $account, $customer) as [$method, $uri, $body]) {
        $this->json($method, $uri, $body, TopUps::key())->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->count())->toBe(9)
        ->and($topUp->fresh()->status->value)->toBe('pending');
})->with([
    'COO (a founder)' => [SeedRole::COO, true],
    'Operations' => [SeedRole::OPERATIONS, false],
    'Verification' => [SeedRole::VERIFICATION, false],
]);

it('lets Finance and the CEO in', function (SeedRole $role, bool $founder) {
    TopUps::actAsStaff($this, $role, $founder);

    $this->getJson('/api/v1/dashboard/topups')->assertOk();
    $this->getJson('/api/v1/dashboard/receiving-accounts')->assertOk();
})->with([
    'Finance' => [SeedRole::FINANCE, false],
    'CEO' => [SeedRole::CEO, true],
]);

it('follows the permission data, not the role name', function () {
    TopUps::actAsStaff($this, SeedRole::FINANCE); // seeds the roles
    Role::findByName(SeedRole::OPERATIONS->value, 'staff')->givePermissionTo('topup.match');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    $this->getJson('/api/v1/dashboard/topups')->assertOk();
});

it('lists both codes in the Money group of the catalogue, with no COO seed', function () {
    TopUps::actAsStaff($this, SeedRole::CEO, founder: true);

    $codes = collect($this->getJson('/api/v1/dashboard/permissions')->assertOk()->json('data'))->keyBy('code');

    expect($codes['topup.match']['group'])->toBe('Money')
        ->and($codes['topup.match']['label'])->toBe('Match an incoming transfer')
        ->and($codes['topup.accounts.manage']['group'])->toBe('Money')
        ->and(Role::findByName(SeedRole::COO->value, 'staff')->hasPermissionTo('topup.match'))->toBeFalse()
        ->and(Role::findByName(SeedRole::COO->value, 'staff')->hasPermissionTo('topup.accounts.manage'))->toBeFalse()
        ->and(Role::findByName(SeedRole::FINANCE->value, 'staff')->hasPermissionTo('topup.match'))->toBeTrue();
});
