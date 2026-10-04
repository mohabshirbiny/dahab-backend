<?php

namespace App\Support\Finance;

use App\Enums\AccountKind;
use App\Models\Account;
use App\Models\BankMovement;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Models\Withdrawal;
use App\Support\DatabaseActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every posting on the bank account in a Cairo period (spec 015 FR-011,
 * research R7), ordered by the posting (monotonic, so entries stamped at the
 * same instant keep their order), each with its source: a top-up (matched
 * notice or credited by hand), a released withdrawal, a movement recorded by
 * hand, or a reversal. The bank's cash is −SUM(bank) (spec 008 R15), so money
 * in is a negative posting.
 */
final class BankBookQuery
{
    private string $bank;

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->bank = DatabaseActor::ledger(fn () => Account::internal(AccountKind::BANK));
        $this->start = CarbonImmutable::parse($from, 'Africa/Cairo')->startOfDay();
        $this->end = CarbonImmutable::parse($to, 'Africa/Cairo')->addDay()->startOfDay();
    }

    /** @return array{opening: string, in: string, out: string, closing: string} */
    public function summary(): array
    {
        $row = DB::selectOne('
            SELECT (-COALESCE(SUM(p.amount) FILTER (WHERE t.created_at < ?), 0))::numeric(18,4)::text AS opening,
                   (-COALESCE(SUM(p.amount) FILTER (WHERE t.created_at >= ? AND t.created_at < ? AND p.amount < 0), 0))::numeric(18,4)::text AS money_in,
                   COALESCE(SUM(p.amount) FILTER (WHERE t.created_at >= ? AND t.created_at < ? AND p.amount > 0), 0)::numeric(18,4)::text AS money_out
            FROM ledger_posting p JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
            WHERE p.account_id = ? AND t.created_at < ?
        ', [$this->start, $this->start, $this->end, $this->start, $this->end, $this->bank, $this->end]);

        return [
            'opening' => $row->opening,
            'in' => $row->money_in,
            'out' => $row->money_out,
            'closing' => bcsub(bcadd($row->opening, $row->money_in, 4), $row->money_out, 4),
        ];
    }

    /**
     * The rows after `$afterPosting`, at most `$limit`, with their sources.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $opening, ?int $afterPosting, int $limit): array
    {
        $rows = DB::select('
            WITH book AS (
              SELECT p.posting_id, p.amount, t.ledger_txn_id, t.event_kind::text AS kind, t.created_at,
                     t.staff_id, t.customer_id, t.reverses_txn_id, t.memo,
                     SUM(p.amount) OVER (ORDER BY p.posting_id) AS run
              FROM ledger_posting p JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
              WHERE p.account_id = ? AND t.created_at >= ? AND t.created_at < ?
            )
            SELECT * FROM book WHERE posting_id > ? ORDER BY posting_id LIMIT ?
        ', [$this->bank, $this->start, $this->end, $afterPosting ?? 0, $limit]);

        $txns = array_map(fn ($r) => $r->ledger_txn_id, $rows);
        $topups = TopUp::query()->whereIn('ledger_txn_id', $txns)->get()->keyBy('ledger_txn_id');
        $withdrawals = Withdrawal::query()->whereIn('release_txn_id', $txns)->with('customer')->get()->keyBy('release_txn_id');
        $movements = BankMovement::query()->whereIn('ledger_txn_id', $txns)->get()->keyBy('ledger_txn_id');
        $accounts = ReceivingAccount::query()->whereIn('receiving_account_id', $topups->pluck('receiving_account_id')->filter()->unique())->pluck('label', 'receiving_account_id');
        $customers = DB::table('customer')->whereIn('customer_id', $topups->pluck('customer_id')->unique())->pluck('display_ref', 'customer_id');
        $staff = Staff::query()->whereIn('staff_id', array_filter(array_map(fn ($r) => $r->staff_id, $rows)))->pluck('full_name', 'staff_id');

        return array_map(function ($r) use ($opening, $topups, $withdrawals, $movements, $accounts, $customers, $staff) {
            $amount = (string) $r->amount;

            return [
                'posting_id' => (int) $r->posting_id,
                'ledger_txn_id' => $r->ledger_txn_id,
                'at' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
                'kind' => $r->kind,
                'direction' => bccomp($amount, '0', 4) < 0 ? 'in' : 'out',
                'amount' => ltrim(bcadd($amount, '0', 4), '-'),
                'cash_after' => bcsub($opening, bcadd((string) $r->run, '0', 4), 4),
                'actor' => $r->staff_id !== null
                    ? ['type' => isset($staff[$r->staff_id]) ? 'staff' : 'system', 'name' => $staff[$r->staff_id] ?? null]
                    : ['type' => 'customer', 'name' => null],
                'source' => $this->source($r, $topups->get($r->ledger_txn_id), $withdrawals->get($r->ledger_txn_id),
                    $movements->get($r->ledger_txn_id), $accounts, $customers),
            ];
        }, $rows);
    }

    /**
     * @param  Collection<int|string, mixed>  $accounts
     * @param  Collection<int|string, mixed>  $customers
     * @return array<string, mixed>
     */
    private function source(object $r, ?TopUp $topup, ?Withdrawal $withdrawal, ?BankMovement $movement, $accounts, $customers): array
    {
        return match (true) {
            $r->kind === 'reversal' => ['type' => 'reversal', 'reverses_txn_id' => $r->reverses_txn_id, 'memo' => $r->memo],
            $movement !== null => ['type' => 'bank_movement', 'id' => $movement->movement_id, 'number' => $movement->number(),
                'kind' => $movement->kind->value, 'kind_label' => $movement->kind->label(), 'reason' => $movement->reason,
                'occurred_on' => $movement->occurred_on->toDateString(), 'has_proof' => $movement->proof_ref !== null],
            $withdrawal !== null => ['type' => 'withdrawal', 'id' => $withdrawal->withdrawal_id, 'number' => $withdrawal->number(),
                'customer_ref' => $withdrawal->customer?->display_ref, 'bank_txn_number' => $withdrawal->bank_txn_number],
            $r->kind === 'topup' => ['type' => 'topup', 'id' => $topup?->topup_id, 'number' => $topup?->number(), 'reference' => $topup?->reference,
                'customer_ref' => $topup === null ? null : ($customers[$topup->customer_id] ?? null),
                'how' => $topup === null ? null : ($topup->origin->value === 'by_hand' ? 'credited_by_hand' : 'matched_notice'),
                'receiving_account' => $topup?->receiving_account_id === null ? null : ($accounts[$topup->receiving_account_id] ?? null)],
            default => ['type' => $r->kind, 'memo' => $r->memo],
        };
    }

    /** The posting id a cursor points after. */
    public static function decodeCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $id = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($id === false || preg_match('/^\d{1,18}$/', $id) !== 1) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return (int) $id;
    }

    public static function encodeCursor(int $postingId): string
    {
        return rtrim(strtr(base64_encode((string) $postingId), '+/', '-_'), '=');
    }
}
