<?php

use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-026, SC-008, Part 1 §4.1: the three listing codes are seeded
// to CEO, COO and Operations, and stay data (spec 002): a role holding only
// one of them can read the queue but act only with that one.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    $this->seller = Customer::factory()->verified()->create();
});

/** @return list<array{0: string, 1: string, 2: array<string, string>}> */
function listingRoutes(Listing $waiting, Listing $live): array
{
    $note = 'A message that is long enough to be accepted.';
    $media = $waiting->media()->first()->media_id;

    return [
        ['GET', Listings::STAFF_URL, []],
        ['GET', Listings::STAFF_URL."/{$waiting->listing_id}", []],
        ['GET', Listings::STAFF_URL."/{$waiting->listing_id}/media/{$media}", []],
        ['POST', Listings::STAFF_URL."/{$waiting->listing_id}/approve", []],
        ['POST', Listings::STAFF_URL."/{$waiting->listing_id}/request-changes", ['message' => $note]],
        ['POST', Listings::STAFF_URL."/{$waiting->listing_id}/reject", ['reason' => $note]],
        ['POST', Listings::STAFF_URL."/{$live->listing_id}/takedown", ['reason' => $note]],
    ];
}

function listingFixtures(Customer $seller): array
{
    return [
        Listing::factory()->withPhotos(2)->inReview()->create(['seller_id' => $seller->customer_id]),
        Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]),
    ];
}

it('defines the three codes in the Listings group, seeded to COO and Operations', function () {
    foreach ([StaffPermission::LISTING_REVIEW, StaffPermission::LISTING_REQUEST_CHANGES, StaffPermission::LISTING_TAKEDOWN] as $code) {
        expect($code->group())->toBe('Listings')
            ->and($code->seedRoles())->toBe([SeedRole::COO->value, SeedRole::OPERATIONS->value]);
    }

    expect(StaffPermission::LISTING_REVIEW->label())->toBe('Approve or reject a new listing')
        ->and(StaffPermission::LISTING_REQUEST_CHANGES->label())->toBe('Ask a seller for a better photo')
        ->and(StaffPermission::LISTING_TAKEDOWN->label())->toBe('Take a live listing down');
});

it('refuses every listing route to roles without a listing permission, and records it', function (SeedRole $role) {
    [$waiting, $live] = listingFixtures($this->seller);
    $staff = Listings::actAsStaff($this, $role);

    foreach (listingRoutes($waiting, $live) as [$method, $uri, $body]) {
        $this->json($method, $uri, $body, Listings::key())->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }

    expect(AuditLog::query()->where('action', 'auth.staff.permission_denied')->where('actor_staff_id', $staff->staff_id)->count())->toBe(7)
        ->and($waiting->fresh()->state->value)->toBe('in_review')
        ->and($live->fresh()->state->value)->toBe('live');
    Notification::assertNothingSent();
})->with([
    'Finance' => [SeedRole::FINANCE],
    'Verification' => [SeedRole::VERIFICATION],
    'IGI' => [SeedRole::IGI_BRANCH],
]);

it('allows every listing route to the default listing roles', function (SeedRole $role) {
    Listings::actAsStaff($this, $role);

    foreach (['approve' => [], 'request-changes' => ['message' => 'A message that is long enough.'], 'reject' => ['reason' => 'A reason that is long enough.']] as $action => $body) {
        $waiting = Listing::factory()->withPhotos(2)->inReview()->create(['seller_id' => $this->seller->customer_id]);
        $this->postJson(Listings::STAFF_URL."/{$waiting->listing_id}/{$action}", $body, Listings::key())->assertOk();
    }

    [$waiting, $live] = listingFixtures($this->seller);
    $this->getJson(Listings::STAFF_URL)->assertOk();
    $this->getJson(Listings::STAFF_URL."/{$waiting->listing_id}")->assertOk();
    $this->get(Listings::STAFF_URL."/{$waiting->listing_id}/media/".$waiting->media()->first()->media_id)->assertOk();
    $this->postJson(Listings::STAFF_URL."/{$live->listing_id}/takedown", ['reason' => 'A reason that is long enough.'], Listings::key())->assertOk();
})->with([
    'CEO' => [SeedRole::CEO],
    'COO' => [SeedRole::COO],
    'Operations' => [SeedRole::OPERATIONS],
]);

it('lets a role with only the take-down code read, and do nothing but take down', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $role = Role::create(['name' => 'market_watch', 'guard_name' => 'staff', 'display_name' => 'Market watch']);
    $role->givePermissionTo(StaffPermission::LISTING_TAKEDOWN->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $staff = Staff::factory()->withRole('market_watch')->create();
    app('auth')->forgetGuards();
    Sanctum::actingAs($staff, ['staff:access'], 'staff');

    [$waiting, $live] = listingFixtures($this->seller);
    $note = 'A message that is long enough to be accepted.';

    $this->getJson(Listings::STAFF_URL)->assertOk();
    $this->getJson(Listings::STAFF_URL."/{$waiting->listing_id}")->assertOk()
        ->assertJsonPath('data.can_approve', false)->assertJsonPath('data.can_request_changes', false)->assertJsonPath('data.can_reject', false);
    $this->getJson(Listings::STAFF_URL."/{$live->listing_id}")->assertOk()->assertJsonPath('data.can_take_down', true);

    $this->postJson(Listings::STAFF_URL."/{$waiting->listing_id}/approve", [], Listings::key())->assertForbidden();
    $this->postJson(Listings::STAFF_URL."/{$waiting->listing_id}/reject", ['reason' => $note], Listings::key())->assertForbidden();
    $this->postJson(Listings::STAFF_URL."/{$waiting->listing_id}/request-changes", ['message' => $note], Listings::key())->assertForbidden();
    $this->postJson(Listings::STAFF_URL."/{$live->listing_id}/takedown", ['reason' => $note], Listings::key())->assertOk();

    expect($waiting->fresh()->state->value)->toBe('in_review')->and($live->fresh()->state->value)->toBe('withdrawn');
});

it('takes a removed code away on the next request', function () {
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    [$waiting] = listingFixtures($this->seller);

    $this->getJson(Listings::STAFF_URL)->assertOk();

    Role::findByName(SeedRole::OPERATIONS->value, 'staff')->revokePermissionTo([
        StaffPermission::LISTING_REVIEW->value, StaffPermission::LISTING_REQUEST_CHANGES->value, StaffPermission::LISTING_TAKEDOWN->value,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson(Listings::STAFF_URL)->assertForbidden();
    $this->postJson(Listings::STAFF_URL."/{$waiting->listing_id}/approve", [], Listings::key())->assertForbidden();
});

it('refuses a customer token on the staff routes and a staff token on the seller routes', function () {
    [$waiting] = listingFixtures($this->seller);

    Listings::as($this, $this->seller)->getJson(Listings::STAFF_URL)->assertUnauthorized();

    $this->withoutToken();
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->getJson(Listings::SELLER_URL)->assertUnauthorized();
});
