<?php

namespace App\Actions\TopUp;

use App\Models\TopUp;
use App\Support\TopUpCursor;
use App\Support\TopUpListQuery;
use App\Support\TopUpMoney;
use Illuminate\Support\Collection;

/**
 * The Incoming transfers list (spec 009 US2, FR-015): filtered notices and
 * hand credits, newest first, keyset-paginated, with the count and the
 * claimed total of everything the filter matches. Relations are eager-loaded
 * (no N+1). Staff scope; gated by `topup.match`.
 */
final class ListTopUpsAction
{
    public const RELATIONS = ['customer', 'noticeAccount', 'receivingAccount', 'creditedBy', 'heldBy', 'rejectedBy'];

    /** @return array{rows: Collection<int, TopUp>, next_cursor: string|null, totals: array{count: int, claimed: string}} */
    public function handle(TopUpListQuery $query, ?TopUpCursor $cursor, int $perPage): array
    {
        $rows = $query->apply(TopUp::query())
            ->with(self::RELATIONS)
            ->when($cursor !== null, fn ($q) => $q->where('topup_no', '<', $cursor->no))
            ->orderByDesc('topup_no')
            ->limit($perPage + 1)
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        $totals = $query->apply(TopUp::query())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(claimed_amount), 0)::text AS claimed')
            ->toBase()
            ->first();

        return [
            'rows' => $rows,
            'next_cursor' => $more ? (new TopUpCursor((int) $rows->last()->topup_no))->encode() : null,
            'totals' => ['count' => (int) $totals->n, 'claimed' => TopUpMoney::format($totals->claimed)],
        ];
    }
}
