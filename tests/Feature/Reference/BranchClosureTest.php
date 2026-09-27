<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 004 US1: full-day closures and all-branch holidays (FR-003, edge cases).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->token = staffAccessToken(Staff::factory()->role(SeedRole::COO)->create());
    $this->branch = Branch::factory()->create();
    $this->future = CarbonImmutable::now('Africa/Cairo')->addDays(10)->toDateString();
});

it('adds a branch closure and an all-branch holiday, and lists them by date', function () {
    $later = CarbonImmutable::now('Africa/Cairo')->addDays(20)->toDateString();

    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', [
        'branch_id' => null, 'closure_date' => $later, 'reason_en' => 'Sinai Liberation Day', 'reason_ar' => 'عيد تحرير سيناء',
    ])->assertCreated()->assertJsonPath('data.branch_id', null)->assertJsonPath('data.closure_date', $later);

    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', [
        'branch_id' => $this->branch->branch_id, 'closure_date' => $this->future,
    ])->assertCreated()->assertJsonPath('data.branch_id', $this->branch->branch_id);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/branch-closures')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.closure_date', $this->future)
        ->assertJsonPath('data.1.reason_en', 'Sinai Liberation Day');

    expect(AuditLog::query()->where('action', 'reference.closure.added')->count())->toBe(2);
});

it('refuses a duplicate for the same scope, including all branches', function (bool $allBranches) {
    $body = ['branch_id' => $allBranches ? null : $this->branch->branch_id, 'closure_date' => $this->future];

    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', $body)->assertCreated();
    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', $body)
        ->assertStatus(409)->assertJsonPath('code', 'closure_exists');
})->with(['one branch' => false, 'all branches' => true]);

it('allows the same date for a branch and for all branches', function () {
    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', ['branch_id' => null, 'closure_date' => $this->future])->assertCreated();
    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', ['branch_id' => $this->branch->branch_id, 'closure_date' => $this->future])->assertCreated();
});

it('refuses a past date or an unknown branch', function () {
    $yesterday = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();

    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', ['branch_id' => null, 'closure_date' => $yesterday])
        ->assertUnprocessable()->assertJsonValidationErrors('closure_date');

    $this->bearer($this->token)->postJson('/api/v1/dashboard/branch-closures', ['branch_id' => 999, 'closure_date' => $this->future])
        ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
});

it('removes a future closure and audits it', function () {
    $closure = BranchClosure::factory()->create(['branch_id' => $this->branch->branch_id, 'closure_date' => $this->future]);

    $this->bearer($this->token)->deleteJson("/api/v1/dashboard/branch-closures/{$closure->closure_id}")->assertNoContent();

    expect(BranchClosure::query()->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'reference.closure.removed')->sole();
    expect($audit->before_json['closure_date'])->toBe($this->future);
});

it('refuses to remove a closure dated today or earlier', function (int $daysAgo) {
    $closure = BranchClosure::factory()->create([
        'branch_id' => null,
        'closure_date' => CarbonImmutable::now('Africa/Cairo')->subDays($daysAgo)->toDateString(),
    ]);

    $this->bearer($this->token)->deleteJson("/api/v1/dashboard/branch-closures/{$closure->closure_id}")
        ->assertStatus(409)->assertJsonPath('code', 'closure_in_past');

    expect(BranchClosure::query()->count())->toBe(1);
})->with(['today' => 0, 'last week' => 7]);

it('returns 404 for an unknown closure', function () {
    $this->bearer($this->token)->deleteJson('/api/v1/dashboard/branch-closures/999')->assertNotFound();
});

it('needs branches.manage to change closures', function () {
    $finance = staffAccessToken(Staff::factory()->role(SeedRole::FINANCE)->create());

    $this->bearer($finance)->getJson('/api/v1/dashboard/branch-closures')->assertOk();
    $this->bearer($finance)->postJson('/api/v1/dashboard/branch-closures', ['branch_id' => null, 'closure_date' => $this->future])
        ->assertForbidden();
});
