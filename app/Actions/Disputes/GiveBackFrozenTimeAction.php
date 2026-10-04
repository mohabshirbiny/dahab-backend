<?php

namespace App\Actions\Disputes;

use App\Enums\DeadlineKind;
use App\Enums\OrderState;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderDeadlineExtension;
use App\Models\Staff;
use Carbon\CarbonImmutable;

/**
 * Resume gives every running deadline back the time the order spent frozen
 * (spec 014 FR-011, research R4, Clarification). The deadline that runs in the
 * state the order returns to is pushed by exactly (now − frozen_at), to the
 * microsecond, on the application clock every deadline uses; each push is an `order_deadline_extension`
 * row naming the resolver and the dispute, and the deadline's reminder re-arms.
 * `at_inspection` has no deadline. Inside the resolution's transaction, before
 * the order moves: returns the columns the move writes.
 *
 * @phpstan-type Push array{kind: DeadlineKind, column: string, old: CarbonImmutable, new: CarbonImmutable}
 */
final class GiveBackFrozenTimeAction
{
    /** @return array{0: array<string, mixed>, 1: Push|null} the order columns to write, and the push */
    public function compute(Order $order, Dispute $dispute): array
    {
        [$kind, $column, $reminder] = match ($dispute->frozen_from) {
            OrderState::WEIGHT_ADJUST_PENDING => [DeadlineKind::DECISION, 'decision_due_deadline', null],
            OrderState::AWAITING_BALANCE => [DeadlineKind::BALANCE, 'balance_due_deadline', 'balance_reminder_sent_at'],
            OrderState::READY_TO_COLLECT => [DeadlineKind::COLLECT, 'collect_deadline', null],
            default => [null, null, null],
        };

        if ($kind === null) {
            return [[], null];
        }

        /** @var CarbonImmutable|null $old */
        $old = $order->{$column};
        if ($old === null) {
            return [[], null];
        }

        $frozenFor = max(1, (int) round($dispute->frozen_at->diffInMicroseconds(CarbonImmutable::now(), true)));
        $new = $old->addMicroseconds($frozenFor);

        return [
            [$column => $new] + ($reminder !== null ? [$reminder => null] : []),
            ['kind' => $kind, 'column' => $column, 'old' => $old, 'new' => $new],
        ];
    }

    /** @param  Push  $push */
    public function record(Order $order, Dispute $dispute, Staff $resolver, array $push): OrderDeadlineExtension
    {
        return OrderDeadlineExtension::query()->create([
            'order_id' => $order->order_id,
            'which' => $push['kind'],
            'old_deadline' => $push['old'],
            'new_deadline' => $push['new'],
            'granted_by' => $resolver->staff_id,
            'reason' => "Dispute {$dispute->dispute_ref}: the time the order was frozen given back.",
            'dispute_id' => $dispute->dispute_id,
        ]);
    }
}
