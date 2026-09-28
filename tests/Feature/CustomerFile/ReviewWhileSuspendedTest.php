<?php

use App\Enums\CustomerStatus;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Spec 007 research R6: an identity review never lifts a suspension; it changes
// the state a reinstatement returns to.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->founder = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    Sanctum::actingAs(Staff::factory()->role(SeedRole::VERIFICATION)->create(), ['staff:access'], 'staff');

    // Suspended while waiting for verification, with a document still pending.
    $this->customer = Customer::factory()
        ->suspended(byStaffId: $this->founder->staff_id, before: CustomerStatus::PENDING_VERIFICATION)
        ->create();
    $this->document = IdentityDocument::factory()->for($this->customer)->pending()->create();
});

function reviewWhileSuspended($test, IdentityDocument $document, array $body)
{
    return $test->postJson("/api/v1/dashboard/identity-documents/{$document->document_id}/review", $body)->assertOk();
}

it('keeps the customer suspended when the document is approved, and remembers verified', function () {
    reviewWhileSuspended($this, $this->document, ['action' => 'verify']);

    $customer = $this->customer->fresh();
    expect($customer->status)->toBe(CustomerStatus::SUSPENDED)
        ->and($customer->status_before_suspension)->toBe(CustomerStatus::ACTIVE)
        ->and($customer->is_verified)->toBeTrue()
        ->and($customer->suspended_by)->toBe($this->founder->staff_id);
});

it('keeps the customer suspended when the document is rejected, and remembers rejected', function () {
    reviewWhileSuspended($this, $this->document, ['action' => 'reject', 'reasons' => ['card_expired'], 'note' => 'Expired.']);

    $customer = $this->customer->fresh();
    expect($customer->status)->toBe(CustomerStatus::SUSPENDED)
        ->and($customer->status_before_suspension)->toBe(CustomerStatus::REJECTED)
        ->and($customer->is_verified)->toBeFalse();
});

it('leaves the remembered state alone when a new upload is asked for', function () {
    reviewWhileSuspended($this, $this->document, ['action' => 'request_resubmission', 'reasons' => ['blurred_or_glare']]);

    $customer = $this->customer->fresh();
    expect($customer->status)->toBe(CustomerStatus::SUSPENDED)
        ->and($customer->status_before_suspension)->toBe(CustomerStatus::PENDING_VERIFICATION);
});

it('reinstates to the state the review settled', function () {
    reviewWhileSuspended($this, $this->document, ['action' => 'verify']);

    Sanctum::actingAs($this->founder, ['staff:access'], 'staff');
    $this->postJson("/api/v1/dashboard/customers/{$this->customer->customer_id}/reinstate", ['note' => 'Cleared.'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($this->customer->fresh()->is_verified)->toBeTrue();
});
