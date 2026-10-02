<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionOutcome;
use App\Models\InspectionResult;
use App\Models\Staff;
use App\Support\Listings\ListingCursor;
use App\Support\Orders\StaffBranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The inspection results list (spec 012 US9, FR-022, research R18; the
 * design's Inspections page): every result, newest first, the corrected ones
 * flagged, between two Cairo dates (the last 30 days by default), by branch
 * and outcome. Staff with an assigned branch see that branch only. The rows
 * carry measurements and outcomes, never money.
 */
final class ListInspectionsAction
{
    public function __construct(private readonly StaffBranchScope $branches) {}

    /** @return array{rows: Collection<int, InspectionResult>, next_cursor: string|null, superseded: list<string>} */
    public function handle(Staff $staff, ?string $from, ?string $to, ?int $branchId, ?InspectionOutcome $outcome, ?ListingCursor $cursor, int $perPage): array
    {
        $tz = 'Africa/Cairo';
        $start = $from !== null ? CarbonImmutable::parse($from, $tz)->startOfDay() : CarbonImmutable::now($tz)->subDays(30)->startOfDay();
        $end = $to !== null ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $branch = $this->branches->branchFilterFor($staff, $branchId);

        $rows = InspectionResult::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($branch !== null, fn (Builder $q) => $q->where('branch_id', $branch))
            ->when($outcome !== null, fn (Builder $q) => $q->where('outcome', $outcome->value))
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('created_at', '<', $cursor->value)
                ->orWhere(fn (Builder $e) => $e->where('created_at', $cursor->value)->where('inspection_id', '<', $cursor->id))))
            ->orderByDesc('created_at')->orderByDesc('inspection_id')
            ->limit($perPage + 1)
            ->with(['order.listing.pieceType', 'order.seller:customer_id,display_ref', 'order.stateChanges', 'branch'])
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $superseded = InspectionResult::query()->whereIn('supersedes_id', $rows->pluck('inspection_id'))->pluck('supersedes_id')->all();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->created_at->format('Y-m-d H:i:s.uP'), $last->inspection_id))->encode()
                : null,
            'superseded' => $superseded,
        ];
    }
}
