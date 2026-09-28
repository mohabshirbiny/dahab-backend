<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Actions\Auth\Shared\RevokeTokenFamilyAction;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Spec 007 US3 / FR-012, contract §5 (Sessions). Open sessions only: sign-out
// deletes a session's tokens (research R7, as built).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Sanctum::actingAs(Staff::factory()->role(SeedRole::VERIFICATION)->create(), ['staff:access'], 'staff');
    $this->customer = Customer::factory()->create();
});

function sessionsOf($test, Customer $customer, string $query = '')
{
    return $test->getJson("/api/v1/dashboard/customers/{$customer->customer_id}/sessions{$query}");
}

function trustDevice(Customer $customer, string $seed, string $firstSeen, string $lastSeen): CustomerTrustedDevice
{
    return CustomerTrustedDevice::query()->create([
        'customer_id' => $customer->customer_id,
        'fingerprint_hash' => hash('sha256', $seed),
        'first_seen_at' => $firstSeen,
        'last_seen_at' => $lastSeen,
    ]);
}

it('lists every device and the open sessions, without secrets', function () {
    $phone = trustDevice($this->customer, 'phone', '2026-09-01 10:00:00', '2026-09-27 10:00:00');
    $laptop = trustDevice($this->customer, 'laptop', '2026-09-10 10:00:00', '2026-09-20 10:00:00');
    $issue = app(IssueTokenFamilyAction::class);
    $older = $issue->forCustomer($this->customer);
    $this->travel(5)->minutes();
    $newer = $issue->forCustomer($this->customer);

    $res = sessionsOf($this, $this->customer)->assertOk();

    expect(array_column($res->json('data.devices'), 'device_ref'))->toBe([
        substr($phone->fingerprint_hash, 0, 12),
        substr($laptop->fingerprint_hash, 0, 12),
    ])
        ->and($res->json('data.devices.0'))->toHaveKeys(['device_ref', 'first_seen_at', 'last_seen_at'])
        ->and(array_column($res->json('data.sessions'), 'session_id'))->toBe([$newer->familyId, $older->familyId])
        ->and($res->json('data.sessions.0'))->toHaveKeys(['session_id', 'started_at', 'last_active_at', 'expires_at'])
        ->and($res->json('meta.total'))->toBe(2);

    $json = json_encode($res->json());
    foreach ([$phone->fingerprint_hash, $older->accessToken, $older->refreshToken, 'abilities', 'customer:access', 'token'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('drops a session once it is signed out or has expired', function () {
    $issue = app(IssueTokenFamilyAction::class);
    $kept = $issue->forCustomer($this->customer);
    $signedOut = $issue->forCustomer($this->customer);
    $expired = $issue->forCustomer($this->customer);

    app(RevokeTokenFamilyAction::class)->byFamily($signedOut->familyId);
    DB::table('personal_access_tokens')->where('family_id', $expired->familyId)->update(['expires_at' => now()->subMinute()]);

    $res = sessionsOf($this, $this->customer)->assertOk();

    expect(array_column($res->json('data.sessions'), 'session_id'))->toBe([$kept->familyId]);
});

it('never lists another customer or a staff member', function () {
    app(IssueTokenFamilyAction::class)->forCustomer(Customer::factory()->create());
    app(IssueTokenFamilyAction::class)->forStaff(Staff::factory()->create());
    trustDevice(Customer::factory()->create(), 'elsewhere', '2026-09-01', '2026-09-02');

    sessionsOf($this, $this->customer)->assertOk()
        ->assertJsonPath('data.devices', [])
        ->assertJsonPath('data.sessions', [])
        ->assertJsonPath('meta.total', 0);
});

it('pages the sessions', function () {
    $issue = app(IssueTokenFamilyAction::class);
    foreach (range(1, 3) as $i) {
        $issue->forCustomer($this->customer);
        $this->travel(1)->minutes();
    }

    $res = sessionsOf($this, $this->customer, '?per_page=2&page=2')->assertOk();

    expect($res->json('data.sessions'))->toHaveCount(1)
        ->and($res->json('meta'))->toMatchArray(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
});

it('needs customer.view, and answers 404 for an unknown customer', function () {
    Sanctum::actingAs(Staff::factory()->role(SeedRole::IGI_BRANCH)->create(), ['staff:access'], 'staff');
    sessionsOf($this, $this->customer)->assertForbidden()->assertJsonPath('code', 'permission_denied');

    Sanctum::actingAs(Staff::factory()->role(SeedRole::VERIFICATION)->create(), ['staff:access'], 'staff');
    $this->getJson('/api/v1/dashboard/customers/'.Str::uuid().'/sessions')->assertNotFound();
});
