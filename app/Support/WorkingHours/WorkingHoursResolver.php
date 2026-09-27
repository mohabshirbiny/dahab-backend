<?php

namespace App\Support\WorkingHours;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for working-hours deadlines at a branch
 * (Technical Spec Part 3 §1, spec 004 FR-010). Endpoints and jobs call this
 * and never re-implement it: it loads the branch's timezone, weekly hours
 * and closures (its own plus all-branch holidays) and delegates the counting
 * to WorkingCalendar.
 *
 * Example: `resolve_working_deadline(accepted_at, 12 working hours, branch)`
 * for the reach-branch deadline is `addWorkingMinutes($acceptedAt, 12 * 60, $branchId)`.
 */
final class WorkingHoursResolver
{
    public function addWorkingMinutes(CarbonImmutable $start, int $minutes, int $branchId): CarbonImmutable
    {
        return $this->calendarFor($branchId, $start)->addWorkingMinutes($start, $minutes);
    }

    public function calendarFor(int $branchId, CarbonImmutable $from): WorkingCalendar
    {
        $branch = Branch::query()->findOrFail($branchId);
        $localFrom = $from->setTimezone($branch->timezone)->toDateString();
        $until = $from->setTimezone($branch->timezone)->addDays(WorkingCalendar::MAX_DAYS + 1)->toDateString();

        $week = [];
        foreach (DB::table('branch_hours')->where('branch_id', $branchId)->get() as $row) {
            $week[(int) $row->dow][] = [substr((string) $row->opens_at, 0, 5), substr((string) $row->closes_at, 0, 5)];
        }

        $closed = DB::table('branch_closure')
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->whereBetween('closure_date', [$localFrom, $until])
            ->pluck('closure_date')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->unique()
            ->values()
            ->all();

        return new WorkingCalendar($branch->timezone, $week, $closed);
    }
}
