<?php

namespace App\Actions\Orders\Concerns;

use App\Enums\InboxLinkKind;
use App\Enums\OrderEvent;
use App\Jobs\NotifyCustomerJob;
use App\Jobs\NotifyWhenFreeJob;
use App\Models\Listing;
use App\Models\Order;
use App\Notifications\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Order messages (spec 012 FR-025): collected while the transaction runs and
 * sent only once it commits, each as a job running as the system actor — the
 * actor of the change cannot read the other party's row. A rollback sends
 * nothing.
 */
trait TellsOrderParties
{
    /** @var list<array{0: string, 1: OrderNotification}> */
    private array $orderOutbox = [];

    /** @var list<string> listings back on the market with an empty line */
    private array $orderFreeAgain = [];

    private function tellOrder(
        string $customerId,
        OrderEvent $event,
        Order $order,
        Listing $listing,
        ?string $amount = null,
        ?CarbonImmutable $deadline = null,
        ?string $code = null,
        ?string $message = null,
        ?InboxLinkKind $linkKind = null,
        ?string $linkId = null,
    ): void {
        $listing->loadMissing('pieceType');
        $order->loadMissing('branch');

        $this->orderOutbox[] = [$customerId, (new OrderNotification(
            $event, $order->order_ref, $listing->title(), $listing->title(arabic: true),
            $amount, $deadline?->toIso8601String(), $order->branch?->name_en, $order->branch?->name_ar, $code, $message,
        ))->linkTo($linkKind ?? InboxLinkKind::ORDER, $linkId ?? $order->order_id)];
    }

    /** Send what was collected once the transaction commits (call inside it). */
    private function flushOrderOutbox(): void
    {
        $outbox = $this->orderOutbox;
        $free = array_values(array_unique($this->orderFreeAgain));
        $this->orderOutbox = [];
        $this->orderFreeAgain = [];

        DB::afterCommit(function () use ($outbox, $free) {
            foreach ($outbox as [$customerId, $notification]) {
                NotifyCustomerJob::dispatch($customerId, $notification);
            }
            foreach ($free as $listingId) {
                NotifyWhenFreeJob::dispatch($listingId);
            }
        });
    }
}
