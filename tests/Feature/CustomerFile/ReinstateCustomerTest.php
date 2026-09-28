<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\CustomerStatus;
use App\Enums\SeedRole;
use App\Enums\SuspendedReason;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Spec 007 US2 / FR-006–FR-009, contract §4.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    Sanctum::actingAs($this->ceo, ['staff:access'], 'staff');

    Route::middleware(['api', 'auth:customer', 'abilities:customer:access', 'customer.gate:trade'])
        ->post('/api/v1/customer/_probe/reinstate-trade', fn () => response()->json(['ok' => true]));
});

function reinstateCall($test, Customer $customer, array $body, ?string $key = null)
{
    return $test->postJson(
        "/api/v1/dashboard/customers/{$customer->customer_id}/reinstate",
        $body,
        ['Idempotency-Key' => $key ?? (string) Str::uuid()],
    );
}

function suspendedCustomer(Staff $by, CustomerStatus $before = CustomerStatus::ACTIVE): Customer
{
    return Customer::factory()->suspended(SuspendedReason::REPEATED_DISPUTES, $by->staff_id, $before, 'Disputes.')->create();
}

it('returns the customer to exactly the state the suspension interrupted', function (CustomerStatus $before, bool $verified) {
    $customer = suspendedCustomer($this->ceo, $before);

    $res = reinstateCall($this, $customer, ['note' => 'Cleared after review.'])->assertOk();

    expect($res->json('data.status'))->toBe($before->value)
        ->and($res->json('data.suspension'))->toBeNull();

    $customer->refresh();
    expect($customer->status)->toBe($before)
        ->and($customer->is_verified)->toBe($verified)
        ->and($customer->is_suspended)->toBeFalse()
        ->and($customer->status_before_suspension)->toBeNull()
        ->and($customer->suspended_reason)->toBeNull()
        ->and($customer->suspended_note)->toBeNull()
        ->and($customer->suspended_by)->toBeNull()
        ->and($customer->suspended_at)->toBeNull();

    $audit = AuditLog::query()->where('action', 'auth.customer.unsuspended')->where('entity_id', $customer->customer_id)->sole();
    expect($audit->actor_staff_id)->toBe($this->ceo->staff_id)
        ->and($audit->entity_type)->toBe('customer')
        ->and($audit->reason)->toBe('Cleared after review.')
        ->and($audit->before_json)->toBe(['status' => 'suspended', 'suspended_reason' => 'repeated_disputes'])
        ->and($audit->after_json)->toMatchArray(['status' => $before->value, 'suspended_reason' => null]);
})->with([
    'verified' => [CustomerStatus::ACTIVE, true],
    'waiting' => [CustomerStatus::PENDING_VERIFICATION, false],
    'rejected' => [CustomerStatus::REJECTED, false],
]);

it('needs a note', function (array $body) {
    $customer = suspendedCustomer($this->ceo);

    reinstateCall($this, $customer, $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect($customer->fresh()->status)->toBe(CustomerStatus::SUSPENDED);
})->with([
    'none' => [[]],
    'blank' => [['note' => '  ']],
    'too long' => [['note' => str_repeat('a', 1001)]],
]);

it('refuses to reinstate a customer who is not suspended', function () {
    $customer = Customer::factory()->create();

    reinstateCall($this, $customer, ['note' => 'x'])
        ->assertStatus(409)->assertJsonPath('code', 'customer_not_suspended');

    expect(AuditLog::query()->where('action', 'auth.customer.unsuspended')->count())->toBe(0);
});

it('replays a retried reinstatement', function () {
    $customer = suspendedCustomer($this->ceo);
    $key = (string) Str::uuid();

    $first = reinstateCall($this, $customer, ['note' => 'Back.'], $key)->assertOk();
    $again = reinstateCall($this, $customer, ['note' => 'Back.'], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())
        ->and(AuditLog::query()->where('action', 'auth.customer.unsuspended')->count())->toBe(1);
});

it('refuses staff without customer.suspend', function () {
    Sanctum::actingAs(Staff::factory()->role(SeedRole::VERIFICATION)->create(), ['staff:access'], 'staff');
    $customer = suspendedCustomer($this->ceo);

    reinstateCall($this, $customer, ['note' => 'x'])->assertForbidden()->assertJsonPath('code', 'permission_denied');
    expect($customer->fresh()->status)->toBe(CustomerStatus::SUSPENDED);
});

it('opens the trade gate again for a customer restored to verified', function () {
    $customer = suspendedCustomer($this->ceo);
    $token = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->bearer($token)->postJson('/api/v1/customer/_probe/reinstate-trade')->assertForbidden();

    Sanctum::actingAs($this->ceo, ['staff:access'], 'staff');
    reinstateCall($this, $customer, ['note' => 'Cleared.'])->assertOk();

    $this->bearer($token)->postJson('/api/v1/customer/_probe/reinstate-trade')->assertOk();
});
