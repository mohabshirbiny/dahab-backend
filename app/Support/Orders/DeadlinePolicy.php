<?php

namespace App\Support\Orders;

use App\Enums\DeadlineKind;
use App\Enums\OrderState;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\SellerReturn;
use App\Support\Pricing\Settings;
use App\Support\WorkingHours\WorkingHoursResolver;
use App\Support\WorkingHours\WorkingHoursUnavailable;
use Carbon\CarbonImmutable;

/**
 * The order deadlines (spec 012, research R9, R13, R14; Part 3 §1.3). Only the
 * reach-branch deadline is in working hours (set at acceptance by spec 011);
 * the decision, balance, collection and return windows are CALENDAR time,
 * counted in Cairo, from settings read live.
 */
final class DeadlinePolicy
{
    private const TZ = 'Africa/Cairo';

    public function __construct(
        private readonly Settings $settings,
        private readonly WorkingHoursResolver $hours,
    ) {}

    /** `deadline.buyer_pay_days` calendar days: to pay the balance, or to answer an adjustment. */
    public function payWindow(?CarbonImmutable $from = null): CarbonImmutable
    {
        return $this->cairo($from)->addDays($this->settings->integer(SettingKey::DEADLINE_BUYER_PAY_DAYS));
    }

    public function collectWindow(?CarbonImmutable $from = null): CarbonImmutable
    {
        return $this->cairo($from)->addWeeks($this->settings->integer(SettingKey::DEADLINE_COLLECT_WEEKS));
    }

    public function returnWindow(?CarbonImmutable $from = null): CarbonImmutable
    {
        return $this->cairo($from)->addWeeks($this->settings->integer(SettingKey::DEADLINE_SELLER_RETURN_WEEKS));
    }

    /** The deadline an order is running against now, if any (research R19). */
    public function running(Order $order, ?SellerReturn $return = null): ?array
    {
        [$kind, $at] = match ($order->state) {
            OrderState::AWAITING_DELIVERY => [DeadlineKind::REACH_BRANCH, $order->reach_branch_deadline],
            OrderState::WEIGHT_ADJUST_PENDING => [DeadlineKind::DECISION, $order->decision_due_deadline],
            OrderState::AWAITING_BALANCE => [DeadlineKind::BALANCE, $order->balance_due_deadline],
            OrderState::READY_TO_COLLECT => [DeadlineKind::COLLECT, $order->collect_deadline],
            default => $return !== null && $return->isOpen()
                ? [DeadlineKind::RETURN, $return->return_deadline]
                : [null, null],
        };

        if ($kind === null || $at === null) {
            return null;
        }

        return ['kind' => $kind, 'at' => $at, 'overdue' => $at->isPast()];
    }

    /**
     * Whether no more than `$workingHours` of working time is left before the
     * reach-branch deadline (research R14): the deadline comes no later than
     * that much working time from now. A branch whose hours cannot be worked
     * out counts as due, so the seller is still reminded.
     */
    public function reachDueWithin(Order $order, int $workingHours): bool
    {
        try {
            $limit = $this->hours->addWorkingMinutes(CarbonImmutable::now(), $workingHours * 60, $order->branch_id);
        } catch (WorkingHoursUnavailable) {
            return true;
        }

        return $order->reach_branch_deadline->lessThanOrEqualTo($limit);
    }

    private function cairo(?CarbonImmutable $from): CarbonImmutable
    {
        return ($from ?? CarbonImmutable::now())->setTimezone(self::TZ);
    }
}
