<?php

namespace App\Actions\Listings;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPriceChange;
use App\Support\Controls\Controls;
use App\Support\Listings\ListingPriceLocks;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A seller changes the making charge (gold) or the asking price (stones) of
 * their live piece (spec 022). No review, no state move, no notification:
 * `state_changed_at` and `listed_at` stay as they were. Refused under "stop
 * everything", once a buyer is in line, and while the piece is approved for
 * market makers. Everything is re-checked under the listing's row lock, so a
 * buyer who arrives first wins and a seller who arrives first makes the
 * buyer's confirmed price stale (`price_moved`).
 */
final class ChangeListingPriceAction
{
    use MovesListing;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $seller, string $listingId, string $figure, ?RequestContext $ctx = null): Listing
    {
        // Ownership under the seller's own row isolation: 404 for anyone else's listing.
        Listing::query()->where('seller_id', $seller->customer_id)->findOrFail($listingId);

        app(Controls::class)->assertPlatformRunning();

        return DB::transaction(function () use ($seller, $listingId, $figure, $ctx) {
            $listing = $this->lockListing($listingId);

            if ($listing->state->holdsRequests()) {
                throw DomainApiException::listingHasRequests();
            }

            if ($listing->state !== ListingState::LIVE) {
                throw DomainApiException::listingNotLiveForPriceChange();
            }

            if (ListingPriceLocks::lockedIds([$listing->listing_id]) !== []) {
                throw DomainApiException::listingPriceLocked();
            }

            $field = $listing->priceField();
            $old = (string) $listing->currentPriceFigure();

            if (bccomp($figure, $old, 4) === 0) {
                throw ValidationException::withMessages([$field->value => ['This is already the price.']]);
            }

            $listing->{$field->value} = $figure;
            $listing->save();

            ListingPriceChange::query()->create([
                'listing_id' => $listing->listing_id,
                'field' => $field,
                'old_value' => $old,
                'new_value' => $figure,
                'actor_customer_id' => $seller->customer_id,
            ]);

            $this->audit->execute(
                AuditEvent::LISTING_PRICE_CHANGED,
                'success',
                [
                    'listing_id' => $listing->listing_id,
                    'field' => $field->value,
                    'old_value' => bcadd($old, '0', 4),
                    'new_value' => bcadd($figure, '0', 4),
                ],
                entityType: 'listing',
                entityId: $listing->listing_id,
                ctx: $ctx,
                actorCustomerId: $seller->customer_id,
            );

            return $listing->refresh();
        });
    }
}
