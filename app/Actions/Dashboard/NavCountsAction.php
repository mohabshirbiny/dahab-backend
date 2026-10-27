<?php

namespace App\Actions\Dashboard;

use App\Enums\CustomerStatus;
use App\Enums\DisputeState;
use App\Enums\ListingState;
use App\Enums\ReportState;
use App\Enums\StaffPermission;
use App\Enums\TopUpStatus;
use App\Enums\WithdrawalState;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard's navigation badges in one read (dashboard performance):
 * each count only for the holders of the code its page needs — a count the
 * viewer may not see is absent, not 0, as on /dashboard/overview. Each uses
 * the predicate of the list it badges, so the badge and the page agree:
 *
 * - customers_pending / customers_by_status — `customer.status` (Users list, its tabs)
 * - topups_open      — status pending or on hold, whatever the date (Incoming transfers)
 * - listings_in_review — `listing.state = in_review` (Listings to review, meta.counts)
 * - withdrawals_waiting — requested or under review (Withdrawals, figures.waiting_count)
 * - disputes_unresolved — open or passed on (Disputes, meta.counts)
 * - listing_reports_open — `listing_report.state = open` (Listing reports, meta.counts)
 *
 * Plain indexed counts; nothing cached.
 */
final class NavCountsAction
{
    /** @return array<string, mixed> */
    public function handle(Staff $viewer): array
    {
        $can = fn (StaffPermission ...$codes) => collect($codes)->contains(fn (StaffPermission $c) => $viewer->can($c->value));
        $out = [];

        if ($can(StaffPermission::CUSTOMER_VIEW)) {
            $byStatus = DB::table('customer')->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');
            $all = [];
            foreach (CustomerStatus::cases() as $status) {
                $all[$status->value] = (int) ($byStatus[$status->value] ?? 0);
            }
            $out['customers_pending'] = $all[CustomerStatus::PENDING_VERIFICATION->value];
            $out['customers_by_status'] = $all;
        }
        if ($can(StaffPermission::TOPUP_MATCH)) {
            $out['topups_open'] = DB::table('topup')->whereIn('status', [TopUpStatus::PENDING->value, TopUpStatus::ON_HOLD->value])->count();
        }
        if ($can(StaffPermission::LISTING_REVIEW, StaffPermission::LISTING_REQUEST_CHANGES, StaffPermission::LISTING_TAKEDOWN)) {
            $out['listings_in_review'] = DB::table('listing')->where('state', ListingState::IN_REVIEW->value)->count();
        }
        if ($can(StaffPermission::WITHDRAWAL_RELEASE)) {
            $out['withdrawals_waiting'] = DB::table('withdrawal')->whereIn('state', WithdrawalState::openValues())->count();
        }
        if ($can(StaffPermission::DISPUTE_HANDLE)) {
            $out['disputes_unresolved'] = DB::table('dispute')->whereIn('state', [DisputeState::OPEN->value, DisputeState::PASSED_ON->value])->count();
        }
        if ($can(StaffPermission::LISTING_REPORT_HANDLE)) {
            $out['listing_reports_open'] = DB::table('listing_report')->where('state', ReportState::OPEN->value)->count();
        }

        return $out;
    }
}
