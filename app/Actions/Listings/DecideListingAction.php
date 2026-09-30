<?php

namespace App\Actions\Listings;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\ListingDecision;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use App\Notifications\ListingDecisionNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * What the four staff decisions share (spec 010 US2/US5, FR-028–FR-032):
 * one transaction locks the listing, checks where it stands, moves it with a
 * history row naming the staff member, and writes the audit row; the seller
 * is told by SMS / email only after the transaction commits.
 *
 * Each decision starts from exactly one state (approve, ask for changes and
 * reject from `in_review`; take down from `live`), even where
 * `listing_transition` holds other rows into the same target for later
 * modules.
 */
final class DecideListingAction
{
    use MovesListing;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $listingId, ListingDecision $decision, ?string $note = null, ?RequestContext $ctx = null): Listing
    {
        $note = $note === null ? null : trim($note);

        [$from, $to, $event] = match ($decision) {
            ListingDecision::APPROVED => [ListingState::IN_REVIEW, ListingState::LIVE, AuditEvent::LISTING_APPROVED],
            ListingDecision::CHANGES_REQUESTED => [ListingState::IN_REVIEW, ListingState::CHANGES_REQUESTED, AuditEvent::LISTING_CHANGES_REQUESTED],
            ListingDecision::REJECTED => [ListingState::IN_REVIEW, ListingState::REJECTED, AuditEvent::LISTING_REJECTED],
            ListingDecision::TAKEN_DOWN => [ListingState::LIVE, ListingState::WITHDRAWN, AuditEvent::LISTING_TAKEN_DOWN],
        };

        return DB::transaction(function () use ($actor, $listingId, $decision, $note, $ctx, $from, $to, $event) {
            $listing = $this->lockListing($listingId);

            if ($listing->state !== $from) {
                throw DomainApiException::illegalListingTransition();
            }

            if ($decision === ListingDecision::APPROVED) {
                $this->assertCanGoLive($listing);
            }

            $listing = $this->moveListing($listing, $to, null, $actor, $note);

            $this->audit->execute(
                $event,
                'success',
                ['state' => $to->value, 'seller_id' => $listing->seller_id],
                'listing',
                $listing->listing_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['state' => $from->value],
                reason: $note,
            );

            $listing->loadMissing('pieceType');
            $notification = new ListingDecisionNotification($decision, $listing->title(), $listing->title(arabic: true), $note);
            DB::afterCommit(fn () => Customer::query()->find($listing->seller_id)?->notify($notification));

            return $listing;
        });
    }

    /**
     * A suspended seller's piece never reaches the market (FR-030b), and
     * neither does a piece in a karat Dahab has turned off since it was
     * submitted (FR-030c). The listing stays in review either way.
     */
    private function assertCanGoLive(Listing $listing): void
    {
        $status = Customer::query()->whereKey($listing->seller_id)->sharedLock()->firstOrFail()->status;
        $status = $status instanceof CustomerStatus ? $status : CustomerStatus::from((string) $status);

        if ($status === CustomerStatus::SUSPENDED) {
            throw DomainApiException::sellerSuspended();
        }

        if ($listing->karat_code !== null && ! $listing->karat()->where('is_enabled', true)->exists()) {
            throw DomainApiException::karatDisabled();
        }
    }
}
