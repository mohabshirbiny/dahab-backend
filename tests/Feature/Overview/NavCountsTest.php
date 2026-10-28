<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\TopUp;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Account;
use Tests\Support\Disputes;
use Tests\Support\Finance;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Dashboard performance: the navigation badges in one read, each only with its
// page's code, each equal to the count its own list shows.

const NAV_COUNTS_URL = '/api/v1/dashboard/nav-counts';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    // The factories below create staff; seed the roles before any of them.
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    // The report first: the helpers below sign in as staff.
    $piece = Orders::ring();
    Account::post($this, Listings::token(Customer::factory()->verified()->create()), '/listing-reports',
        ['listing_id' => $piece->listing_id, 'reason' => 'photos_not_genuine'])->assertCreated();

    Customer::factory()->pendingVerification()->count(2)->create();
    Customer::factory()->rejected()->create();
    $customer = Customer::factory()->verified()->create();
    TopUp::factory()->for($customer)->pending()->create();
    TopUp::factory()->for($customer)->onHold()->create();
    TopUp::factory()->for($customer)->cancelled()->create();
    Listing::factory()->inReview()->count(3)->create();
    Withdrawals::requested($this, '1000', '1000');
    Disputes::opened($this, Disputes::awaitingBalance($this));
});

it('gives the CEO every count, each equal to its own list', function () {
    Finance::staff($this, SeedRole::CEO);
    $data = $this->getJson(NAV_COUNTS_URL)->assertOk()->assertJsonStructure(['data', 'meta' => ['generated_at']])->json('data');

    expect(array_keys($data))->toBe(['customers_pending', 'customers_by_status', 'topups_open', 'listings_in_review', 'withdrawals_waiting', 'disputes_unresolved', 'listing_reports_open'])
        ->and($data['topups_open'])->toBe(2)
        ->and($data['listings_in_review'])->toBe(3)
        ->and($data['withdrawals_waiting'])->toBe(1)
        ->and($data['disputes_unresolved'])->toBe(1)
        ->and($data['listing_reports_open'])->toBe(1);

    $total = fn (string $status) => $this->getJson("/api/v1/dashboard/customers?status={$status}&per_page=1")->assertOk()->json('meta.total');
    foreach ($data['customers_by_status'] as $status => $n) {
        expect($n)->toBe($total($status));
    }
    expect($data['customers_pending'])->toBe(2)->toBe($data['customers_by_status']['pending_verification'])
        ->and($data['customers_by_status']['rejected'])->toBe(1);

    expect($this->getJson('/api/v1/dashboard/topups?status=pending,on_hold&from=2000-01-01&per_page=1')->json('meta.totals.count'))->toBe($data['topups_open'])
        ->and($this->getJson('/api/v1/dashboard/listings?state=in_review&per_page=1')->json('meta.counts.in_review'))->toBe($data['listings_in_review'])
        ->and($this->getJson('/api/v1/dashboard/withdrawals?per_page=1')->json('meta.figures.waiting_count'))->toBe($data['withdrawals_waiting'])
        ->and(array_sum($this->getJson('/api/v1/dashboard/disputes?state=unresolved&per_page=1')->json('meta.counts')))->toBe($data['disputes_unresolved'])
        ->and($this->getJson('/api/v1/dashboard/listing-reports?state=open')->json('meta.counts.open'))->toBe($data['listing_reports_open']);
});

it('leaves out every count the viewer may not see', function () {
    Finance::staff($this, SeedRole::VERIFICATION);
    expect(array_keys($this->getJson(NAV_COUNTS_URL)->assertOk()->json('data')))->toBe(['customers_pending', 'customers_by_status']);

    Finance::staff($this, SeedRole::OPERATIONS);
    expect($this->getJson(NAV_COUNTS_URL)->assertOk()->json('data'))
        ->toHaveKeys(['listings_in_review', 'disputes_unresolved', 'listing_reports_open'])
        ->not->toHaveKey('withdrawals_waiting')->not->toHaveKey('customers_by_status');

    Finance::staff($this, SeedRole::IGI_BRANCH);
    expect($this->getJson(NAV_COUNTS_URL)->assertOk()->json('data'))->toBe([]);
});

it('needs a signed-in staff member', function () {
    $this->getJson(NAV_COUNTS_URL)->assertUnauthorized();
});
