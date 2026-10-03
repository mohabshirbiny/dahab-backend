<?php

namespace App\Actions\Withdrawals\Staff;

use App\Enums\WithdrawalState;
use App\Models\Withdrawal;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use App\Support\Withdrawals\WithdrawalListQuery;
use App\Support\Withdrawals\WithdrawalSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff Withdrawals list (spec 013 US6, FR-012): oldest first, keyset
 * pages, the page's signals, each customer's available balance, and the
 * figures of the design's header. Staff scope; relations eager-loaded.
 */
final class ListWithdrawalsAction
{
    public const RELATIONS = ['customer', 'account.checkedBy', 'reviewer', 'holder'];

    public function __construct(private readonly WithdrawalSignals $signals) {}

    /** @return array{rows: Collection<int, Withdrawal>, next_cursor: string|null, signals: array<string, array<string, mixed>>, available: array<string, string>, figures: array<string, mixed>} */
    public function handle(WithdrawalListQuery $filters, ?ListingCursor $cursor, int $perPage): array
    {
        $query = $filters->apply(Withdrawal::query()->with(self::RELATIONS));
        $page = KeysetPage::byTime($query, 'withdrawal', 'requested_at', 'withdrawal_id', false, $cursor, $perPage);

        return $page + [
            'signals' => $this->signals->for($page['rows']),
            'available' => $this->available($page['rows']),
            'figures' => $this->figures(),
        ];
    }

    /**
     * Each customer's available balance now, one query.
     *
     * @param  Collection<int, Withdrawal>  $rows
     * @return array<string, string>
     */
    public function available(Collection $rows): array
    {
        $customers = $rows->pluck('customer_id')->unique()->values()->all();
        if ($customers === []) {
            return [];
        }

        return DB::table('account AS a')->leftJoin('ledger_posting AS p', 'p.account_id', '=', 'a.account_id')
            ->whereIn('a.customer_id', $customers)->where('a.kind', 'cust_available')
            ->selectRaw('a.customer_id, COALESCE(SUM(p.amount), 0)::numeric(18,4)::text AS balance')
            ->groupBy('a.customer_id')->pluck('balance', 'customer_id')->all();
    }

    /** @return array{waiting_count: int, waiting_sum: string, released_today_count: int, released_today_sum: string, on_hold_count: int, avg_hours_to_release: string|null} */
    public function figures(): array
    {
        $open = WithdrawalState::openValues();
        $row = DB::selectOne("
            SELECT count(*) FILTER (WHERE state IN (?, ?))                                   AS waiting_count,
                   COALESCE(sum(amount) FILTER (WHERE state IN (?, ?)), 0)::numeric(18,4)::text AS waiting_sum,
                   count(*) FILTER (WHERE state = 'released' AND released_at >= ?)            AS released_today_count,
                   COALESCE(sum(amount) FILTER (WHERE state = 'released' AND released_at >= ?), 0)::numeric(18,4)::text AS released_today_sum,
                   count(*) FILTER (WHERE state = 'under_review' AND held_at IS NOT NULL)    AS on_hold_count,
                   round((avg(extract(epoch FROM released_at - requested_at)) FILTER (WHERE released_at >= ?) / 3600)::numeric, 1)::text AS avg_hours
            FROM withdrawal
        ", [...$open, ...$open, now()->startOfDay(), now()->startOfDay(), now()->subDays(30)]);

        return [
            'waiting_count' => (int) $row->waiting_count,
            'waiting_sum' => $row->waiting_sum,
            'released_today_count' => (int) $row->released_today_count,
            'released_today_sum' => $row->released_today_sum,
            'on_hold_count' => (int) $row->on_hold_count,
            'avg_hours_to_release' => $row->avg_hours,
        ];
    }

    /** One withdrawal with its ledger entries (staff detail). */
    public function show(string $withdrawalId): array
    {
        $withdrawal = Withdrawal::query()->with(self::RELATIONS)->findOrFail($withdrawalId);
        $rows = collect([$withdrawal]);

        $ledger = DB::table('ledger_transaction AS t')->join('ledger_posting AS p', 'p.ledger_txn_id', '=', 't.ledger_txn_id')
            ->join('account AS a', 'a.account_id', '=', 'p.account_id')
            ->where('t.withdrawal_id', $withdrawalId)
            ->orderBy('t.created_at')->orderBy('p.posting_id')
            ->get(['t.ledger_txn_id', 't.created_at', 'a.kind', 'p.amount'])
            ->groupBy('ledger_txn_id')
            ->map(fn ($lines, $id) => [
                'id' => $id,
                'kind' => match ($id) {
                    $withdrawal->hold_txn_id => 'hold',
                    $withdrawal->release_txn_id => 'release',
                    default => 'return',
                },
                'created_at' => CarbonImmutable::parse($lines->first()->created_at)->toIso8601String(),
                'lines' => $lines->map(fn ($l) => ['account' => $l->kind, 'amount' => bcadd((string) $l->amount, '0', 4)])->values()->all(),
            ])->values()->all();

        return [
            'withdrawal' => $withdrawal,
            'signals' => $this->signals->for($rows)[$withdrawalId] ?? [],
            'available' => $this->available($rows)[$withdrawal->customer_id] ?? '0.0000',
            'ledger' => $ledger,
        ];
    }
}
