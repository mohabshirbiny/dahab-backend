<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 research R7, analysis C1: dispute.handle (CEO, COO, Operations,
// Finance); order.refund and compensation.pay (CEO, Finance); never the COO
// on money; all of it editable from the Dashboard.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('seeds the codes to the roles of the plan', function (SeedRole $role, bool $handle, bool $refund, bool $pay, bool $uncapped) {
    $staff = Orders::staff($this, $role);

    expect($staff->can('dispute.handle'))->toBe($handle)
        ->and($staff->can('order.refund'))->toBe($refund)
        ->and($staff->can('compensation.pay'))->toBe($pay)
        ->and($staff->can('compensation.uncapped'))->toBe($uncapped);
})->with([
    [SeedRole::CEO, true, true, true, true],
    [SeedRole::COO, true, false, false, false],
    [SeedRole::OPERATIONS, true, false, false, false],
    [SeedRole::FINANCE, true, true, true, false],
    [SeedRole::VERIFICATION, false, false, false, false],
    [SeedRole::IGI_BRANCH, false, false, false, false],
]);

it('follows a role edited from the Dashboard at once', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);

    $ops = Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute, ['outcome' => 'against_sale'])->assertForbidden();

    $ops->roles->first()->givePermissionTo('order.refund');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Disputes::actAs(Staff::query()->findOrFail($ops->staff_id));
    Disputes::resolve($this, $dispute, ['outcome' => 'against_sale'])->assertOk();
});

it('keeps the queue closed to a role holding only order.refund', function () {
    $verification = Orders::staff($this, SeedRole::VERIFICATION);
    $verification->roles->first()->givePermissionTo('order.refund');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Disputes::actAs(Staff::query()->findOrFail($verification->staff_id));

    $this->getJson(Disputes::STAFF_URL)->assertForbidden();
});
