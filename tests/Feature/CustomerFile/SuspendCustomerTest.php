<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\CustomerStatus;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Spec 007 US2 / FR-004–FR-006, FR-008–FR-010, contract §3.

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->create(['full_name' => 'Ahmed Ezz El-Din']);
    Sanctum::actingAs($this->coo, ['staff:access'], 'staff');

    // A trade-gated probe route: no trade endpoint exists yet.
    Route::middleware(['api', 'auth:customer', 'abilities:customer:access', 'customer.gate:trade'])
        ->post('/api/v1/customer/_probe/suspend-trade', fn () => response()->json(['ok' => true]));
});

function suspendCall($test, Customer $customer, array $body, ?string $key = null)
{
    return $test->postJson(
        "/api/v1/dashboard/customers/{$customer->customer_id}/suspend",
        $body,
        ['Idempotency-Key' => $key ?? (string) Str::uuid()],
    );
}

function suspendAudits(Customer $customer)
{
    return AuditLog::query()
        ->where('action', 'auth.customer.suspended')
        ->where('entity_type', 'customer')
        ->where('entity_id', $customer->customer_id)
        ->get();
}

it('suspends a customer from any state and records it once', function (string $state, string $before) {
    $customer = Customer::factory()->{$state}()->create();

    $res = suspendCall($this, $customer, ['reason' => 'off_platform_dealing', 'note' => '  Asked a buyer to pay cash.  '])
        ->assertOk();

    expect($res->json('data.status'))->toBe('suspended')
        ->and($res->json('data.suspension'))->toMatchArray([
            'reason' => 'off_platform_dealing',
            'note' => 'Asked a buyer to pay cash.',
            'status_before' => $before,
            'suspended_by' => ['id' => $this->coo->staff_id, 'full_name' => 'Ahmed Ezz El-Din'],
        ]);

    $customer->refresh();
    expect($customer->status)->toBe(CustomerStatus::SUSPENDED)
        ->and($customer->status_before_suspension->value)->toBe($before)
        ->and($customer->suspended_by)->toBe($this->coo->staff_id)
        ->and($customer->suspended_at)->not->toBeNull()
        ->and($customer->suspended_note)->toBe('Asked a buyer to pay cash.');

    $audit = suspendAudits($customer)->sole();
    expect($audit->actor_staff_id)->toBe($this->coo->staff_id)
        ->and($audit->reason)->toBe('Asked a buyer to pay cash.')
        ->and($audit->before_json)->toBe(['status' => $before, 'suspended_reason' => null])
        ->and($audit->after_json)->toMatchArray(['status' => 'suspended', 'suspended_reason' => 'off_platform_dealing']);
})->with([
    'verified' => ['verified', 'active'],
    'waiting' => ['pendingVerification', 'pending_verification'],
    'rejected' => ['rejected', 'rejected'],
]);

it('validates the reason and the note', function (array $body) {
    $customer = Customer::factory()->create();

    suspendCall($this, $customer, $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($customer->fresh()->status)->toBe(CustomerStatus::ACTIVE)
        ->and(suspendAudits($customer))->toHaveCount(0);
})->with([
    'no reason' => [['note' => 'x']],
    'an unknown reason' => [['reason' => 'policy_violation', 'note' => 'x']],
    'no note' => [['reason' => 'other']],
    'a blank note' => [['reason' => 'other', 'note' => '   ']],
    'a note over 1000 characters' => [['reason' => 'other', 'note' => str_repeat('a', 1001)]],
]);

it('refuses to suspend a customer who is already suspended', function () {
    $customer = Customer::factory()->create();
    suspendCall($this, $customer, ['reason' => 'other', 'note' => 'First.'])->assertOk();

    suspendCall($this, $customer, ['reason' => 'repeated_disputes', 'note' => 'Second.'])
        ->assertStatus(409)->assertJsonPath('code', 'customer_already_suspended');

    expect($customer->fresh()->suspended_note)->toBe('First.')
        ->and(suspendAudits($customer))->toHaveCount(1);
});

it('replays a retried request instead of suspending twice', function () {
    $customer = Customer::factory()->create();
    $key = (string) Str::uuid();
    $body = ['reason' => 'other', 'note' => 'Retry me.'];

    $first = suspendCall($this, $customer, $body, $key)->assertOk();
    $again = suspendCall($this, $customer, $body, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())
        ->and(suspendAudits($customer))->toHaveCount(1);
});

it('needs an Idempotency-Key', function () {
    $customer = Customer::factory()->create();

    $this->postJson("/api/v1/dashboard/customers/{$customer->customer_id}/suspend", ['reason' => 'other', 'note' => 'x'])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    expect($customer->fresh()->status)->toBe(CustomerStatus::ACTIVE);
});

it('refuses staff without customer.suspend, and answers 404 for an unknown customer', function () {
    Sanctum::actingAs(Staff::factory()->role(SeedRole::VERIFICATION)->create(), ['staff:access'], 'staff');
    $customer = Customer::factory()->create();

    suspendCall($this, $customer, ['reason' => 'other', 'note' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
    expect($customer->fresh()->status)->toBe(CustomerStatus::ACTIVE);

    Sanctum::actingAs($this->coo, ['staff:access'], 'staff');
    $this->postJson('/api/v1/dashboard/customers/'.Str::uuid().'/suspend', ['reason' => 'other', 'note' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('leaves the customer able to sign in and read, but not trade', function () {
    $customer = Customer::factory()->create();
    $token = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->bearer($token)->postJson('/api/v1/customer/_probe/suspend-trade')->assertOk();

    Sanctum::actingAs($this->coo, ['staff:access'], 'staff');
    suspendCall($this, $customer, ['reason' => 'reported_by_users', 'note' => 'Staff-only note.'])->assertOk();

    // The same session keeps working for reads; the trade gate re-reads the status.
    $me = $this->bearer($token)->getJson('/api/v1/customer/auth/me')->assertOk();
    expect(json_encode($me->json()))->toContain('suspended')
        ->toContain('reported_by_users')
        ->not->toContain('Staff-only note.');

    $this->bearer($token)->postJson('/api/v1/customer/_probe/suspend-trade')
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
});
