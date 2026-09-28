<?php

use App\Enums\CustomerStatus;
use App\Enums\SeedRole;
use App\Enums\SuspendedReason;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Spec 007 US1 / FR-001–FR-003, FR-013 (search), contract §1–§2.

const FILE_BASE = '/api/v1/dashboard/customers';

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->verification = Staff::factory()->role(SeedRole::VERIFICATION)->create(['full_name' => 'Sara Adel']);
    Sanctum::actingAs($this->verification, ['staff:access'], 'staff');
});

it('opens the whole file: profile, every document newest first, no suspension', function () {
    $customer = Customer::factory()->verified()->create(['preferred_lang' => 'en', 'governorate' => 'cairo']);
    $old = IdentityDocument::factory()->for($customer)->rejected()->create(['created_at' => now()->subDays(3), 'reviewed_by' => $this->verification->staff_id]);
    $new = IdentityDocument::factory()->for($customer)->verified()->create(['created_at' => now()->subDay(), 'reviewed_by' => $this->verification->staff_id]);

    $res = $this->getJson(FILE_BASE.'/'.$customer->customer_id)->assertOk();

    expect($res->json('data.id'))->toBe($customer->customer_id)
        ->and($res->json('data.preferred_lang'))->toBe('en')
        ->and($res->json('data.joined_at'))->not->toBeNull()
        ->and($res->json('data.governorate'))->toBe('cairo')
        ->and($res->json('data.status'))->toBe('active')
        ->and($res->json('data.suspension'))->toBeNull()
        ->and(array_column($res->json('data.documents'), 'document_id'))->toBe([$new->document_id, $old->document_id])
        ->and($res->json('data.documents.0.reviewed_by'))->toBe(['id' => $this->verification->staff_id, 'full_name' => 'Sara Adel'])
        ->and($res->json('data.latest_document'))->toBe($res->json('data.documents.0'));
});

it('shows a pending document with no reviewer', function () {
    $customer = Customer::factory()->pendingVerification()->create();
    IdentityDocument::factory()->for($customer)->pending()->create();

    $this->getJson(FILE_BASE.'/'.$customer->customer_id)->assertOk()
        ->assertJsonPath('data.documents.0.reviewed_by', null)
        ->assertJsonPath('data.documents.0.status', 'pending');
});

it('shows the suspension: reason, note, state before, who and when', function () {
    $founder = Staff::factory()->role(SeedRole::COO)->founder()->create(['full_name' => 'Ahmed Ezz El-Din']);
    $customer = Customer::factory()
        ->suspended(SuspendedReason::REPEATED_DISPUTES, $founder->staff_id, CustomerStatus::REJECTED, 'Three disputes this month.')
        ->create();

    $res = $this->getJson(FILE_BASE.'/'.$customer->customer_id)->assertOk();

    expect($res->json('data.status'))->toBe('suspended')
        ->and($res->json('data.suspended_reason'))->toBe('repeated_disputes')
        ->and($res->json('data.suspension'))->toMatchArray([
            'reason' => 'repeated_disputes',
            'note' => 'Three disputes this month.',
            'status_before' => 'rejected',
            'suspended_by' => ['id' => $founder->staff_id, 'full_name' => 'Ahmed Ezz El-Din'],
        ])
        ->and($res->json('data.suspension.suspended_at'))->not->toBeNull();
});

it('records exactly one audit entry per opening', function () {
    $customer = Customer::factory()->create();

    $this->getJson(FILE_BASE.'/'.$customer->customer_id)->assertOk();
    $this->getJson(FILE_BASE.'/'.$customer->customer_id)->assertOk();

    $rows = AuditLog::query()
        ->where('action', 'auth.customer.verification_details_viewed')
        ->where('entity_id', $customer->customer_id)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('actor_staff_id')->unique()->all())->toBe([$this->verification->staff_id]);
});

it('loads the file in a fixed number of queries, however many documents', function () {
    $few = Customer::factory()->create();
    IdentityDocument::factory()->for($few)->verified()->create();
    $many = Customer::factory()->create();
    IdentityDocument::factory()->count(6)->for($many)->verified()->create();

    $count = function (Customer $c) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(FILE_BASE.'/'.$c->customer_id)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count($few); // warm permission caches
    expect($count($many))->toBe($count($few));
});

it('refuses staff without customer.view, and answers 404 for an unknown customer', function () {
    Sanctum::actingAs(Staff::factory()->role(SeedRole::IGI_BRANCH)->create(), ['staff:access'], 'staff');
    $this->getJson(FILE_BASE.'/'.Customer::factory()->create()->customer_id)
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');

    Sanctum::actingAs($this->verification, ['staff:access'], 'staff');
    $this->getJson(FILE_BASE.'/'.Str::uuid())->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('never sends the staff note to the customer', function () {
    $founder = Staff::factory()->founder()->create();
    $customer = Customer::factory()->suspended(byStaffId: $founder->staff_id, note: 'Internal only.')->create();

    Sanctum::actingAs($customer, ['customer:access'], 'customer');
    $me = $this->getJson('/api/v1/customer/auth/me')->assertOk();

    expect($me->json('data.customer.status') ?? $me->json('data.status'))->toBe('suspended')
        ->and(json_encode($me->json()))->not->toContain('Internal only.');
});

it('finds a customer by reference or phone across every state', function () {
    $suspended = Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)
        ->create(['display_ref' => '004417', 'phone' => '+201011114417']);
    Customer::factory()->pendingVerification()->create(['display_ref' => '004418']);

    $byRef = $this->getJson(FILE_BASE.'?q=004417&status=pending_verification')->assertOk();
    expect(array_column($byRef->json('data'), 'id'))->toBe([$suspended->customer_id]);

    $byPhone = $this->getJson(FILE_BASE.'?q='.urlencode('+20 101 111 4417'))->assertOk();
    expect(array_column($byPhone->json('data'), 'id'))->toBe([$suspended->customer_id]);

    $none = $this->getJson(FILE_BASE.'?q=0044')->assertOk();
    expect($none->json('data'))->toBe([]);
});

it('validates the search term', function () {
    $this->getJson(FILE_BASE.'?q='.str_repeat('1', 33))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
