<?php

namespace App\Http\Resources\Staff;

use App\Enums\ExtensionRequestState;
use App\Enums\OrderState;
use App\Enums\StaffPermission;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Order;
use App\Models\Staff;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Orders\OrderTimeline;
use App\Support\Orders\StaffBranchScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * An order on the staff Orders page (spec 012 FR-022, research R18). Buyer
 * and seller by display reference (and id, to open the Customer file for
 * holders of customer.view). `detail()` adds the figures, every inspection
 * result, the history, the ledger entries and what the caller may do now.
 * Collection codes are never returned to staff.
 */
#[OA\Schema(
    schema: 'StaffOrder',
    description: 'An order for staff (spec 012). Money as 4-dp strings. The detail adds settlement, inspections, decision, collection, seller_return, branch_changes, extensions, ledger, timeline and can. Spec 014: frozen and has_waiting_extension on every row; the detail adds disputes, extension_request, collection.proxy and can.handle_dispute | answer_extension | view_proxy_id.',
    required: ['id', 'order_ref', 'state', 'group', 'listing_state', 'piece', 'seller', 'buyer', 'branch', 'value', 'held', 'paid', 'deadline', 'accepted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_ref', type: 'string'),
        new OA\Property(property: 'state', type: 'string'),
        new OA\Property(property: 'group', type: 'string', enum: ['waiting_seller', 'at_igi', 'needs_decision', 'waiting_balance', 'ready_to_collect', 'closed']),
        new OA\Property(property: 'listing_state', type: 'string'),
        new OA\Property(property: 'piece', type: 'object'),
        new OA\Property(property: 'seller', type: 'object', description: '{customer_id, display_ref}'),
        new OA\Property(property: 'buyer', type: 'object', description: '{customer_id, display_ref}'),
        new OA\Property(property: 'branch', type: 'object', description: '{id, name_en, name_ar}'),
        new OA\Property(property: 'value', type: 'string', description: 'The final total once paid, else the locked total'),
        new OA\Property(property: 'held', type: 'string', description: 'The buyer\'s deposit while it is held, else 0'),
        new OA\Property(property: 'paid', type: 'string', nullable: true),
        new OA\Property(property: 'deadline', type: 'object', nullable: true, description: '{kind, at, overdue}'),
        new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'frozen', type: 'boolean', description: 'Spec 014: disputed'),
        new OA\Property(property: 'has_waiting_extension', type: 'boolean', description: 'Spec 014: the seller asked for more time and is waiting'),
    ],
)]
class StaffOrderResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $deadline = app(DeadlinePolicy::class)->running($o, $o->sellerReturn);
        $deposit = bcadd((string) $o->buyRequest->deposit_amount, '0', 4);
        // The deposit is held until payment; a dispute frozen after payment holds nothing (spec 014).
        $held = $o->state->isOpen() && $o->state !== OrderState::READY_TO_COLLECT
            && ! ($o->state === OrderState::DISPUTED && $o->final_buyer_total !== null);
        $requests = $o->relationLoaded('extensionRequests') ? $o->extensionRequests : $o->extensionRequests()->get();

        $data = [
            'id' => $o->order_id,
            'order_ref' => $o->order_ref,
            'state' => $o->state->value,
            'group' => $o->state->group(),
            'listing_state' => $o->listing->state->value,
            'piece' => CustomerOrderResource::piece($o->listing),
            'seller' => ['customer_id' => $o->seller_id, 'display_ref' => $o->seller?->display_ref],
            'buyer' => ['customer_id' => $o->buyer_id, 'display_ref' => $o->buyer?->display_ref],
            'branch' => ['id' => $o->branch_id, 'name_en' => $o->branch?->name_en, 'name_ar' => $o->branch?->name_ar],
            'value' => bcadd((string) ($o->final_buyer_total ?? $o->locked_total_price), '0', 4),
            'held' => $held ? $deposit : '0.0000',
            'paid' => $o->final_buyer_total === null ? null : bcadd((string) $o->final_buyer_total, '0', 4),
            'deadline' => $deadline === null ? null : [
                'kind' => $deadline['kind']->value,
                'at' => $deadline['at']->toIso8601String(),
                'overdue' => $deadline['overdue'],
            ],
            'accepted_at' => $o->accepted_at->toIso8601String(),
            'frozen' => $o->state === OrderState::DISPUTED,
            'has_waiting_extension' => $requests->contains(fn ($r) => $r->state === ExtensionRequestState::WAITING),
        ];

        if (! $this->detail) {
            return $data;
        }

        $superseded = $o->inspections->pluck('supersedes_id')->filter()->all();
        $latest = $o->latestInspection();

        return $data + [
            'locked_total_price' => bcadd((string) $o->locked_total_price, '0', 4),
            'deposit_amount' => $deposit,
            'locked_seller_unit_rate' => $o->locked_seller_unit_rate === null ? null : bcadd((string) $o->locked_seller_unit_rate, '0', 4),
            'locked_buyer_unit_rate' => $o->buyRequest->locked_unit_rate === null ? null : bcadd((string) $o->buyRequest->locked_unit_rate, '0', 4),
            'settlement' => $o->final_buyer_total === null ? null : [
                'final_weight_g' => $o->final_weight_g === null ? null : (string) $o->final_weight_g,
                'final_buyer_total' => (string) $o->final_buyer_total,
                'final_seller_gross' => (string) $o->final_seller_gross,
                'commission_amount' => (string) $o->commission_amount,
                'vat_amount' => (string) $o->vat_amount,
                'spread_amount' => (string) $o->spread_amount,
                'seller_proceeds' => (string) $o->seller_proceeds,
                'balance_amount' => (string) $o->balance_amount,
            ],
            'inspections' => $o->inspections->map(fn ($r) => InspectionResultResource::shape($r, withOrder: false)
                + ['superseded' => in_array($r->inspection_id, $superseded, true)])->values()->all(),
            'latest_inspection_id' => $latest?->inspection_id,
            'proposed_price' => $o->proposed_price === null ? null : bcadd((string) $o->proposed_price, '0', 4),
            'decision_due_deadline' => $o->decision_due_deadline?->toIso8601String(),
            'balance_due_deadline' => $o->balance_due_deadline?->toIso8601String(),
            'reach_branch_deadline' => $o->reach_branch_deadline->toIso8601String(),
            'collect_deadline' => $o->collect_deadline?->toIso8601String(),
            'collection' => $o->collection === null ? null : [
                'collected_at' => $o->collection->collected_at?->toIso8601String(),
                'handover_by' => $o->collection->handover_by,
                'failed_attempts' => $o->collection->failed_attempts,
                'locked_until' => $o->collection->locked_until?->toIso8601String(),
                // Spec 014: the person the buyer named; the ID photo through GET …/proxy-id.
                'proxy' => ! $o->collection->is_proxy && ! $o->collection->collected_by_proxy ? null : [
                    'name' => $o->collection->proxy_name,
                    'phone' => $o->collection->proxy_phone,
                    'named_at' => $o->collection->proxy_named_at?->toIso8601String(),
                    'has_id_photo' => $o->collection->is_proxy,
                    'collected_by_proxy' => $o->collection->collected_by_proxy,
                ],
            ],
            'seller_return' => $o->sellerReturn === null ? null : [
                'return_deadline' => $o->sellerReturn->return_deadline->toIso8601String(),
                'collected_at' => $o->sellerReturn->collected_at?->toIso8601String(),
                'relisted_at' => $o->sellerReturn->relisted_at?->toIso8601String(),
                'compensation_txn_id' => $o->sellerReturn->compensation_txn_id,
                'failed_attempts' => $o->sellerReturn->failed_attempts,
                'locked_until' => $o->sellerReturn->locked_until?->toIso8601String(),
            ],
            'cancel_reason' => $o->cancel_reason,
            'ledger' => $o->getAttribute('ledger_entries') ?? [],
            'timeline' => OrderTimeline::build($o, forStaff: true),
            // Spec 014.
            'disputes' => ($o->relationLoaded('disputes') ? $o->disputes : $o->disputes()->get())->map(fn ($d) => [
                'id' => $d->dispute_id,
                'ref' => $d->dispute_ref,
                'raised_as' => $d->raised_as,
                'reason' => $d->reason->value,
                'state' => $d->state->value,
                'outcome' => $d->outcome?->value,
                'opened_at' => $d->opened_at->toIso8601String(),
            ])->values()->all(),
            'extension_request' => ($last = $requests->last()) === null ? null : ExtensionRequestResource::shape($last, withOrder: false),
            'can' => self::can($o, $request->user('staff')),
        ];
    }

    /** @return array<string, bool> */
    private static function can(Order $o, ?Staff $staff): array
    {
        $has = fn (StaffPermission $p): bool => $staff !== null && $staff->can($p->value);
        $here = $staff !== null && app(StaffBranchScope::class)->canActAt($staff, $o->branch_id);
        $return = $o->sellerReturn;

        return [
            'receive' => $o->state === OrderState::AWAITING_DELIVERY && $here && $has(StaffPermission::ORDER_RECEIVE),
            'inspect' => $o->state === OrderState::AT_INSPECTION && $here && $has(StaffPermission::INSPECTION_ENTER),
            'correct' => in_array($o->state, [OrderState::WEIGHT_ADJUST_PENDING, OrderState::AWAITING_BALANCE], true)
                && $o->decisions->isEmpty() && $here && $has(StaffPermission::INSPECTION_ENTER),
            'propose_price' => $o->state === OrderState::WEIGHT_ADJUST_PENDING && $o->proposed_price === null
                && $o->latestInspection()?->outcome->value === 'stone_regrade' && $has(StaffPermission::ORDER_PRICE_ADJUST),
            'change_branch' => $o->state === OrderState::AWAITING_DELIVERY && $has(StaffPermission::ORDER_CHANGE_BRANCH),
            'extend' => in_array($o->state, [OrderState::AWAITING_DELIVERY, OrderState::AWAITING_BALANCE, OrderState::READY_TO_COLLECT], true)
                && $has(StaffPermission::ORDER_EXTEND_DEADLINE),
            'handover' => $o->state === OrderState::READY_TO_COLLECT && $o->collection?->collected_at === null
                && $here && $has(StaffPermission::ORDER_HANDOVER),
            'return_handover' => $return !== null && $return->collected_at === null && $return->relisted_at === null
                && $here && $has(StaffPermission::ORDER_HANDOVER),
            'cancel' => $o->state === OrderState::AWAITING_DELIVERY && $has(StaffPermission::ORDER_CANCEL),
            // Spec 014.
            'handle_dispute' => $o->state === OrderState::DISPUTED && $has(StaffPermission::DISPUTE_HANDLE),
            'answer_extension' => $o->state === OrderState::AWAITING_DELIVERY && $has(StaffPermission::ORDER_EXTEND_DEADLINE)
                && ($o->relationLoaded('extensionRequests') ? $o->extensionRequests : $o->extensionRequests()->get())
                    ->contains(fn ($r) => $r->state === ExtensionRequestState::WAITING),
            'view_proxy_id' => $o->collection !== null && $o->collection->is_proxy
                && ($has(StaffPermission::ORDER_HANDOVER) || $has(StaffPermission::ORDER_VIEW)),
        ];
    }
}
