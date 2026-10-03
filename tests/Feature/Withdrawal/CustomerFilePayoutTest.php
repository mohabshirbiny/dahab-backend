<?php

use App\Enums\SeedRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Listings;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 013 US8, FR-020: the Customer file shows the payout accounts (full
// number only for payout_account.verify / withdrawal.release) and any open
// pause; the staff wallet splits pending withdrawals from held on orders.

beforeEach(function () {
    Notification::fake();
    $this->w = Withdrawals::requested($this, '1000', '5000');
    $this->customer = Withdrawals::customer($this->w);
});

it('shows payout accounts with the full number to Finance and Verification', function (SeedRole $role) {
    Withdrawals::staff($this, $role);

    $this->getJson(Withdrawals::STAFF."/customers/{$this->customer->customer_id}")->assertOk()
        ->assertJsonPath('data.payout_accounts.0.number', Withdrawals::iban())
        ->assertJsonPath('data.payout_accounts.0.in_use', true)
        ->assertJsonPath('data.payout_accounts.0.state', 'active')
        ->assertJsonPath('data.withdrawal_pause', null);
})->with([SeedRole::FINANCE, SeedRole::VERIFICATION]);

it('masks the number for customer.view alone and shows an open pause', function () {
    $b = Withdrawals::verifiedAccount($this, $this->customer, ['account_number_or_iban' => '1234567890']);
    Listings::as($this, $this->customer)->postJson(Withdrawals::CUSTOMER."/payout-accounts/{$b->payout_account_id}/use", [], Listings::key())->assertOk();

    $viewer = Withdrawals::staff($this, SeedRole::OPERATIONS);
    DB::table('model_has_permissions')->insert(['permission_id' => DB::table('permissions')->where('name', 'customer.view')->value('id'), 'model_type' => $viewer->getMorphClass(), 'model_id' => $viewer->staff_id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $res = $this->getJson(Withdrawals::STAFF."/customers/{$this->customer->customer_id}")->assertOk()
        ->assertJsonPath('data.payout_accounts.0.number', null)
        ->assertJsonPath('data.payout_accounts.0.number_masked', '•••• 7890');
    expect($res->json('data.withdrawal_pause.until'))->not->toBeNull();
});

it('splits pending withdrawals in the staff wallet', function () {
    Withdrawals::staff($this, SeedRole::FINANCE);

    $this->getJson(Withdrawals::STAFF."/customers/{$this->customer->customer_id}/wallet")->assertOk()
        ->assertJsonPath('data.held', '1000.0000')
        ->assertJsonPath('data.pending_withdrawals', '1000.0000')
        ->assertJsonPath('data.held_on_orders', '0.0000');
    $this->getJson(Withdrawals::STAFF."/withdrawals?customer_id={$this->customer->customer_id}")->assertOk()->assertJsonCount(1, 'data');
});
