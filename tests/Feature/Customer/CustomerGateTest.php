<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\CustomerTrustedDevice;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Spec 002 FR-030–FR-033, data-model §6.

beforeEach(function () {
    // Probe routes live in the test, not in routes/api.php.
    Route::middleware(['api', 'auth:customer', 'abilities:customer:access', 'customer.gate:verified'])
        ->get('/api/v1/customer/_probe/verified', fn () => response()->json(['ok' => true]));
    Route::middleware(['api', 'auth:customer', 'abilities:customer:access', 'customer.gate:trade'])
        ->post('/api/v1/customer/_probe/trade', fn () => response()->json(['ok' => true]));
});

function customerToken(Customer $customer): string
{
    return app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
}

it('applies the gate matrix', function (string $state, int $verifiedStatus, ?string $verifiedCode, int $tradeStatus, ?string $tradeCode) {
    $customer = $state === 'suspended'
        ? Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create()
        : Customer::factory()->{$state}()->create();
    $token = customerToken($customer);

    $verified = $this->bearer($token)->getJson('/api/v1/customer/_probe/verified')->assertStatus($verifiedStatus);
    $trade = $this->bearer($token)->postJson('/api/v1/customer/_probe/trade')->assertStatus($tradeStatus);

    expect($verified->json('code'))->toBe($verifiedCode)
        ->and($trade->json('code'))->toBe($tradeCode);
})->with([
    'active' => ['verified', 200, null, 200, null],
    'suspended' => ['suspended', 200, null, 403, 'account_suspended'],
    'pending verification' => ['pendingVerification', 403, 'verification_required', 403, 'verification_required'],
    'rejected' => ['rejected', 403, 'verification_required', 403, 'verification_required'],
]);

it('reads the live status on every request, not the token', function () {
    $customer = Customer::factory()->pendingVerification()->create();
    $token = customerToken($customer);

    $this->bearer($token)->postJson('/api/v1/customer/_probe/trade')->assertForbidden();

    $customer->forceFill(['status' => CustomerStatus::ACTIVE, 'is_verified' => true])->save();

    $this->bearer($token)->postJson('/api/v1/customer/_probe/trade')->assertOk();
});

it('lets an unverified customer sign in, read their profile and finish verification', function () {
    $customer = Customer::factory()->pendingVerification()->withPassword('correct-horse-battery')->create();
    $token = customerToken($customer);

    $this->bearer($token)->getJson('/api/v1/customer/auth/me')->assertOk();

    Storage::fake('identity_private');
    $this->bearer($token)->post('/api/v1/customer/me/uploads', [
        'purpose' => 'identity',
        'file' => UploadedFile::fake()->createWithContent('id.png', pngBytes()),
    ], ['Accept' => 'application/json'])->assertCreated();
});

it('signs in an unverified, rejected or suspended customer with password (spec 002 US3 AS1, FR-032)', function (string $status) {
    $customer = Customer::factory()->create([
        'phone' => '+201000003333',
        'status' => $status,
        'is_verified' => $status === 'suspended',
        'is_suspended' => $status === 'suspended',
        'suspended_reason' => $status === 'suspended' ? 'policy_violation' : null,
        'suspended_by' => $status === 'suspended' ? Staff::factory()->create()->staff_id : null,
        'suspended_at' => $status === 'suspended' ? now() : null,
    ]);
    CustomerPassword::query()->create([
        'customer_id' => $customer->customer_id,
        'password_hash' => Hash::make('correct-horse-battery'),
        'password_changed_at' => now(),
    ]);
    $deviceId = str_repeat('e', 32);
    CustomerTrustedDevice::query()->create([
        'customer_id' => $customer->customer_id,
        'fingerprint_hash' => hash('sha256', $deviceId.'|web'),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->postJson('/api/v1/customer/auth/login', ['phone' => '+201000003333', 'password' => 'correct-horse-battery'],
        ['X-Device-Id' => $deviceId, 'X-Device-Platform' => 'web'])
        ->assertOk()
        ->assertJsonPath('data.customer.status', $status)
        ->assertJsonStructure(['data' => ['session' => ['access_token']]]);
})->with(['pending_verification', 'rejected', 'suspended']);
