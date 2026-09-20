<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AuditEvent;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

/** Call one identity-review endpoint by short name. */
function callIdentityEndpoint($test, string $endpoint, ?string $documentId = null)
{
    $id = $documentId ?? (string) Str::uuid();
    $base = '/api/v1/dashboard/identity-documents';

    return match ($endpoint) {
        'index' => $test->getJson($base),
        'show' => $test->getJson("{$base}/{$id}"),
        'image' => $test->getJson("{$base}/{$id}/image"),
        'review' => $test->postJson("{$base}/{$id}/review", ['action' => 'verify']),
    };
}

function identityStaff(StaffRole $role, array $directPermissions = []): Staff
{
    $staff = Staff::factory()->role($role)->create();
    foreach ($directPermissions as $permission) {
        $staff->givePermissionTo($permission);
    }
    Sanctum::actingAs($staff, ['staff:access'], 'staff');

    return $staff;
}

const IDENTITY_ENDPOINTS = ['index', 'show', 'image', 'review'];

it('requires authentication on every identity-review endpoint', function (string $endpoint) {
    callIdentityEndpoint($this, $endpoint)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
})->with(IDENTITY_ENDPOINTS);

it('treats a customer token as unauthenticated on every identity-review endpoint', function (string $endpoint) {
    $token = app(IssueTokenFamilyAction::class)->forCustomer(Customer::factory()->create())->accessToken;

    $this->bearer($token);
    callIdentityEndpoint($this, $endpoint)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
})->with(IDENTITY_ENDPOINTS);

it('refuses a staff refresh token with 403 forbidden on every identity-review endpoint', function (string $endpoint) {
    $staff = Staff::factory()->role(StaffRole::VERIFICATION)->create();
    $token = app(IssueTokenFamilyAction::class)->forStaff($staff)->refreshToken;

    $this->bearer($token);
    callIdentityEndpoint($this, $endpoint)->assertStatus(403)->assertJsonPath('code', 'forbidden');
})->with(IDENTITY_ENDPOINTS);

it('refuses roles without the permission with 403 permission_denied and audits the denial', function (StaffRole $role, string $endpoint, string $permission) {
    $staff = identityStaff($role);
    $document = IdentityDocument::factory()->withImage()->create();

    callIdentityEndpoint($this, $endpoint, $document->document_id)
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied');

    $denied = AuditLog::query()->where('action', AuditEvent::STAFF_PERMISSION_DENIED->value)->sole();
    expect($denied->actor_staff_id)->toBe($staff->staff_id)
        ->and($denied->after_json['permission'])->toBe($permission);

    // A refused call touches nothing.
    expect(DocumentViewLog::query()->count())->toBe(0)
        ->and($document->fresh()->status->value)->toBe('pending');
})->with(
    fn () => collect([StaffRole::COO, StaffRole::FINANCE, StaffRole::OPERATIONS, StaffRole::IGI_BRANCH])
        ->crossJoin([['index', 'identity.view'], ['show', 'identity.view'], ['image', 'identity.view'], ['review', 'identity.review']])
        ->mapWithKeys(fn ($pair) => ["{$pair[0]->value} → {$pair[1][0]}" => [$pair[0], $pair[1][0], $pair[1][1]]])
        ->all()
);

it('lets the CEO and Verification roles through every endpoint', function (StaffRole $role, string $endpoint) {
    identityStaff($role);
    $document = IdentityDocument::factory()->withImage(pngBytes())->create();

    callIdentityEndpoint($this, $endpoint, $document->document_id)->assertOk();
})->with(fn () => collect([StaffRole::CEO, StaffRole::VERIFICATION])
    ->crossJoin(IDENTITY_ENDPOINTS)
    ->mapWithKeys(fn ($pair) => ["{$pair[0]->value} → {$pair[1]}" => [$pair[0], $pair[1]]])
    ->all());

it('lets a view-only staff member list, show and open documents but not decide', function () {
    identityStaff(StaffRole::OPERATIONS, ['identity.view']);
    $customer = Customer::factory()->pendingVerification()->create();
    $document = IdentityDocument::factory()->for($customer)->withImage(pngBytes())->create();

    callIdentityEndpoint($this, 'index')->assertOk();
    callIdentityEndpoint($this, 'show', $document->document_id)->assertOk();
    callIdentityEndpoint($this, 'image', $document->document_id)->assertOk();
    callIdentityEndpoint($this, 'review', $document->document_id)->assertStatus(403)->assertJsonPath('code', 'permission_denied');

    expect($document->fresh()->status->value)->toBe('pending')
        ->and($document->customer->fresh()->is_verified)->toBeFalse();
});

it('lets a review-only staff member decide but not list, show or open documents', function () {
    identityStaff(StaffRole::OPERATIONS, ['identity.review']);
    $document = IdentityDocument::factory()->withImage(pngBytes())->create();

    callIdentityEndpoint($this, 'index')->assertStatus(403);
    callIdentityEndpoint($this, 'show', $document->document_id)->assertStatus(403);
    callIdentityEndpoint($this, 'image', $document->document_id)->assertStatus(403);
    expect(DocumentViewLog::query()->count())->toBe(0);

    callIdentityEndpoint($this, 'review', $document->document_id)->assertOk();
});

it('does not reveal whether a document exists to someone who may not see it', function () {
    identityStaff(StaffRole::OPERATIONS);
    $existing = IdentityDocument::factory()->create();

    foreach (['show', 'image', 'review'] as $endpoint) {
        $missing = callIdentityEndpoint($this, $endpoint)->status();
        $present = callIdentityEndpoint($this, $endpoint, $existing->document_id)->status();

        expect([$endpoint => $missing])->toBe([$endpoint => 403])
            ->and([$endpoint => $present])->toBe([$endpoint => 403]);
    }
});

it('never accepts the customer-facing routes with a staff token or the reverse', function () {
    $staff = Staff::factory()->role(StaffRole::VERIFICATION)->create();
    $token = app(IssueTokenFamilyAction::class)->forStaff($staff)->accessToken;

    $this->bearer($token)->postJson('/api/v1/customer/me/identity-documents', ['doc_kind' => 'passport', 'upload_token' => 'x'])
        ->assertStatus(401);
});
