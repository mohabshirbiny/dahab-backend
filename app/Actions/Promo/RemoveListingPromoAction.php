<?php

namespace App\Actions\Promo;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPromo;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Take the code off a listing (spec 023 research R6): its credit is voided (the use row stays,
 * append-only, and is ignored by every count because its credit is voided) and the listing's
 * code row is deleted. Refused once a buyer is in line (`listing_promo_locked`).
 */
final class RemoveListingPromoAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly VoidListingCreditsAction $void,
    ) {}

    public function handle(Customer $seller, string $listingId, ?RequestContext $ctx = null): Listing
    {
        Listing::query()->whereKey($listingId)->where('seller_id', $seller->customer_id)->firstOrFail();

        return DatabaseActor::promo(fn () => DB::transaction(function () use ($seller, $listingId, $ctx) {
            $listing = Listing::query()->whereKey($listingId)->lockForUpdate()->firstOrFail();

            if (! ListingPromo::query()->where('listing_id', $listingId)->exists()) {
                throw DomainApiException::promoNotApplied();
            }

            return $this->within($seller, $listing, 'code_removed', $ctx);
        }));
    }

    /** Inside the caller's transaction and `promo` scope, listing locked. */
    public function within(Customer $seller, Listing $listing, string $reason, ?RequestContext $ctx): Listing
    {
        $open = in_array($listing->state, [ListingState::DRAFT, ListingState::CHANGES_REQUESTED, ListingState::IN_REVIEW, ListingState::LIVE], true);
        $inLine = DB::table('buy_request')->where('listing_id', $listing->listing_id)->whereIn('state', ['queued', 'accepted'])->exists();
        if (! $open || $inLine) {
            throw DomainApiException::listingPromoLocked();
        }

        $row = ListingPromo::query()->where('listing_id', $listing->listing_id)->first();
        $this->void->forListing($listing, $reason, $seller, null, $ctx);
        $row?->delete();

        $this->audit->execute(
            AuditEvent::PROMO_REMOVED,
            'success',
            ['listing_id' => $listing->listing_id, 'code' => $row?->promo_code ?? 'referral', 'reason' => $reason],
            'listing',
            $listing->listing_id,
            $ctx,
            actorCustomerId: $seller->customer_id,
        );

        return $listing->refresh();
    }
}
