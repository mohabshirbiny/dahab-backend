<?php

use App\Enums\StaffRole;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const CUSTOMERS_BASE = '/api/v1/dashboard/customers';

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Sanctum::actingAs(Staff::factory()->role(StaffRole::VERIFICATION)->create(), ['staff:access'], 'staff');
});

it('sends the customer type, defaulting to ordinary', function () {
    $ordinary = Customer::factory()->pendingVerification()->create();
    $dealer = Customer::factory()->pendingVerification()->marketMaker()->create();

    $rows = collect($this->getJson(CUSTOMERS_BASE)->assertOk()->json('data'))->keyBy('id');

    expect($rows[$ordinary->customer_id]['customer_type'])->toBe('ordinary')
        ->and($rows[$dealer->customer_id]['customer_type'])->toBe('market_maker');
});

it('sends when the latest document was decided, and null while it is pending', function () {
    $waiting = Customer::factory()->pendingVerification()->create();
    IdentityDocument::factory()->for($waiting)->pending()->create();

    $decided = Customer::factory()->create();
    IdentityDocument::factory()->for($decided)->verified()->create();

    $rows = collect($this->getJson(CUSTOMERS_BASE.'?status=pending_verification')->assertOk()->json('data'));
    expect($rows->firstWhere('id', $waiting->customer_id)['latest_document']['reviewed_at'])->toBeNull();

    $detail = $this->getJson(CUSTOMERS_BASE.'/'.$decided->customer_id)->assertOk();
    expect($detail->json('data.customer_type'))->toBe('ordinary')
        ->and($detail->json('data.latest_document.reviewed_at'))->not->toBeNull();
});

it('refuses a customer type outside the two known values at the database', function () {
    $customer = Customer::factory()->create();

    // Straight to the table: the model's enum cast would refuse the value first.
    expect(fn () => DB::table('customer')
        ->where('customer_id', $customer->customer_id)
        ->update(['customer_type' => 'wholesaler']))
        ->toThrow(QueryException::class);
});
