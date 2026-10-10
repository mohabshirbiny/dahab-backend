<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Order;
use App\Models\Staff;
use App\Support\Finance\Csv;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Wallet\HeldByRequest;

/**
 * The Orders list as CSV (spec 015 FR-018, research R11): the list's own
 * filters (group, past deadline, branch, search) and query, newest first,
 * capped, UTF-8 with a BOM, formulas neutralised, audited with the filters
 * and the row count — like the Wallet statement and Withdrawals exports.
 */
final class ExportOrdersAction
{
    public function __construct(
        private readonly ListOrdersAction $list,
        private readonly DeadlinePolicy $deadlines,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, string $group, bool $pastDeadline, ?int $branchId, ?string $q, ?string $freeRelist = null): array
    {
        $cap = (int) config('dahab-orders.export_cap');
        $csv = new Csv(['Order', 'State', 'Piece', 'Karat', 'Weight (g)', 'Branch', 'Seller ref', 'Buyer ref', 'Locked total',
            'Deposit', 'Held now', 'Accepted at', 'Deadline', 'Deadline at', 'Past deadline'], $cap);

        $orders = $this->list->filtered($group, $pastDeadline, $branchId, $q, $freeRelist)
            ->with(['listing.pieceType', 'branch', 'buyRequest', 'seller:customer_id,display_ref', 'buyer:customer_id,display_ref', 'sellerReturn'])
            ->orderByDesc('accepted_at')->orderByDesc('order_id')->limit($cap + 1)->get();
        $held = HeldByRequest::for($orders->pluck('buy_request_id')->all());

        foreach ($orders as $o) {
            /** @var Order $o */
            $deadline = $this->deadlines->running($o, $o->sellerReturn);
            $more = $csv->row([
                $o->order_ref, $o->state->value, $o->listing?->title(), $o->listing?->karat_code === null ? '' : $o->listing->karat_code.'K',
                $o->final_weight_g ?? $o->listing?->stated_weight_g, $o->branch?->name_en, $o->seller?->display_ref, $o->buyer?->display_ref,
                bcadd((string) $o->locked_total_price, '0', 4), bcadd((string) $o->buyRequest?->deposit_amount, '0', 4),
                $held[$o->buy_request_id] ?? '0.0000', $o->accepted_at->setTimezone('Africa/Cairo')->toIso8601String(),
                $deadline['kind']->value ?? '', isset($deadline['at']) ? $deadline['at']->setTimezone('Africa/Cairo')->toIso8601String() : '',
                ($deadline['overdue'] ?? false) ? 'yes' : '',
            ]);
            if (! $more) {
                break;
            }
        }

        $result = $csv->finish();
        $this->audit->execute(AuditEvent::ORDER_LIST_EXPORTED, 'success',
            ['group' => $group, 'past_deadline' => $pastDeadline, 'branch_id' => $branchId, 'q' => $q, 'free_relist' => $freeRelist,
                'rows' => $result['rows'], 'truncated' => $result['truncated']],
            entityType: 'order', actorStaffId: $viewer->staff_id);

        return $result;
    }
}
