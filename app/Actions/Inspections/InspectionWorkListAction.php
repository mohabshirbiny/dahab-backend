<?php

namespace App\Actions\Inspections;

use App\Enums\OrderState;
use App\Models\Order;
use App\Models\Staff;
use App\Support\Listings\ListingCursor;
use App\Support\Orders\StaffBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The branch work list (spec 012 FR-010, research R18): pieces to receive,
 * inspect, correct or hand over (to the buyer, or back to the seller) at the
 * caller's branch — every branch for staff with no assigned branch, who may
 * narrow it. Oldest first, so the longest-waiting piece is on top.
 */
final class InspectionWorkListAction
{
    public function __construct(private readonly StaffBranchScope $branches) {}

    /** @return array{rows: Collection<int, Order>, next_cursor: string|null} */
    public function handle(Staff $staff, ?int $branchId, ?ListingCursor $cursor, int $perPage): array
    {
        $branch = $this->branches->branchFilterFor($staff, $branchId);

        $rows = Order::query()
            ->when($branch !== null, fn (Builder $q) => $q->where('branch_id', $branch))
            ->where(fn (Builder $q) => $q
                ->whereIn('state', [
                    OrderState::AWAITING_DELIVERY->value, OrderState::AT_INSPECTION->value,
                    OrderState::WEIGHT_ADJUST_PENDING->value, OrderState::AWAITING_BALANCE->value,
                ])
                ->orWhere(fn (Builder $r) => $r->where('state', OrderState::READY_TO_COLLECT->value)
                    ->whereHas('collection', fn (Builder $c) => $c->whereNull('collected_at')))
                ->orWhereHas('sellerReturn', fn (Builder $s) => $s->whereNull('collected_at')->whereNull('relisted_at')))
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('accepted_at', '>', $cursor->value)
                ->orWhere(fn (Builder $e) => $e->where('accepted_at', $cursor->value)->where('order_id', '>', $cursor->id))))
            ->orderBy('accepted_at')->orderBy('order_id')
            ->limit($perPage + 1)
            ->with(['listing.pieceType', 'branch', 'inspections', 'collection', 'sellerReturn', 'stateChanges'])
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->accepted_at->format('Y-m-d H:i:s.uP'), $last->order_id))->encode()
                : null,
        ];
    }
}
