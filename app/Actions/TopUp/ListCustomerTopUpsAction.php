<?php

namespace App\Actions\TopUp;

use App\Enums\TopUpStatus;
use App\Models\TopUp;
use App\Support\TopUpCursor;
use Illuminate\Support\Collection;

/**
 * A customer's own notices and hand credits, newest first (spec 009 FR-012).
 * Row-level security already limits the rows to the customer; the explicit
 * predicate keeps the query right in any scope.
 */
final class ListCustomerTopUpsAction
{
    /** @return array{rows: Collection<int, TopUp>, next_cursor: string|null} */
    public function handle(string $customerId, ?TopUpStatus $status, ?TopUpCursor $cursor, int $perPage): array
    {
        $rows = TopUp::query()
            ->where('customer_id', $customerId)
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->when($cursor !== null, fn ($q) => $q->where('topup_no', '<', $cursor->no))
            ->orderByDesc('topup_no')
            ->limit($perPage + 1)
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        return [
            'rows' => $rows,
            'next_cursor' => $more ? (new TopUpCursor((int) $rows->last()->topup_no))->encode() : null,
        ];
    }
}
