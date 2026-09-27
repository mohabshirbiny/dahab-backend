<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 004 US1: branches and their weekly hours (FR-001..FR-004).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ops = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    $this->token = staffAccessToken($this->ops);
});

function branchBody(array $overrides = []): array
{
    return array_merge([
        'name_en' => 'IGI Heliopolis',
        'name_ar' => 'فرع مصر الجديدة',
        'address_en' => '12 Baghdad St',
        'address_ar' => '12 شارع بغداد',
        'timezone' => 'Africa/Cairo',
        'hours' => [
            ['dow' => 0, 'opens_at' => '10:00', 'closes_at' => '14:00'],
            ['dow' => 0, 'opens_at' => '16:00', 'closes_at' => '20:00'],
            ['dow' => 1, 'opens_at' => '10:00', 'closes_at' => '18:00'],
        ],
    ], $overrides);
}

it('lists branches with their week, sorted by day then opening', function () {
    $branch = Branch::factory()->create(['name_en' => 'IGI Nasr City']);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/branches')
        ->assertOk()
        ->assertJsonPath('data.0.id', $branch->branch_id)
        ->assertJsonPath('data.0.name_en', 'IGI Nasr City')
        ->assertJsonPath('data.0.timezone', 'Africa/Cairo')
        ->assertJsonPath('data.0.is_enabled', true)
        ->assertJsonCount(5, 'data.0.hours')
        ->assertJsonPath('data.0.hours.0', ['dow' => 0, 'opens_at' => '10:00', 'closes_at' => '18:00']);
});

it('creates a branch with a split day and audits it', function () {
    $response = $this->bearer($this->token)->postJson('/api/v1/dashboard/branches', branchBody())
        ->assertCreated()
        ->assertJsonPath('data.name_en', 'IGI Heliopolis')
        ->assertJsonPath('data.is_enabled', true)
        ->assertJsonPath('data.hours', [
            ['dow' => 0, 'opens_at' => '10:00', 'closes_at' => '14:00'],
            ['dow' => 0, 'opens_at' => '16:00', 'closes_at' => '20:00'],
            ['dow' => 1, 'opens_at' => '10:00', 'closes_at' => '18:00'],
        ]);

    $audit = AuditLog::query()->where('action', 'reference.branch.created')->sole();
    expect($audit->actor_staff_id)->toBe($this->ops->staff_id)
        ->and($audit->after_json['branch_id'])->toBe($response->json('data.id'))
        ->and($audit->after_json['hours'])->toHaveCount(3);
});

it('refuses overlapping, reversed or malformed hours and a bad timezone', function (array $hours, string $errorKey) {
    $this->bearer($this->token)->postJson('/api/v1/dashboard/branches', branchBody(['hours' => $hours]))
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($errorKey);

    expect(Branch::query()->count())->toBe(0);
})->with([
    'overlap' => [[['dow' => 2, 'opens_at' => '10:00', 'closes_at' => '14:00'], ['dow' => 2, 'opens_at' => '13:00', 'closes_at' => '18:00']], 'hours.1'],
    'same start' => [[['dow' => 2, 'opens_at' => '10:00', 'closes_at' => '14:00'], ['dow' => 2, 'opens_at' => '10:00', 'closes_at' => '12:00']], 'hours.1'],
    'reversed' => [[['dow' => 2, 'opens_at' => '18:00', 'closes_at' => '10:00']], 'hours.0.closes_at'],
    'bad day' => [[['dow' => 7, 'opens_at' => '10:00', 'closes_at' => '18:00']], 'hours.0.dow'],
    'bad time' => [[['dow' => 1, 'opens_at' => '25:00', 'closes_at' => '26:00']], 'hours.0.opens_at'],
]);

it('refuses an unknown timezone', function () {
    $this->bearer($this->token)->postJson('/api/v1/dashboard/branches', branchBody(['timezone' => 'Mars/Olympus']))
        ->assertUnprocessable()->assertJsonValidationErrors('timezone');
});

it('edits fields and audits only what changed', function () {
    $branch = Branch::factory()->create(['name_en' => 'Old name']);

    $this->bearer($this->token)->patchJson("/api/v1/dashboard/branches/{$branch->branch_id}", ['name_en' => 'New name', 'address_en' => $branch->address_en])
        ->assertOk()->assertJsonPath('data.name_en', 'New name')->assertJsonCount(5, 'data.hours');

    $audit = AuditLog::query()->where('action', 'reference.branch.updated')->sole();
    expect($audit->before_json)->toBe(['name_en' => 'Old name'])
        ->and($audit->after_json['changes'])->toBe(['name_en' => 'New name'])
        ->and(AuditLog::query()->where('action', 'reference.branch.hours_replaced')->exists())->toBeFalse();
});

it('replaces the whole week and audits before and after', function () {
    $branch = Branch::factory()->create();

    $this->bearer($this->token)->patchJson("/api/v1/dashboard/branches/{$branch->branch_id}", [
        'hours' => [['dow' => 6, 'opens_at' => '11:00', 'closes_at' => '19:00']],
    ])->assertOk()->assertJsonPath('data.hours', [['dow' => 6, 'opens_at' => '11:00', 'closes_at' => '19:00']]);

    $audit = AuditLog::query()->where('action', 'reference.branch.hours_replaced')->sole();
    expect($audit->before_json['hours'])->toHaveCount(5)
        ->and($audit->after_json['hours'])->toBe([['dow' => 6, 'opens_at' => '11:00', 'closes_at' => '19:00']]);
});

it('disables a branch but keeps it listed', function () {
    $branch = Branch::factory()->create();

    $this->bearer($this->token)->patchJson("/api/v1/dashboard/branches/{$branch->branch_id}", ['is_enabled' => false])
        ->assertOk()->assertJsonPath('data.is_enabled', false);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/branches')->assertJsonPath('data.0.is_enabled', false);
});

it('returns 404 for an unknown branch and refuses an empty patch', function () {
    $this->bearer($this->token)->patchJson('/api/v1/dashboard/branches/999', ['name_en' => 'X'])->assertNotFound();

    $branch = Branch::factory()->create();
    $this->bearer($this->token)->patchJson("/api/v1/dashboard/branches/{$branch->branch_id}", [])->assertUnprocessable();
});

it('lets finance view but not manage branches', function () {
    $finance = staffAccessToken(Staff::factory()->role(SeedRole::FINANCE)->create());

    $this->bearer($finance)->getJson('/api/v1/dashboard/branches')->assertOk();
    $this->bearer($finance)->postJson('/api/v1/dashboard/branches', branchBody())
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('hides branches from staff without reference.view', function () {
    $verifier = staffAccessToken(Staff::factory()->role(SeedRole::VERIFICATION)->create());

    $this->bearer($verifier)->getJson('/api/v1/dashboard/branches')->assertForbidden();
});
