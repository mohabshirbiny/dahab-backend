<?php

namespace App\Actions\BuyRequests\Concerns;

use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\InboxLinkKind;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Jobs\NotifyCustomerJob;
use App\Jobs\NotifyWhenFreeJob;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Staff;
use App\Notifications\BuyRequestNotification;
use App\Support\BuyRequests\BuyRequestTransitions;
use App\Support\BuyRequests\DepositLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ending a queued request and keeping its listing in step (spec 011 FR-010,
 * FR-015, FR-018–FR-020, research R14). Inside the caller's transaction, with
 * the listing already locked: move the request along
 * `buy_request_transition`, give the deposit back through the money service,
 * and — when the line is now empty — move the listing `reserved → live` with
 * a history row. Messages are collected and sent only after commit.
 */
trait ReleasesRequests
{
    use MovesListing;

    /** @var list<array{0: string, 1: BuyRequestNotification}> */
    private array $outbox = [];

    /** @var list<string> listings that went back to live with an empty line */
    private array $freeAgain = [];

    /** Lock the queued requests of a locked listing, in line order. */
    private function lockQueue(Listing $listing): Collection
    {
        return BuyRequest::query()->where('listing_id', $listing->listing_id)
            ->where('state', BuyRequestState::QUEUED->value)
            ->orderBy('queue_position')->lockForUpdate()->get();
    }

    private function releaseRequest(
        BuyRequest $locked,
        BuyRequestState $to,
        Listing $listing,
        ?Customer $byCustomer,
        ?Staff $byStaff,
        ?BuyRequestEvent $tell,
        bool $notifyWhenFree = false,
    ): BuyRequest {
        if (! app(BuyRequestTransitions::class)->allows($locked->state, $to)) {
            throw DomainApiException::illegalBuyRequestTransition();
        }

        $locked->state = $to;
        if ($to === BuyRequestState::WITHDRAWN_BY_BUYER) {
            $locked->notify_when_free = $notifyWhenFree;
        }
        $locked->save();

        app(DepositLedger::class)->release($locked, $byCustomer?->customer_id, $byStaff?->staff_id);

        if ($tell !== null) {
            $this->tell($locked->buyer_id, $tell, $listing, amount: (string) $locked->deposit_amount);
        }

        return $locked->refresh();
    }

    /**
     * After requests ended: an empty line on a reserved listing puts it back on
     * the market, recorded against the actor. Other states are left as they are.
     */
    private function syncListingAfterRelease(Listing $listing, ?Customer $byCustomer, ?Staff $byStaff): Listing
    {
        $listing->refresh();

        if ($listing->state === ListingState::RESERVED && $listing->active_queue_count === 0) {
            $listing = $this->moveListing($listing, ListingState::LIVE, $byCustomer, $byStaff, ListingStateChange::NOTE_QUEUE_EMPTIED);
            $this->freeAgain[] = $listing->listing_id;
        }

        return $listing;
    }

    private function tell(
        string $customerId,
        BuyRequestEvent $event,
        Listing $listing,
        ?string $amount = null,
        ?string $deadline = null,
        ?string $orderRef = null,
        ?string $branch = null,
        ?string $branchAr = null,
        ?string $reason = null,
    ): void {
        $listing->loadMissing('pieceType');

        $notification = new BuyRequestNotification(
            $event, $listing->title(), $listing->title(arabic: true), $amount, $deadline, $orderRef, $branch, $branchAr, $reason,
        );
        // Spec 017: the seller's inbox item opens the piece.
        if ($event === BuyRequestEvent::NEW_REQUEST) {
            $notification->linkTo(InboxLinkKind::LISTING, $listing->listing_id);
        }
        $this->outbox[] = [$customerId, $notification];
    }

    /** Send what was collected, once the transaction commits (call inside it). */
    private function flushAfterCommit(): void
    {
        $outbox = $this->outbox;
        $free = array_values(array_unique($this->freeAgain));
        $this->outbox = [];
        $this->freeAgain = [];

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
