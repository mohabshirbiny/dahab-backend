<?php

namespace App\Console\Commands;

use App\Actions\Orders\Customer\CancelOrderBySellerAction;
use App\Actions\Orders\Customer\DecideAdjustmentAction;
use App\Actions\Orders\ForfeitDepositAction;
use App\Actions\Orders\OrderSweepAction;
use App\Enums\OrderState;
use App\Models\Order;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\Orders\OrderSettlement;
use App\Support\SystemActor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The order deadlines (spec 012 research R14; Part 2 §11, Part 3 §12).
 * Scheduled every minute in routes/console.php; runs as the system actor, one
 * transaction per item, so one failure never blocks the rest. Passes, in
 * order: suspension, reach-branch, suspension again (a sweep cancellation
 * can reach the threshold), the reach reminder, the balance reminder, the
 * unanswered adjustment (a decline), the unpaid balance (forfeiture), the
 * seller-return window, the collection window.
 */
class SweepOrders extends Command
{
    protected $signature = 'orders:sweep';

    protected $description = 'Act on order deadlines: missed delivery, unpaid balance, return windows, reminders and the cancellation threshold';

    private int $failed = 0;

    public function handle(
        OrderSweepAction $sweep,
        CancelOrderBySellerAction $cancel,
        ForfeitDepositAction $forfeit,
        OrderSettlement $settlement,
        DecideAdjustmentAction $decide,
    ): int {
        return DatabaseActor::elevate('system', function () use ($sweep, $cancel, $forfeit, $settlement, $decide) {
            $system = Staff::query()->findOrFail(SystemActor::id());
            $now = CarbonImmutable::now();

            $suspended = $this->each('suspend', $sweep->sellersToSuspend(), fn ($id) => $sweep->suspendSeller($system, $id));

            $missed = $this->each('reach_branch', Order::query()->where('state', OrderState::AWAITING_DELIVERY->value)
                ->where('reach_branch_deadline', '<=', $now)->orderBy('reach_branch_deadline')->pluck('order_id')->all(),
                fn ($id) => $cancel->byDeadline($system, $id) !== null);

            $suspended += $this->each('suspend', $sweep->sellersToSuspend(), fn ($id) => $sweep->suspendSeller($system, $id));

            $this->each('reach_reminder', $sweep->reachReminderCandidates(), fn ($id) => $sweep->remindReach($id));

            $this->each('balance_reminder', $sweep->balanceReminderDue(), function ($id) use ($sweep, $settlement) {
                $order = Order::query()->with(['listing', 'buyRequest', 'inspections'])->findOrFail($id);
                $due = $settlement->compute($order, $order->listing, $order->buyRequest, $order->latestInspection())->balance;

                return $sweep->remindBalance($id, $due);
            });

            $declined = $this->each('no_answer', Order::query()->where('state', OrderState::WEIGHT_ADJUST_PENDING->value)
                ->whereNotNull('decision_due_deadline')->where('decision_due_deadline', '<=', $now)
                ->orderBy('decision_due_deadline')->pluck('order_id')->all(),
                fn ($id) => $decide->byDeadline($system, $id) !== null);

            $forfeited = $this->each('no_pay', Order::query()->where('state', OrderState::AWAITING_BALANCE->value)
                ->where('balance_due_deadline', '<=', $now)->orderBy('balance_due_deadline')->pluck('order_id')->all(),
                fn ($id) => $forfeit->handle($system, $id) !== null);

            $unclaimed = $this->each('return_window', $sweep->returnsPastWindow(), fn ($id) => $sweep->closeReturnWindow($system, $id));

            $uncollected = $this->each('collect_window', $sweep->collectionsPastWindow(), fn ($id) => $sweep->closeCollectionWindow($system, $id));

            $this->components->info("Orders: {$missed} missed delivery, {$declined} unanswered adjustments, {$forfeited} forfeited, "
                ."{$unclaimed} returns unclaimed, {$uncollected} uncollected, {$suspended} sellers suspended.");

            return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
        }, SystemActor::id());
    }

    /** @param  list<string>  $ids */
    private function each(string $pass, array $ids, Closure $work): int
    {
        $done = 0;
        foreach ($ids as $id) {
            try {
                $done += $work($id) ? 1 : 0;
            } catch (Throwable $e) {
                $this->failed++;
                Log::error('orders.sweep.failed', ['pass' => $pass, 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }
}
