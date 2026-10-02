<?php

namespace App\Support\Orders;

use App\Enums\OrderState;
use App\Models\InspectionResult;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Support\SystemActor;
use Carbon\CarbonImmutable;

/**
 * An order's story in time order (spec 012 FR-001, FR-022, research R18–R19),
 * merged from its history rows: the moves, the inspection results, the
 * buyer's decisions, branch changes, extensions, and the return of the piece.
 * Customers get events and figures only; staff also get who did it and why.
 * The relations must be loaded by the caller (no query per event).
 */
final class OrderTimeline
{
    /** @return list<array{event: string, at: string, detail: array<string, mixed>}> */
    public static function build(Order $order, bool $forStaff): array
    {
        $events = [];
        $add = function (string $event, ?CarbonImmutable $at, array $detail = []) use (&$events) {
            if ($at !== null) {
                $events[] = ['event' => $event, 'at' => $at, 'detail' => $detail];
            }
        };

        foreach ($order->stateChanges as $change) {
            $event = self::eventFor($change);
            if ($event === null) {
                continue;
            }
            $detail = ['state' => $change->to_state->value];
            if ($forStaff) {
                $detail += self::actor($change->actor_customer_id, $change->actor_staff_id, $order) + ['note' => $change->note];
            } elseif ($event === 'cancelled') {
                $detail['reason_kind'] = self::cancelKind($change);
            }
            $add($event, $change->changed_at, $detail);
        }

        $superseded = $order->inspections->pluck('supersedes_id')->filter()->all();
        foreach ($order->inspections as $result) {
            /** @var InspectionResult $result */
            $detail = [
                'inspection_id' => $result->inspection_id,
                'outcome' => $result->outcome->value,
                'corrects' => $result->supersedes_id,
                'superseded' => in_array($result->inspection_id, $superseded, true),
            ];
            if ($forStaff) {
                $detail['by'] = ['type' => 'staff', 'staff_id' => $result->inspected_by];
            }
            $add('result', $result->created_at, $detail);
        }

        foreach ($order->decisions as $decision) {
            $add('decision', $decision->decided_at, [
                'accepted' => $decision->buyer_accepted,
                'old_price' => bcadd((string) $decision->old_price, '0', 4),
                'new_price' => bcadd((string) $decision->new_price, '0', 4),
            ]);
        }

        foreach ($order->branchChanges as $change) {
            $add('branch_changed', $change->changed_at, array_filter([
                'from_branch' => $change->from_branch,
                'to_branch' => $change->to_branch,
                'extended_to' => $change->extended_to?->toIso8601String(),
                'reason' => $forStaff ? $change->reason : null,
                'by' => $forStaff ? ['type' => 'staff', 'staff_id' => $change->changed_by] : null,
            ], fn ($v) => $v !== null));
        }

        foreach ($order->extensions as $ext) {
            $add('deadline_extended', $ext->granted_at, array_filter([
                'which' => $ext->which->value,
                'old_deadline' => $ext->old_deadline->toIso8601String(),
                'new_deadline' => $ext->new_deadline->toIso8601String(),
                'reason' => $forStaff ? $ext->reason : null,
                'by' => $forStaff ? ['type' => 'staff', 'staff_id' => $ext->granted_by] : null,
            ], fn ($v) => $v !== null));
        }

        if ($order->proposed_at !== null) {
            $add('price_proposed', $order->proposed_at, ['price' => bcadd((string) $order->proposed_price, '0', 4)]
                + ($forStaff ? ['by' => ['type' => 'staff', 'staff_id' => $order->proposed_by]] : []));
        }

        $return = $order->sellerReturn;
        if ($return !== null) {
            $add('returned', $return->created_at, ['return_deadline' => $return->return_deadline->toIso8601String()]);
            $add('relisted', $return->relisted_at);
            $add('return_collected', $return->collected_at);
        }

        usort($events, fn ($a, $b) => [$a['at']->format('Y-m-d H:i:s.u'), $a['event']] <=> [$b['at']->format('Y-m-d H:i:s.u'), $b['event']]);

        return array_map(fn ($e) => ['event' => $e['event'], 'at' => $e['at']->toIso8601String(), 'detail' => $e['detail']], $events);
    }

    private static function eventFor(OrderStateChange $change): ?string
    {
        return match ($change->to_state) {
            OrderState::AWAITING_DELIVERY => $change->from_state === null ? 'accepted' : null,
            OrderState::AT_INSPECTION => 'received',
            OrderState::READY_TO_COLLECT => 'paid',
            OrderState::COMPLETED => 'collected',
            OrderState::CANCELLED_BUYER_NOPAY => 'forfeited',
            OrderState::CANCELLED_SELLER, OrderState::CANCELLED_STAFF, OrderState::CANCELLED_INSPECTION => 'cancelled',
            // Results and decisions carry these moves.
            default => null,
        };
    }

    /** Why the sale ended, in customer terms (contract `cancel.reason_kind`). */
    public static function cancelKind(OrderStateChange $change): string
    {
        return match ($change->to_state) {
            OrderState::CANCELLED_SELLER => $change->note === OrderStateChange::NOTE_DEADLINE_MISSED ? 'deadline_missed' : 'seller',
            OrderState::CANCELLED_STAFF => 'staff',
            OrderState::CANCELLED_BUYER_NOPAY => 'no_pay',
            OrderState::CANCELLED_INSPECTION => match (true) {
                $change->note === OrderStateChange::NOTE_NO_ANSWER => 'no_answer',
                $change->from_state === OrderState::WEIGHT_ADJUST_PENDING && $change->actor_customer_id !== null => 'declined',
                default => 'inspection',
            },
            default => 'staff',
        };
    }

    /** @return array{by: array<string, mixed>} */
    private static function actor(?string $customerId, ?string $staffId, Order $order): array
    {
        if ($staffId !== null) {
            return ['by' => $staffId === SystemActor::id() ? ['type' => 'system'] : ['type' => 'staff', 'staff_id' => $staffId]];
        }

        return ['by' => ['type' => 'customer', 'role' => $customerId === $order->seller_id ? 'seller' : 'buyer']];
    }
}
