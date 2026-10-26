<?php

namespace App\Actions\Listings;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\InboxLinkKind;
use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingStateChange;
use App\Models\Staff;
use App\Notifications\ListingDecisionNotification;
use App\Support\Controls\Controls;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * A piece taken down off the market goes back live (docs/features/settings-page-and-relist.md):
 * `withdrawn → live`, with no new review — the piece was already approved. Only a withdrawal
 * from `live` or `reserved` qualifies (not a piece that left after a sale or a return). The seller
 * brings back only what they took down themselves; staff (`listing.takedown`) bring back either,
 * with a reason the seller reads. Refused like an approval: the switches (stop new listings, a
 * paused category, stop everything), a suspended seller, a karat turned off. A promo code's
 * credits voided by the take-down stay voided.
 */
final class RelistListingAction
{
    use MovesListing;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function bySeller(Customer $seller, string $listingId, ?RequestContext $ctx = null): Listing
    {
        // Ownership under the seller's own row isolation: 404 for anyone else's listing.
        Listing::query()->where('seller_id', $seller->customer_id)->findOrFail($listingId);

        return DB::transaction(function () use ($seller, $listingId, $ctx) {
            $listing = $this->lockListing($listingId);
            $takenDown = $this->assertRelistable($listing);

            if ($takenDown->actor_customer_id !== $seller->customer_id) {
                throw DomainApiException::listingNotRelistable('taken_down_by_staff');
            }

            $listing = $this->moveListing($listing, ListingState::LIVE, $seller, null);

            $this->audit->execute(
                AuditEvent::LISTING_RELISTED,
                'success',
                ['state' => ListingState::LIVE->value, 'seller_id' => $listing->seller_id, 'taken_down_by' => 'seller'],
                'listing',
                $listing->listing_id,
                $ctx ?? RequestContext::forCustomer(request(), $seller->customer_id),
                before: ['state' => ListingState::WITHDRAWN->value],
            );

            return $listing;
        });
    }

    public function byStaff(Staff $actor, string $listingId, string $note, ?RequestContext $ctx = null): Listing
    {
        $note = trim($note);

        return DB::transaction(function () use ($actor, $listingId, $note, $ctx) {
            $listing = $this->lockListing($listingId);
            $takenDown = $this->assertRelistable($listing);

            $listing = $this->moveListing($listing, ListingState::LIVE, null, $actor, $note);

            $this->audit->execute(
                AuditEvent::LISTING_RELISTED,
                'success',
                [
                    'state' => ListingState::LIVE->value,
                    'seller_id' => $listing->seller_id,
                    'taken_down_by' => $takenDown->actor_staff_id !== null ? 'staff' : 'seller',
                ],
                'listing',
                $listing->listing_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['state' => ListingState::WITHDRAWN->value],
                reason: $note,
            );

            $listing->loadMissing('pieceType');
            $notification = new ListingDecisionNotification(ListingDecision::RELISTED, $listing->title(), $listing->title(arabic: true), $note);
            $notification->linkTo(InboxLinkKind::LISTING, $listing->listing_id);
            DB::afterCommit(fn () => Customer::query()->find($listing->seller_id)?->notify($notification));

            return $listing;
        });
    }

    /** The move that took the listing down. */
    public static function takeDownOf(Listing $listing): ?ListingStateChange
    {
        if ($listing->state !== ListingState::WITHDRAWN) {
            return null;
        }

        $last = $listing->relationLoaded('changes')
            ? $listing->changes->sortByDesc('change_id')->first(fn (ListingStateChange $c) => $c->to_state === ListingState::WITHDRAWN)
            : $listing->changes()->where('to_state', ListingState::WITHDRAWN->value)->orderByDesc('change_id')->first();

        return $last !== null && in_array($last->from_state, [ListingState::LIVE, ListingState::RESERVED], true) ? $last : null;
    }

    private function assertRelistable(Listing $listing): ListingStateChange
    {
        if ($listing->state !== ListingState::WITHDRAWN) {
            throw DomainApiException::illegalListingTransition();
        }

        $takenDown = self::takeDownOf($listing) ?? throw DomainApiException::listingNotRelistable('not_taken_down');

        app(Controls::class)->assertCanList($listing->category);

        $status = Customer::query()->whereKey($listing->seller_id)->sharedLock()->firstOrFail()->status;
        $status = $status instanceof CustomerStatus ? $status : CustomerStatus::from((string) $status);
        if ($status === CustomerStatus::SUSPENDED) {
            throw DomainApiException::sellerSuspended();
        }
        if ($status === CustomerStatus::CLOSED) {
            throw DomainApiException::listingNotRelistable('account_closed');
        }

        if ($listing->karat_code !== null && ! $listing->karat()->where('is_enabled', true)->exists()) {
            throw DomainApiException::karatDisabled();
        }

        return $takenDown;
    }
}
