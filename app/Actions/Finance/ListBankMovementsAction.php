<?php

namespace App\Actions\Finance;

use App\Enums\BankMovementKind;
use App\Models\BankMovement;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The movements recorded by hand (spec 015 FR-011; the design's table):
 * filtered by the statement date and the kind, newest recorded first, with the
 * period's money in and out.
 */
final class ListBankMovementsAction
{
    /** @return Builder<BankMovement> */
    public static function query(string $from, string $to, ?BankMovementKind $kind): Builder
    {
        return BankMovement::query()->with('recorder')
            ->whereBetween('bank_movement.occurred_on', [$from, $to])
            ->when($kind !== null, fn (Builder $q) => $q->where('bank_movement.kind', $kind->value));
    }

    /** @return array{rows: Collection<int, BankMovement>, next_cursor: string|null, totals: array{in: string, out: string}} */
    public function handle(string $from, string $to, ?BankMovementKind $kind, ?ListingCursor $cursor, int $perPage): array
    {
        $page = KeysetPage::byTime(self::query($from, $to, $kind), 'bank_movement', 'recorded_at', 'movement_id', true, $cursor, $perPage);

        $totals = self::query($from, $to, $kind)->toBase()->selectRaw('
            COALESCE(SUM(amount) FILTER (WHERE amount > 0), 0)::numeric(18,4)::text AS money_in,
            (-COALESCE(SUM(amount) FILTER (WHERE amount < 0), 0))::numeric(18,4)::text AS money_out')->first();

        return $page + ['totals' => ['in' => $totals->money_in, 'out' => $totals->money_out]];
    }
}
