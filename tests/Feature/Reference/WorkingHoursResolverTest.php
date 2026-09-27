<?php

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Support\WorkingHours\WorkingHoursResolver;
use App\Support\WorkingHours\WorkingHoursUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Spec 004 US2: the resolver reads the branch's own week, its closures and
// all-branch holidays from the database. 2026-10-01 is a Thursday.

function deadlineAt(Branch $branch, string $startLocal, int $minutes): string
{
    return app(WorkingHoursResolver::class)
        ->addWorkingMinutes(CarbonImmutable::parse($startLocal, 'Africa/Cairo'), $minutes, $branch->branch_id)
        ->setTimezone('Africa/Cairo')
        ->format('Y-m-d H:i');
}

it('counts the design example on a real branch', function () {
    expect(deadlineAt(Branch::factory()->create(), '2026-10-01 16:00', 12 * 60))->toBe('2026-10-05 12:00');
});

it('honours the branch\'s own closure but not another branch\'s', function () {
    $mine = Branch::factory()->create();
    $other = Branch::factory()->create();
    BranchClosure::factory()->create(['branch_id' => $other->branch_id, 'closure_date' => '2026-10-04']);

    expect(deadlineAt($mine, '2026-10-01 16:00', 240))->toBe('2026-10-04 12:00');

    BranchClosure::factory()->create(['branch_id' => $mine->branch_id, 'closure_date' => '2026-10-04']);

    expect(deadlineAt($mine, '2026-10-01 16:00', 240))->toBe('2026-10-05 12:00');
});

it('honours an all-branch national holiday', function () {
    $branch = Branch::factory()->create();
    BranchClosure::factory()->create(['branch_id' => null, 'closure_date' => '2026-10-04']);

    expect(deadlineAt($branch, '2026-10-01 16:00', 240))->toBe('2026-10-05 12:00');
});

it('refuses for a branch that is never open', function () {
    deadlineAt(Branch::factory()->closedAllWeek()->create(), '2026-10-01 10:00', 60);
})->throws(WorkingHoursUnavailable::class);
