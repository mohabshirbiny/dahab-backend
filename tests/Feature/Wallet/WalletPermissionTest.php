<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 008 FR-015 / FR-019 / SC-005: every staff wallet read needs
// wallet.view — CEO and Finance by default, never the COO — and a refusal
// is audited as a permission denial naming wallet.view.

function walletEndpoints(string $customerId): array
{
    return [
        '/api/v1/dashboard/wallets/overview',
        "/api/v1/dashboard/customers/{$customerId}/wallet",
        '/api/v1/dashboard/wallet-statement?view=customers&from=2026-09-01&to=2026-09-30',
        '/api/v1/dashboard/wallet-statement/export?view=dahab&from=2026-09-01&to=2026-09-30',
    ];
}

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->customer = Customer::factory()->verified()->create();
});

it('lets the CEO and Finance read every wallet endpoint', function (SeedRole $role) {
    $factory = Staff::factory()->role($role);
    $staff = ($role === SeedRole::CEO ? $factory->founder() : $factory)->create();

    foreach (walletEndpoints($this->customer->customer_id) as $url) {
        $this->bearer(staffAccessToken($staff))->get($url, ['Accept' => 'application/json'])->assertOk();
    }
})->with([SeedRole::CEO, SeedRole::FINANCE]);

it('refuses the COO, Operations and Verification, and audits each refusal', function (SeedRole $role) {
    $factory = Staff::factory()->role($role);
    $staff = ($role === SeedRole::COO ? $factory->founder() : $factory)->create();

    foreach (walletEndpoints($this->customer->customer_id) as $url) {
        $this->bearer(staffAccessToken($staff))->getJson($url)
            ->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    $denials = AuditLog::query()->where('action', AuditEvent::STAFF_PERMISSION_DENIED->value)->where('actor_staff_id', $staff->staff_id)->get();

    expect($denials)->toHaveCount(4)
        ->and($denials->every(fn ($row) => str_contains(json_encode($row->after_json), 'wallet.view')))->toBeTrue();
})->with([SeedRole::COO, SeedRole::OPERATIONS, SeedRole::VERIFICATION]);

it('refuses a customer token', function () {
    $token = app(IssueTokenFamilyAction::class)->forCustomer($this->customer)->accessToken;

    $this->bearer($token)->getJson('/api/v1/dashboard/wallets/overview')->assertUnauthorized();
});
