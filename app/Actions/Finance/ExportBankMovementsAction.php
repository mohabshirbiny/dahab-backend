<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\BankMovementKind;
use App\Models\BankMovement;
use App\Models\Staff;
use App\Support\Finance\Csv;

/** The recorded movements as CSV, same filters, capped, audited (spec 015 FR-011). */
final class ExportBankMovementsAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, string $from, string $to, ?BankMovementKind $kind): array
    {
        $cap = (int) config('dahab-finance.export_cap');
        $csv = new Csv(['Number', 'Statement date', 'Kind', 'What it was for', 'In', 'Out', 'Proof', 'Recorded by', 'Recorded at', 'In the ledger'], $cap);

        ListBankMovementsAction::query($from, $to, $kind)->orderByDesc('recorded_at')->orderByDesc('movement_id')->limit($cap + 1)->get()
            ->each(fn (BankMovement $m) => $csv->row([
                $m->number(), $m->occurred_on->toDateString(), $m->kind->label(), $m->reason,
                $m->direction() === 'in' ? $m->magnitude() : '', $m->direction() === 'out' ? $m->magnitude() : '',
                $m->proof_ref === null ? '' : 'yes', $m->recorder?->full_name, $m->recorded_at->setTimezone('Africa/Cairo')->toIso8601String(),
                $m->ledger_txn_id === null ? 'no' : 'yes',
            ]));

        $result = $csv->finish();
        $this->audit->execute(AuditEvent::BANK_MOVEMENTS_EXPORTED, 'success',
            ['from' => $from, 'to' => $to, 'kind' => $kind?->value, 'rows' => $result['rows'], 'truncated' => $result['truncated']],
            entityType: 'bank_movement', actorStaffId: $viewer->staff_id);

        return $result;
    }
}
