<?php

namespace App\Actions\Orders\Staff;

use App\Enums\ExtensionRequestState;
use App\Models\OrderExtensionRequest;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sellers' requests for more time for staff (spec 014 FR-024, FR-027, research
 * R17): *More time requested* (waiting, oldest first) and *Extension requests*
 * of a month. Each row carries the order, its branch and running deadline, the
 * seller's display reference, and how many extensions the order had before
 * the request (`extensions_before`, one query per page).
 */
final class ListExtensionRequestsAction
{
    /**
     * @param  list<ExtensionRequestState>  $states
     * @return array{rows: Collection<int, OrderExtensionRequest>, next_cursor: string|null}
     */
    public function handle(array $states, ?string $month, ?ListingCursor $cursor, int $perPage): array
    {
        $query = OrderExtensionRequest::query()
            ->whereIn('order_extension_request.state', array_map(fn (ExtensionRequestState $s) => $s->value, $states))
            ->with([
                'order:order_id,order_ref,state,branch_id,seller_id,reach_branch_deadline',
                'order.branch:branch_id,name_en,name_ar',
                'order.seller:customer_id,display_ref',
                'answerer:staff_id,full_name',
            ]);

        if ($month !== null) {
            $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', 'Africa/Cairo');
            $query->where('order_extension_request.requested_at', '>=', $start)
                ->where('order_extension_request.requested_at', '<', $start->addMonth());
        }

        $page = KeysetPage::byTime($query, 'order_extension_request', 'requested_at', 'request_id', false, $cursor, $perPage);

        $ids = $page['rows']->pluck('request_id')->all();
        $before = $ids === [] ? collect() : DB::table('order_extension_request as r')
            ->join('order_deadline_extension as e', fn ($j) => $j->on('e.order_id', '=', 'r.order_id')->on('e.granted_at', '<', 'r.requested_at'))
            ->whereIn('r.request_id', $ids)->groupBy('r.request_id')
            ->selectRaw('r.request_id, count(*) AS n')->pluck('n', 'request_id');

        foreach ($page['rows'] as $row) {
            $row->setAttribute('extensions_before', (int) ($before[$row->request_id] ?? 0));
        }

        return $page;
    }
}
