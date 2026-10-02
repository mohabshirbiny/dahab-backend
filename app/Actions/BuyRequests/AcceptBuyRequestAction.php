<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Enums\CustomerStatus;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Enums\PieceCategory;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Order;
use App\Support\BuyRequests\OrderReference;
use App\Support\Listings\ListingPricer;
use App\Support\Pricing\Settings;
use App\Support\WorkingHours\WorkingHoursResolver;
use App\Support\WorkingHours\WorkingHoursUnavailable;
use Carbon\CarbonImmutable;

/**
 * The seller accepts the first in line (spec 011 US3, FR-014; Part 2 §5,
 * Part 3 §4.4). One transaction under the `queue` scope, listing locked
 * first, then the line in order: the named request must be the head and its
 * buyer not suspended; the branch one of the listing's options and enabled;
 * the reach-branch deadline from the working-hours resolver. The listing
 * moves `reserved → accepted`, the order is created, the head is accepted
 * (its deposit stays held) and everyone else is released and refunded — all
 * or nothing. Messages go out after commit.
 */
final class AcceptBuyRequestAction
{
    use MovesOrder, ReleasesRequests, RunsInQueue;

    public function __construct(
        private readonly WorkingHoursResolver $hours,
        private readonly Settings $settings,
    ) {}

    /** @return array{order: Order, listing: Listing, released_count: int} */
    public function handle(Customer $seller, string $listingId, string $requestId, int $branchId): array
    {
        // The seller's own listing, under their own row isolation (404 otherwise).
        Listing::query()->where('seller_id', $seller->customer_id)->findOrFail($listingId);

        return $this->inQueue(function () use ($seller, $listingId, $requestId, $branchId) {
            $listing = $this->lockListing($listingId);
            if ($listing->state !== ListingState::RESERVED) {
                throw $listing->state === ListingState::LIVE ? DomainApiException::queueEmpty() : DomainApiException::illegalListingTransition();
            }

            $queue = $this->lockQueue($listing);
            $head = $queue->first();
            if ($head === null) {
                throw DomainApiException::queueEmpty();
            }
            if ($head->buy_request_id !== $requestId) {
                throw DomainApiException::notQueueHead();
            }

            $buyer = Customer::query()->whereKey($head->buyer_id)->first(['customer_id', 'status']);
            if ($buyer === null || $buyer->status === CustomerStatus::SUSPENDED) {
                throw DomainApiException::buyerSuspended();
            }

            $branch = $listing->branches()->where('branch.branch_id', $branchId)->where('branch.is_enabled', true)->first();
            if (! $branch instanceof Branch) {
                throw DomainApiException::branchNotInOptions();
            }

            $now = CarbonImmutable::now();
            try {
                $deadline = $this->hours->addWorkingMinutes(
                    $now, $this->settings->integer(SettingKey::DEADLINE_REACH_BRANCH_WORKING_HOURS) * 60, $branch->branch_id,
                );
            } catch (WorkingHoursUnavailable) {
                throw DomainApiException::branchHoursUnavailable();
            }

            // Spec 012 research R6: the seller's side is fixed now, at what they were shown.
            $sellerRate = $this->sellerRate($listing);

            $listing = $this->moveListing($listing, ListingState::ACCEPTED, $seller, null, ListingStateChange::NOTE_BUY_REQUEST_ACCEPTED);

            $order = Order::query()->create([
                'order_ref' => OrderReference::next($now),
                'listing_id' => $listing->listing_id,
                'buy_request_id' => $head->buy_request_id,
                'seller_id' => $seller->customer_id,
                'buyer_id' => $head->buyer_id,
                'state' => OrderState::AWAITING_DELIVERY,
                'branch_id' => $branch->branch_id,
                'accepted_by' => $seller->customer_id,
                'accepted_at' => $now,
                'reach_branch_deadline' => $deadline,
                'locked_total_price' => $head->locked_total_price,
                'locked_seller_unit_rate' => $sellerRate,
            ]);
            $this->recordOrderCreated($order, $seller);

            $head->state = BuyRequestState::ACCEPTED;
            $head->save();

            $released = 0;
            foreach ($queue->slice(1) as $other) {
                $this->releaseRequest($other, BuyRequestState::RELEASED_NOT_CHOSEN, $listing, $seller, null, BuyRequestEvent::NOT_CHOSEN);
                $released++;
            }

            $this->tell(
                $head->buyer_id, BuyRequestEvent::ACCEPTED, $listing,
                deadline: $deadline->toIso8601String(), orderRef: $order->order_ref,
                branch: $branch->name_en, branchAr: $branch->name_ar,
            );
            $this->flushAfterCommit();

            return [
                'order' => $order->refresh()->load('branch'),
                'listing' => $listing->refresh(),
                'released_count' => $released,
            ];
        });
    }

    /**
     * The seller's per-gram rate locked with the order (spec 012 research R6):
     * `sellers_get` for gold, the unadjusted mid for gold with diamond (the gold
     * value protected from commission), none for a pure diamond. A piece that
     * cannot be priced now cannot be accepted (`price_unavailable`).
     */
    private function sellerRate(Listing $listing): ?string
    {
        if ($listing->category === PieceCategory::DIAMOND) {
            return null;
        }

        $prices = app(ListingPricer::class)->karatPrices((int) $listing->karat_code);
        if ($prices === null || $prices->inverted) {
            throw DomainApiException::priceUnavailable();
        }

        return $listing->category === PieceCategory::GOLD ? $prices->sellersGet : $prices->mid;
    }
}
