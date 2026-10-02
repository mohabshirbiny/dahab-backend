<?php

namespace App\Actions\Orders;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\ListingState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\SettingKey;
use App\Enums\SuspendedReason;
use App\Models\Customer;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Models\SellerReturn;
use App\Models\Staff;
use App\Support\Orders\DeadlinePolicy;
use App\Support\Pricing\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The small passes of `orders:sweep` (spec 012 research R14), each one item
 * per transaction, re-checked under its lock, as the system actor:
 *  - suspension: sellers whose cancellations since their last reinstatement
 *    reach `suspension.cancellations_threshold` (analysis C2 — never part of
 *    the seller's own request);
 *  - reminders: the reach-branch deadline (working hours) and the balance
 *    deadline, once each;
 *  - the seller-return window: the piece waits past its window
 *    (`awaiting_seller_return → seller_unclaimed`).
 * The cancelling passes are CancelOrderBySellerAction::byDeadline and
 * ForfeitDepositAction.
 */
final class OrderSweepAction
{
    use MovesListing, TellsOrderParties;

    public function __construct(
        private readonly Settings $settings,
        private readonly DeadlinePolicy $deadlines,
        private readonly SuspendSellerAction $suspend,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return list<string> sellers who are at or over the threshold and not suspended */
    public function sellersToSuspend(): array
    {
        $threshold = $this->settings->integer(SettingKey::SUSPENSION_CANCELLATIONS_THRESHOLD);

        return DB::table('seller_cancellation as s')
            ->join('customer as c', 'c.customer_id', '=', 's.seller_id')
            ->where('c.status', '<>', CustomerStatus::SUSPENDED->value)
            ->whereRaw("s.cancelled_at > COALESCE(c.cancellations_reset_at, '-infinity'::timestamptz)")
            ->groupBy('s.seller_id')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->pluck('s.seller_id')->all();
    }

    public function suspendSeller(Staff $system, string $sellerId): bool
    {
        return DB::transaction(function () use ($system, $sellerId) {
            $customer = Customer::query()->whereKey($sellerId)->lockForUpdate()->first();
            if ($customer === null || $customer->status === CustomerStatus::SUSPENDED) {
                return false;
            }

            // Compared in SQL, to the microsecond (a PHP round trip would truncate the reset).
            $count = DB::table('seller_cancellation as s')->where('s.seller_id', $sellerId)
                ->whereRaw("s.cancelled_at > COALESCE((SELECT c.cancellations_reset_at FROM customer c WHERE c.customer_id = s.seller_id), '-infinity'::timestamptz)")
                ->count();
            $threshold = $this->settings->integer(SettingKey::SUSPENSION_CANCELLATIONS_THRESHOLD);
            if ($count < $threshold) {
                return false;
            }

            return $this->suspend->handle($sellerId, SuspendedReason::REPEATED_CANCELLATIONS,
                "{$count} accepted sales cancelled (threshold {$threshold}).", $system);
        });
    }

    /** @return list<string> */
    public function reachReminderCandidates(): array
    {
        return Order::query()->where('state', OrderState::AWAITING_DELIVERY->value)
            ->whereNull('reach_reminder_sent_at')->where('reach_branch_deadline', '>', CarbonImmutable::now())
            ->orderBy('reach_branch_deadline')->pluck('order_id')->all();
    }

    public function remindReach(string $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null || $order->state !== OrderState::AWAITING_DELIVERY || $order->reach_reminder_sent_at !== null
                || ! $this->deadlines->reachDueWithin($order, (int) config('dahab-orders.reach_reminder_working_hours', 3))) {
                return false;
            }

            $order->forceFill(['reach_reminder_sent_at' => CarbonImmutable::now()])->save();
            $this->tellOrder($order->seller_id, OrderEvent::REACH_REMINDER, $order, $order->listing, deadline: $order->reach_branch_deadline);
            $this->flushOrderOutbox();

            return true;
        });
    }

    /** @return list<string> */
    public function balanceReminderDue(): array
    {
        $now = CarbonImmutable::now();

        return Order::query()->where('state', OrderState::AWAITING_BALANCE->value)
            ->whereNull('balance_reminder_sent_at')
            ->where('balance_due_deadline', '>', $now)
            ->where('balance_due_deadline', '<=', $now->addHours((int) config('dahab-orders.balance_reminder_hours', 24)))
            ->pluck('order_id')->all();
    }

    public function remindBalance(string $orderId, ?string $amountDue): bool
    {
        return DB::transaction(function () use ($orderId, $amountDue) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null || $order->state !== OrderState::AWAITING_BALANCE || $order->balance_reminder_sent_at !== null) {
                return false;
            }

            $order->forceFill(['balance_reminder_sent_at' => CarbonImmutable::now()])->save();
            $this->tellOrder($order->buyer_id, OrderEvent::BALANCE_REMINDER, $order, $order->listing,
                amount: $amountDue, deadline: $order->balance_due_deadline);
            $this->flushOrderOutbox();

            return true;
        });
    }

    /** @return list<string> paid orders whose piece waited at the branch past the collection window */
    public function collectionsPastWindow(): array
    {
        return Order::query()->where('state', OrderState::READY_TO_COLLECT->value)
            ->where('collect_deadline', '<=', CarbonImmutable::now())
            ->whereHas('listing', fn ($q) => $q->where('state', ListingState::SOLD->value))
            ->pluck('order_id')->all();
    }

    /**
     * Paid but never collected (spec 012 FR-021, Part 3 §10.2): the listing
     * `sold → uncollected_expired`, the buyer told. The order stays ready to
     * collect — the money already moved; a later handover still completes it.
     */
    public function closeCollectionWindow(Staff $system, string $orderId): bool
    {
        return DB::transaction(function () use ($system, $orderId) {
            $order = Order::query()->whereKey($orderId)->firstOrFail();
            $listing = $this->lockListing($order->listing_id);
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->state !== OrderState::READY_TO_COLLECT || $order->collect_deadline === null
                || $order->collect_deadline->isFuture() || $listing->state !== ListingState::SOLD) {
                return false;
            }

            $listing = $this->moveListing($listing, ListingState::UNCOLLECTED_EXPIRED, null, $system, ListingStateChange::NOTE_COLLECTION_WINDOW_PASSED);

            $this->audit->execute(
                AuditEvent::ORDER_WINDOW_PASSED,
                'success',
                ['order_ref' => $order->order_ref, 'window' => 'collection', 'listing_state' => ListingState::UNCOLLECTED_EXPIRED->value],
                'order',
                $order->order_id,
                null,
                actorStaffId: $system->staff_id,
            );

            $this->tellOrder($order->buyer_id, OrderEvent::COLLECTION_WINDOW_PASSED, $order, $listing);
            $this->flushOrderOutbox();

            return true;
        });
    }

    /** @return list<string> orders whose returned piece waited past its window */
    public function returnsPastWindow(): array
    {
        return SellerReturn::query()->whereNull('collected_at')->whereNull('relisted_at')
            ->where('return_deadline', '<=', CarbonImmutable::now())
            ->pluck('order_id')->all();
    }

    public function closeReturnWindow(Staff $system, string $orderId): bool
    {
        return DB::transaction(function () use ($system, $orderId) {
            $order = Order::query()->whereKey($orderId)->firstOrFail();
            $listing = $this->lockListing($order->listing_id);
            $return = SellerReturn::query()->where('order_id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $return->isOpen() || $return->return_deadline->isFuture() || $listing->state !== ListingState::AWAITING_SELLER_RETURN) {
                return false;
            }

            $listing = $this->moveListing($listing, ListingState::SELLER_UNCLAIMED, null, $system, ListingStateChange::NOTE_RETURN_WINDOW_PASSED);

            $this->audit->execute(
                AuditEvent::ORDER_WINDOW_PASSED,
                'success',
                ['order_ref' => $order->order_ref, 'window' => 'seller_return', 'listing_state' => ListingState::SELLER_UNCLAIMED->value],
                'order',
                $order->order_id,
                null,
                actorStaffId: $system->staff_id,
            );

            $this->tellOrder($order->seller_id, OrderEvent::RETURN_WINDOW_PASSED, $order, $listing);
            $this->flushOrderOutbox();

            return true;
        });
    }
}
