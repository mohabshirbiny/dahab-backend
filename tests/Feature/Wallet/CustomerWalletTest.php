<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 US3 (FR-013, FR-014, FR-020): GET /customer/me/wallet.

function walletCustomerToken(Customer $customer): string
{
    return app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
}

it('shows a verified customer their available, held and total as 4-place strings', function () {
    $customer = Customer::factory()->verified()->create();
    Ledger::topUp($customer, '1000');
    Ledger::hold($customer, '400');

    $this->bearer(walletCustomerToken($customer))->getJson('/api/v1/customer/me/wallet')
        ->assertOk()
        ->assertExactJson(['data' => ['available' => '600.0000', 'held' => '400.0000', 'held_on_orders' => '400.0000', 'pending_withdrawals' => '0.0000', 'total' => '1000.0000', 'currency' => 'EGP']]);
});

it('shows a new customer zeros', function () {
    $customer = Customer::factory()->verified()->create();

    $this->bearer(walletCustomerToken($customer))->getJson('/api/v1/customer/me/wallet')
        ->assertOk()
        ->assertJsonPath('data.available', '0.0000')
        ->assertJsonPath('data.held', '0.0000')
        ->assertJsonPath('data.total', '0.0000');
});

it('never includes another customer\'s money', function () {
    $mine = Customer::factory()->verified()->create();
    $other = Customer::factory()->verified()->create();
    Ledger::topUp($other, '5000');
    Ledger::topUp($mine, '10');

    $this->bearer(walletCustomerToken($mine))->getJson('/api/v1/customer/me/wallet')
        ->assertOk()->assertJsonPath('data.total', '10.0000');
});

it('refuses customers who are not verified', function (string $state) {
    $customer = Customer::factory()->{$state}()->create();

    $this->bearer(walletCustomerToken($customer))->getJson('/api/v1/customer/me/wallet')
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
})->with(['pendingVerification', 'rejected']);

it('lets a suspended customer read their wallet', function () {
    $customer = Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create();
    Ledger::topUp($customer, '75.5');

    $this->bearer(walletCustomerToken($customer))->getJson('/api/v1/customer/me/wallet')
        ->assertOk()->assertJsonPath('data.available', '75.5000');
});

it('refuses a staff token and no token', function () {
    $staff = Staff::factory()->create();

    $this->bearer(staffAccessToken($staff))->getJson('/api/v1/customer/me/wallet')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken('')->getJson('/api/v1/customer/me/wallet')->assertUnauthorized();
});
