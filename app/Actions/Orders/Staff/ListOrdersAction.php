<?php

namespace App\Actions\Orders\Staff;

use App\Enums\OrderState;
use App\Models\Order;
use App\Support\Listings\ListingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff Orders page (spec 012 US9, FR-022, research R18): every order,
 * newest first, keyset-paged, filtered by group (where it is stuck), past its
 * running deadline, branch, and a search by order reference or a customer's
 * display reference; with a count per group. Read in the staff scope.
 */
final class ListOrdersAction
{
    public const GROUPS = ['open', 'waiting_seller', 'at_igi', 'needs_decision', 'waiting_balance', 'ready_to_collect', 'returns', 'closed', 'all'];

    /** @return array{rows: Collection<int, Order>, next_cursor: string|null, counts: array<string, int>} */
    public function handle(string $group, bool $pastDeadline, ?int $branchId, ?string $q, ?ListingCursor $cursor, int $perPage): array
    {
        $base = fn () => Order::query()
            ->when($branchId !== null, fn (Builder $b) => $b->where('order.branch_id', $branchId))
            ->when($q !== null && $q !== '', fn (Builder $b) => $this->search($b, (string) $q));

        $query = $base();
        $this->group($query, $group);
        if ($pastDeadline) {
            $this->pastDeadline($query);
        }

        $rows = $query
            ->when($cursor !== null, fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('accepted_at', '<', $cursor->value)
                ->orWhere(fn (Builder $e) => $e->where('accepted_at', $cursor->value)->where('order_id', '<', $cursor->id))))
            ->orderByDesc('accepted_at')->orderByDesc('order_id')
            ->limit($perPage + 1)
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $rows->load(['listing.pieceType', 'listing.photos', 'branch', 'buyRequest', 'seller:customer_id,display_ref',
            'buyer:customer_id,display_ref', 'sellerReturn', 'collection', 'extensionRequests']);

        $counts = [];
        foreach (array_diff(self::GROUPS, ['all', 'closed']) as $g) {
            $c = $base();
            $this->group($c, $g);
            $counts[$g] = $c->count();
        }
        $pd = $base();
        $this->pastDeadline($pd);
        $counts['past_deadline'] = $pd->count();

        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->accepted_at->format('Y-m-d H:i:s.uP'), $last->order_id))->encode()
                : null,
            'counts' => $counts,
        ];
    }

    private function group(Builder $query, string $group): void
    {
        $states = fn (OrderState ...$s) => array_map(fn (OrderState $x) => $x->value, $s);

        match ($group) {
            'open' => $query->whereIn('state', $states(...OrderState::open())),
            'waiting_seller' => $query->where('state', OrderState::AWAITING_DELIVERY->value),
            'at_igi' => $query->whereIn('state', $states(OrderState::AT_INSPECTION, OrderState::INSPECTION_PASSED)),
            'needs_decision' => $query->where('state', OrderState::WEIGHT_ADJUST_PENDING->value),
            'waiting_balance' => $query->where('state', OrderState::AWAITING_BALANCE->value),
            'ready_to_collect' => $query->where('state', OrderState::READY_TO_COLLECT->value),
            'returns' => $query->whereHas('sellerReturn', fn (Builder $r) => $r->whereNull('collected_at')->whereNull('relisted_at')),
            'closed' => $query->whereNotIn('state', $states(...OrderState::open())),
            default => null,
        };
    }

    /** The deadline running in the order's state has passed (research R18). */
    private function pastDeadline(Builder $query): void
    {
        $now = CarbonImmutable::now();

        $query->where(fn (Builder $w) => $w
            ->where(fn (Builder $x) => $x->where('state', OrderState::AWAITING_DELIVERY->value)->where('reach_branch_deadline', '<', $now))
            ->orWhere(fn (Builder $x) => $x->where('state', OrderState::WEIGHT_ADJUST_PENDING->value)->where('decision_due_deadline', '<', $now))
            ->orWhere(fn (Builder $x) => $x->where('state', OrderState::AWAITING_BALANCE->value)->where('balance_due_deadline', '<', $now))
            ->orWhere(fn (Builder $x) => $x->where('state', OrderState::READY_TO_COLLECT->value)->where('collect_deadline', '<', $now))
            ->orWhereHas('sellerReturn', fn (Builder $r) => $r->whereNull('collected_at')->whereNull('relisted_at')->where('return_deadline', '<', $now)));
    }

    /** An order reference (any case), or a buyer's or seller's display reference. */
    private function search(Builder $query, string $q): void
    {
        $q = trim($q);
        $customers = DB::table('customer')->where('display_ref', $q)->pluck('customer_id')->all();

        $query->where(fn (Builder $w) => $w
            ->whereRaw('upper(order_ref) = upper(?)', [$q])
            ->orWhereIn('buyer_id', $customers)
            ->orWhereIn('seller_id', $customers));
    }
}
