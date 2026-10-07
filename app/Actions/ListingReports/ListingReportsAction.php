<?php

namespace App\Actions\ListingReports;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\DecideListingAction;
use App\Enums\AuditEvent;
use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Enums\ReportReason;
use App\Enums\ReportState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\Staff;
use App\Notifications\ListingReportNotification;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listing reports (spec 017 FR-052, FR-053, research R9). A verified customer
 * reports another seller's piece on the market; staff dismiss the report or
 * take the piece down through the spec 010 take-down, which actions every open
 * report on it. A piece that leaves the market another way has its reports
 * closed as `listing_gone` by the sweep. Each reporter gets a generic note; the
 * seller never learns who reported.
 */
final class ListingReportsAction
{
    public function __construct(
        private readonly DecideListingAction $decide,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function report(Customer $reporter, string $listingId, ReportReason $reason, ?string $note, ?RequestContext $ctx = null): ListingReport
    {
        $listing = DatabaseActor::market(fn () => Listing::query()->publiclyVisible()->find($listingId));
        if ($listing === null || $listing->seller_id === $reporter->customer_id) {
            throw DomainApiException::listingNotReportable();
        }

        return DB::transaction(function () use ($reporter, $listing, $reason, $note, $ctx) {
            $report = new ListingReport;
            $report->forceFill([
                'listing_id' => $listing->listing_id,
                'reporter_id' => $reporter->customer_id,
                'reason' => $reason,
                'note' => $note,
            ])->save();
            $report->refresh();

            $this->audit->execute(AuditEvent::LISTING_REPORT_CREATED, 'success',
                ['reference' => $report->reference(), 'listing_id' => $listing->listing_id, 'reason' => $reason->value],
                'listing_report', $report->report_id, $ctx, actorCustomerId: $reporter->customer_id);

            return $report;
        });
    }

    /** @return array{items: Collection<int, ListingReport>, counts: array<string, int>} */
    public function list(?ReportState $state, ?ReportReason $reason, int $limit = 100): array
    {
        $items = ListingReport::query()
            ->with(['listing.pieceType', 'reporter:customer_id,display_ref', 'handler:staff_id,full_name'])
            ->when($state !== null, fn (Builder $q) => $q->where('state', $state->value))
            ->when($reason !== null, fn (Builder $q) => $q->where('reason', $reason->value))
            ->orderByRaw("CASE WHEN state = 'open' THEN 0 ELSE 1 END")->orderByDesc('created_at')
            ->limit($limit)->get();

        $counts = ListingReport::query()->selectRaw('state, count(*) AS n')->groupBy('state')->pluck('n', 'state')
            ->map(fn ($n) => (int) $n)->all();

        return ['items' => $items, 'counts' => array_merge(array_fill_keys(array_map(fn (ReportState $s) => $s->value, ReportState::cases()), 0), $counts)];
    }

    public function show(string $id): ListingReport
    {
        return ListingReport::query()->with(['listing.pieceType', 'reporter:customer_id,display_ref', 'handler:staff_id,full_name'])->findOrFail($id);
    }

    public function dismiss(Staff $staff, string $id, string $note, ?RequestContext $ctx = null): ListingReport
    {
        return DB::transaction(function () use ($staff, $id, $note, $ctx) {
            $report = $this->lockOpen($id);
            $report->forceFill(['state' => ReportState::DISMISSED, 'handled_by' => $staff->staff_id, 'handled_at' => now(), 'staff_note' => $note])->save();

            $this->audit->execute(AuditEvent::LISTING_REPORT_DISMISSED, 'success',
                ['reference' => $report->reference(), 'listing_id' => $report->listing_id],
                'listing_report', $report->report_id, $ctx, actorStaffId: $staff->staff_id, reason: $note);
            $this->tellReporters([$report]);

            return $report;
        });
    }

    /** Take the piece down (spec 010) and action every open report on it. */
    public function takeDown(Staff $staff, string $id, string $reason, ?RequestContext $ctx = null): ListingReport
    {
        return DB::transaction(function () use ($staff, $id, $reason, $ctx) {
            $report = $this->lockOpen($id);
            $this->decide->handle($staff, $report->listing_id, ListingDecision::TAKEN_DOWN, $reason, $ctx);

            $open = ListingReport::query()->where('listing_id', $report->listing_id)->where('state', ReportState::OPEN->value)
                ->orderBy('report_id')->lockForUpdate()->get();
            foreach ($open as $r) {
                $r->forceFill(['state' => ReportState::ACTIONED, 'handled_by' => $staff->staff_id, 'handled_at' => now(), 'staff_note' => $reason])->save();
            }

            $this->audit->execute(AuditEvent::LISTING_REPORT_ACTIONED, 'success',
                ['reference' => $report->reference(), 'listing_id' => $report->listing_id, 'reports' => $open->map(fn (ListingReport $r) => $r->reference())->all()],
                'listing_report', $report->report_id, $ctx, actorStaffId: $staff->staff_id, reason: $reason);
            $this->tellReporters($open->all());

            return $report->refresh();
        });
    }

    /** The sweep: reports whose piece is no longer on the market. @return int how many were closed */
    public function closeGone(): int
    {
        return DB::transaction(function () {
            $gone = ListingReport::query()->where('listing_report.state', ReportState::OPEN->value)
                ->whereHas('listing', fn (Builder $q) => $q->whereNotIn('state', array_map(fn (ListingState $s) => $s->value, ListingState::PUBLIC)))
                ->orderBy('report_id')->lockForUpdate()->get();
            foreach ($gone as $r) {
                $r->forceFill(['state' => ReportState::LISTING_GONE, 'handled_at' => now()])->save();
            }
            $this->tellReporters($gone->all());

            return $gone->count();
        });
    }

    private function lockOpen(string $id): ListingReport
    {
        $report = ListingReport::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($report->state !== ReportState::OPEN) {
            throw DomainApiException::reportNotOpen();
        }

        return $report;
    }

    /** @param  list<ListingReport>  $reports */
    private function tellReporters(array $reports): void
    {
        $byReporter = collect($reports)->groupBy('reporter_id');
        DB::afterCommit(function () use ($byReporter) {
            foreach ($byReporter as $reporterId => $rows) {
                Customer::query()->find($reporterId)?->notify(new ListingReportNotification($rows->first()->reference()));
            }
        });
    }
}
