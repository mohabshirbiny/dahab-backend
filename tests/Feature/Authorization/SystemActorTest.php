<?php

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\Staff;
use App\Support\RequestContext;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Spec 002 FR-060–FR-062.

beforeEach(fn () => SystemActor::forget());

it('exists exactly once and the database refuses a second one or a founder system actor', function () {
    $system = Staff::query()->where('is_system', true)->sole();

    expect($system->full_name)->toBe('System')
        ->and($system->roles)->toBeEmpty()
        ->and($system->password)->toBeNull()
        ->and(SystemActor::id())->toBe($system->staff_id);

    // Each attempt in its own savepoint, so the first failure does not abort the second.
    expect(fn () => DB::transaction(fn () => Staff::factory()->system()->create()))
        ->toThrow(QueryException::class, 'one_system_staff');
    expect(fn () => DB::transaction(fn () => $system->forceFill(['is_founder' => true])->save()))
        ->toThrow(QueryException::class, 'staff_system_not_founder');
});

it('cannot sign in', function () {
    $this->postJson('/api/v1/dashboard/auth/login', ['email' => 'system@dahab.internal', 'password' => 'anything-at-all'])
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
});

it('is invisible to staff management and cannot be given roles', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $token = staffAccessToken(Staff::factory()->role(SeedRole::CEO)->founder()->create());
    $id = SystemActor::id();

    expect(collect($this->bearer($token)->getJson('/api/v1/dashboard/staff?per_page=50')->json('data'))->pluck('id'))
        ->not->toContain($id);

    $this->bearer($token)->putJson("/api/v1/dashboard/staff/{$id}/roles", ['roles' => ['ceo'], 'reason' => 'Should not work'])
        ->assertNotFound();

    expect(Staff::query()->findOrFail($id)->roles)->toBeEmpty();
});

it('attributes background writes to the system actor', function () {
    $row = app(RecordAuditLogAction::class)->execute(
        AuditEvent::TOKEN_FAMILY_REVOKED,
        'success',
        ['job' => 'example-sweep'],
        ctx: RequestContext::forSystem(),
    );

    expect($row)->not->toBeNull()
        ->and($row->actor_staff_id)->toBe(SystemActor::id())
        ->and($row->actor_customer_id)->toBeNull();
});
