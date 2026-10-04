<?php

namespace App\Support\Wallet;

use App\Support\DatabaseActor;
use Illuminate\Support\Facades\DB;

/**
 * What each buy request holds on its buyer's held account now (spec 015
 * FR-019, research R12): the sum of the held postings of every entry that
 * names the request — the deposit hold, a release, a forfeit, the balance
 * payment. An order holds what its request holds. One grouped query, in the
 * money service's `ledger` read scope; the caller decides whose requests (a
 * customer only ever asks for their own). Scoped to the request: a list primes
 * its page once and each Resource reads from the memo.
 */
final class HeldByRequest
{
    /** @var array<string, string> */
    private array $memo = [];

    /**
     * Read these requests now (one query) for the Resources that follow.
     *
     * @param  list<string|null>  $requestIds
     */
    public function prime(array $requestIds): void
    {
        $this->memo = self::for($requestIds) + $this->memo;
    }

    /** A primed amount, or a fresh read. */
    public function of(string $requestId): string
    {
        return $this->memo[$requestId] ?? self::for([$requestId])[$requestId];
    }

    /**
     * @param  list<string|null>  $requestIds
     * @return array<string, string> request id => amount held (4 places), every id present
     */
    public static function for(array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter($requestIds)));
        if ($requestIds === []) {
            return [];
        }

        $sums = DatabaseActor::ledger(fn () => DB::table('ledger_posting AS p')
            ->join('account AS a', 'a.account_id', '=', 'p.account_id')
            ->join('ledger_transaction AS t', 't.ledger_txn_id', '=', 'p.ledger_txn_id')
            ->where('a.kind', 'cust_held')
            ->whereIn('t.buy_request_id', $requestIds)
            ->groupBy('t.buy_request_id')
            ->selectRaw('t.buy_request_id::text AS id, SUM(p.amount)::numeric(18,4)::text AS held')
            ->pluck('held', 'id')->all());

        return array_combine($requestIds, array_map(fn (string $id) => $sums[$id] ?? '0.0000', $requestIds));
    }
}
