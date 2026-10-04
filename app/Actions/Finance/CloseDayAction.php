<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\DailyClose;
use App\Models\Staff;
use App\Support\Finance\CloseFigures;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Close a Cairo day (spec 015 FR-014, research R8–R9; Part 2 §9): only once
 * it has ended; the books are the ledger at its midnight cut-off, read under a
 * SHARE lock on ledger_transaction, so a transaction stamped before midnight
 * that is still committing is either in the snapshot or waited for. The
 * difference is the typed statement balance minus the ledger's bank cash: 0
 * locks the day, a non-zero difference locks it only with an explanation,
 * otherwise the day is saved unlocked and can be closed again. A locked day
 * never changes (daily_close_no_reopen) — closing it again is refused.
 */
final class CloseDayAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $staff, string $date, string $bankBalance, ?string $explanation, ?RequestContext $ctx = null): DailyClose
    {
        if (! CloseFigures::ended($date)) {
            throw DomainApiException::dayNotEnded();
        }
        $bankBalance = Money::fixed4($bankBalance);

        return DB::transaction(function () use ($staff, $date, $bankBalance, $explanation, $ctx) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['close:'.$date]);

            $existing = DailyClose::query()->whereKey($date)->first();
            if ($existing?->is_locked) {
                throw DomainApiException::dayAlreadyClosed();
            }

            DB::statement('LOCK TABLE ledger_transaction IN SHARE MODE');
            $books = CloseFigures::at($date);
            $difference = bcsub($bankBalance, $books['bank'], 4);
            $lock = bccomp($difference, '0', 4) === 0 || $explanation !== null;
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
                'is_locked' => $lock,
                'saved_by' => $staff->staff_id,
                'saved_at' => $now,
                'closed_by' => $lock ? $staff->staff_id : null,
                'closed_at' => $lock ? $now : null,
            ];

            $close = $existing === null
                ? DailyClose::query()->create(['close_date' => $date] + $row)
                : tap($existing)->update($row);

            $this->audit->execute($lock ? AuditEvent::DAY_CLOSED : AuditEvent::DAY_SAVED, 'success',
                ['date' => $date, 'bank_balance' => $bankBalance, 'books_bank' => $books['bank'], 'difference' => $difference, 'locked' => $lock],
                'daily_close', null, $ctx, actorStaffId: $staff->staff_id, reason: $explanation);

            return $close->refresh();
        });
    }
}
