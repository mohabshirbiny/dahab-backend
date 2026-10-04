<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Compensation;
use App\Models\Staff;
use App\Support\Finance\CompensationListQuery;
use App\Support\Finance\Csv;

/** The Compensation list as CSV, same filters, capped, audited (spec 015 FR-003). */
final class ExportCompensationAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, CompensationListQuery $query): array
    {
        $cap = (int) config('dahab-finance.export_cap');
        $csv = new Csv(['Paid at', 'Customer ref', 'Customer name', 'Amount', 'Reason', 'Note', 'Paid by', 'Dispute', 'Order', 'Party'], $cap);

        $query->apply(Compensation::query())->with(ListCompensationAction::RELATIONS)
            ->orderByDesc('paid_at')->orderByDesc('compensation_id')->limit($cap + 1)->get()
            ->each(fn (Compensation $c) => $csv->row([
                $c->paid_at->setTimezone('Africa/Cairo')->toIso8601String(), $c->customer?->display_ref, $c->customer?->full_name,
                bcadd((string) $c->amount, '0', 4), $c->reason->label(), $c->note, $c->payer?->full_name,
                $c->dispute?->dispute_ref ?? '', $c->order?->order_ref ?? '', $c->party ?? '',
            ]));

        $result = $csv->finish();

        $this->audit->execute(AuditEvent::COMPENSATION_LIST_EXPORTED, 'success',
            $query->toArray() + ['rows' => $result['rows'], 'truncated' => $result['truncated']],
            entityType: 'compensation', actorStaffId: $viewer->staff_id);

        return $result;
    }
}
