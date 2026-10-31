<?php

namespace App\Actions\Listings;

use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * The lab of a live piece stopped taking pieces, so it left the market: the seller chooses a branch
 * of another lab, sees its cost on the listing, and the piece is back. Refused while the lab is open.
 */
final class ChooseListingLabAction
{
    use MovesListing;

    public function handle(Customer $seller, string $listingId, int $branchId): Listing
    {
        Listing::query()->where('seller_id', $seller->customer_id)->findOrFail($listingId);

        return DB::transaction(function () use ($listingId, $branchId) {
            $listing = $this->lockListing($listingId);

            if ($listing->state !== ListingState::LIVE) {
                throw DomainApiException::illegalListingTransition();
            }
            if (! $listing->needsLabChoice()) {
                throw DomainApiException::branchOptionsRequired();
            }

            $branch = Branch::query()->whereKey($branchId)->where('is_enabled', true)->first();
            if ($branch === null || ! $branch->lab->is_enabled) {
                throw DomainApiException::labNotAvailable();
            }

            DB::table('listing_branch_option')->where('listing_id', $listingId)->delete();
            DB::table('listing_branch_option')->insert(['listing_id' => $listingId, 'branch_id' => $branch->branch_id]);
            $listing->forceFill(['lab_id' => $branch->lab_id])->save();

            return $listing->refresh();
        });
    }
}
