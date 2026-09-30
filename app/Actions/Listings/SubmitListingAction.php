<?php

namespace App\Actions\Listings;

use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\Listings\ListingTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send a draft, or a listing sent back for changes, to Dahab's review
 * (spec 010 US1/US4, FR-013; Part 2 §3 submit). It must be complete: enough
 * photos, a description, and a karat, piece type and at least one branch
 * that are still enabled — reference data may have changed since the draft.
 */
final class SubmitListingAction
{
    use MovesListing;

    public function __construct(private readonly ListingTransitions $transitions) {}

    public function handle(Customer $seller, string $listingId): Listing
    {
        return DB::transaction(function () use ($seller, $listingId) {
            $listing = $this->lockListing($listingId);

            // The state first: a listing that cannot be submitted is a 409, whatever it lacks.
            if (! $this->transitions->allows($listing->state, ListingState::IN_REVIEW)) {
                throw DomainApiException::illegalListingTransition();
            }

            $minimum = (int) config('dahab-listings.min_photos.'.$listing->category->value);
            if ($listing->media()->where('kind', ListingMediaKind::PHOTO->value)->count() < $minimum) {
                throw DomainApiException::photoRequired($minimum);
            }

            $length = mb_strlen(trim((string) $listing->description));
            $min = (int) config('dahab-listings.description_min');
            if ($length < $min || $length > (int) config('dahab-listings.description_max')) {
                throw ValidationException::withMessages(['description' => ["Describe the piece in at least {$min} characters before sending it for review."]]);
            }

            if (! $listing->pieceType()->where('is_enabled', true)->exists()) {
                throw ValidationException::withMessages(['piece_type_id' => ['This type of piece is no longer accepted. Choose another.']]);
            }

            if ($listing->karat_code !== null && ! $listing->karat()->where('is_enabled', true)->exists()) {
                throw ValidationException::withMessages(['karat_code' => ['This karat is no longer accepted for new listings.']]);
            }

            if (! $listing->branches()->where('branch.is_enabled', true)->exists()) {
                throw DomainApiException::branchOptionsRequired();
            }

            return $this->moveListing($listing, ListingState::IN_REVIEW, $seller, null);
        });
    }
}
