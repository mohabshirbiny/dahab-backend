<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\DailyClose;
use App\Models\DailyCloseBank;
use App\Models\Staff;
use App\Support\Finance\BankAccounts;
use App\Support\Finance\CloseFigures;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Close a Cairo day (spec 015 FR-014, research R8–R9; Part 2 §9): only once
 * it has ended; the books are the ledger at its midnight cut-off, read under a
 * SHARE lock on ledger_transaction, so a transaction stamped before midnight
 * that is still committing is either in the snapshot or waited for. The
 * difference is the typed statement balance minus the ledger's bank cash: 0
 * locks the day, a non-zero difference locks it only with an explanation,
 * otherwise the day is saved unlocked and can be closed again. A locked day
 * never changes (daily_close_no_reopen) — closing it again is refused.
 *
 * Per account (owner notes of 2026-10-29, docs/features/bank-per-account.md):
 * a statement balance is typed for every account the close asks for (active,
 * or holding money); each line's difference is its balance minus its books,
 * and the books not assigned to any account are a line of their own. The day
 * locks when every line is 0 and nothing is unassigned, or with an
 * explanation. The totals stay: bank_balance is the sum typed, books_bank the
 * whole bank cash, so difference = the lines' differences − the unassigned.
 */
final class CloseDayAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  array<int, string>  $balances  the typed statement balance by receiving account id
     */
    public function handle(Staff $staff, string $date, array $balances, ?string $explanation, ?RequestContext $ctx = null): DailyClose
    {
        if (! CloseFigures::ended($date)) {
            throw DomainApiException::dayNotEnded();
        }
        $balances = array_map(fn ($v) => Money::fixed4((string) $v), $balances);

        return DB::transaction(function () use ($staff, $date, $balances, $explanation, $ctx) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['close:'.$date]);

            $existing = DailyClose::query()->whereKey($date)->first();
            if ($existing?->is_locked) {
                throw DomainApiException::dayAlreadyClosed();
            }

            DB::statement('LOCK TABLE ledger_transaction IN SHARE MODE');
            $books = CloseFigures::at($date);
            $byAccount = BankAccounts::booksAt(CloseFigures::cutOff($date));
            $asked = array_column(BankAccounts::forClose($byAccount), 'receiving_account_id');
            $missing = array_diff($asked, array_keys($balances));
            if ($missing !== []) {
                throw ValidationException::withMessages(['bank_balances' => ['Type the statement balance of every account: '.count($missing).' missing.']]);
            }

            $lines = [];
            $bankBalance = '0.0000';
            $allZero = true;
            foreach ($balances as $accountId => $typed) {
                $own = $byAccount[$accountId] ?? '0.0000';
                $lineDifference = bcsub($typed, $own, 4);
                $allZero = $allZero && bccomp($lineDifference, '0', 4) === 0;
                $bankBalance = bcadd($bankBalance, $typed, 4);
                $lines[] = ['receiving_account_id' => $accountId, 'statement_balance' => $typed, 'books_balance' => Money::fixed4($own), 'difference' => $lineDifference];
            }
            $unassigned = $byAccount[BankAccounts::NOT_ASSIGNED] ?? '0.0000';
            if (bccomp($unassigned, '0', 4) !== 0) {
                $allZero = false;
                $lines[] = ['receiving_account_id' => null, 'statement_balance' => null, 'books_balance' => Money::fixed4($unassigned), 'difference' => Money::fixed4(bcmul($unassigned, '-1', 4))];
            }

            $difference = bcsub($bankBalance, $books['bank'], 4);
            $lock = $allZero || $explanation !== null;
            $now = CarbonImmutable::now();

            $row = [
                'bank_balance' => $bankBalance,
                'books_bank' => $books['bank'],
                'customer_available' => $books['customer_available'],
                'customer_held' => $books['customer_held'],
                'customer_liability' => $books['customer_liability'],
                'dahab_wallet' => $books['dahab_wallet'],
                'escrow' => $books['escrow'],
                'vat_payable' => $books['vat_payable'],
                'movements_in' => $books['movements_in'],
                'movements_out' => $books['movements_out'],
                'difference' => $difference,
                'explanation' => $explanation,
                'is_locked' => false,
                'saved_by' => $staff->staff_id,
                'saved_at' => $now,
                'closed_by' => null,
                'closed_at' => null,
            ];

            // Saved unlocked first: the lines of a locked day can no longer be written.
            $close = $existing === null
                ? DailyClose::query()->create(['close_date' => $date] + $row)
                : tap($existing)->update($row);
            DailyCloseBank::query()->where('close_date', $date)->delete();
            DailyCloseBank::query()->insert(array_map(fn (array $l) => ['close_date' => $date] + $l, $lines));
            if ($lock) {
                $close->update(['is_locked' => true, 'closed_by' => $staff->staff_id, 'closed_at' => $now]);
            }

            $this->audit->execute($lock ? AuditEvent::DAY_CLOSED : AuditEvent::DAY_SAVED, 'success',
                ['date' => $date, 'bank_balance' => $bankBalance, 'books_bank' => $books['bank'], 'difference' => $difference, 'locked' => $lock,
                    'banks' => array_map(fn (array $l) => [$l['receiving_account_id'] ?? 'not_assigned', $l['statement_balance'], $l['books_balance'], $l['difference']], $lines)],
                'daily_close', null, $ctx, actorStaffId: $staff->staff_id, reason: $explanation);

            return $close->refresh();
        });
    }
}
