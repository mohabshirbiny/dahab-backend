<?php

namespace App\Actions\BuyRequests;

use App\Enums\BuyRequestState;
use App\Models\BuyRequest;
use App\Support\BuyRequests\PlaceInLine;
use App\Support\Listings\ListingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The staff Buy requests page (spec 012 US10, FR-023, research R18;
 * product-owner decision 2026-10-01): buy requests across listings, soonest
 * reply deadline first, so operations can chase sellers before buyers are
 * released. Read-only, in the staff scope; buyers and sellers by display
 * reference only (the Resource).
 */
final class ListBuyRequestsForStaffAction
{
    /** @return array{rows: Collection<int, BuyRequest>, next_cursor: string|null, counts: array<string, int>} */
    public function handle(string $state, ?string $listingId, bool $nearExpiry, ?int $branchId, ?ListingCursor $cursor, int $perPage): array
    {
        $soon = CarbonImmutable::now()->addHours((int) config('dahab-orders.near_expiry_hours', 6));
        $ended = [BuyRequestState::RELEASED_NOT_CHOSEN, BuyRequestState::RELEASED_DECLINED, BuyRequestState::RELEASED_EXPIRED, BuyRequestState::WITHDRAWN_BY_BUYER];

        $rows = BuyRequest::query()
            ->when($state === 'queued', fn (Builder $q) => $q->where('state', BuyRequestState::QUEUED->value))
            ->when($state === 'accepted', fn (Builder $q) => $q->where('state', BuyRequestState::ACCEPTED->value))
            ->when($state === 'ended', fn (Builder $q) => $q->whereIn('state', array_map(fn ($s) => $s->value, $ended)))
            ->when($listingId !== null, fn (Builder $q) => $q->where('listing_id', $listingId))
            ->when($nearExpiry, fn (Builder $q) => $q->where('state', BuyRequestState::QUEUED->value)->where('seller_reply_deadline', '<=', $soon))
            ->when($branchId !== null, fn (Builder $q) => $q->whereHas('listing.branches', fn (Builder $b) => $b->where('branch.branch_id', $branchId)))
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('seller_reply_deadline', '>', $cursor->value)
                ->orWhere(fn (Builder $e) => $e->where('seller_reply_deadline', $cursor->value)->where('buy_request_id', '>', $cursor->id))))
            ->orderBy('seller_reply_deadline')->orderBy('buy_request_id')
            ->limit($perPage + 1)
            ->with(['listing.pieceType', 'listing.photos', 'listing.seller:customer_id,display_ref', 'buyer:customer_id,display_ref', 'order'])
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        $ahead = PlaceInLine::aheadOf($rows->where('state', BuyRequestState::QUEUED)->pluck('buy_request_id')->all());
        foreach ($rows as $row) {
            $row->setAttribute('ahead_count', $ahead[$row->buy_request_id] ?? null);
            $row->setAttribute('near_expiry', $row->state === BuyRequestState::QUEUED && $row->seller_reply_deadline->lessThanOrEqualTo($soon));
        }

        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->seller_reply_deadline->format('Y-m-d H:i:s.uP'), $last->buy_request_id))->encode()
                : null,
            'counts' => [
                'queued' => BuyRequest::query()->where('state', BuyRequestState::QUEUED->value)->count(),
                'near_expiry' => BuyRequest::query()->where('state', BuyRequestState::QUEUED->value)->where('seller_reply_deadline', '<=', $soon)->count(),
            ],
        ];
    }
}
