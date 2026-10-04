<?php

namespace App\Actions\Disputes\Staff;

use App\Enums\DisputeState;
use App\Models\Dispute;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Disputes queue (spec 014 FR-008, research R17): oldest first, keyset
 * pages on `(opened_at, dispute_id)`, by state (unresolved by default), the
 * caller's own (`assigned=me`), or a `DSP-`/`DH-` reference. Counts of open and
 * passed-on disputes feed the navigation badge. Staff scope.
 */
final class ListDisputesAction
{
    /**
     * @param  list<DisputeState>  $states
     * @return array{rows: Collection<int, Dispute>, next_cursor: string|null, counts: array{open: int, passed_on: int}}
     */
    public function handle(array $states, ?string $assignedTo, ?string $q, ?ListingCursor $cursor, int $perPage): array
    {
        $query = Dispute::query()
            ->whereIn('dispute.state', array_map(fn (DisputeState $s) => $s->value, $states))
            ->with(['order:order_id,order_ref,state,listing_id,branch_id', 'raiser:customer_id,display_ref', 'assignee:staff_id,full_name']);

        if ($assignedTo !== null) {
            $query->where('dispute.assigned_to', $assignedTo);
        }

        if ($q !== null && trim($q) !== '') {
            $term = strtoupper(trim($q));
            $query->where(function ($w) use ($term) {
                $w->where('dispute.dispute_ref', $term)
                    ->orWhereIn('dispute.order_id', fn ($o) => $o->select('order_id')->from('order')->where('order_ref', $term));
            });
        }

        $page = KeysetPage::byTime($query, 'dispute', 'opened_at', 'dispute_id', false, $cursor, $perPage);

        $counts = DB::table('dispute')->whereIn('state', [DisputeState::OPEN->value, DisputeState::PASSED_ON->value])
            ->groupBy('state')->selectRaw('state, count(*) AS n')->pluck('n', 'state');

        return $page + ['counts' => [
            'open' => (int) ($counts[DisputeState::OPEN->value] ?? 0),
            'passed_on' => (int) ($counts[DisputeState::PASSED_ON->value] ?? 0),
        ]];
    }
}
