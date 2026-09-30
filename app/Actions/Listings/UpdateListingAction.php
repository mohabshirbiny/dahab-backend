<?php

namespace App\Actions\Listings;

use App\Actions\Listings\Concerns\AttachesListingMedia;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingMediaKind;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A seller edits a draft or a listing sent back for changes (spec 010 US4,
 * FR-006; Part 2 §3 PATCH). Anything else is `listing_not_editable`. The
 * category never changes; the fields that belong to it are validated by
 * UpdateListingRequest against the listing's category.
 */
final class UpdateListingAction
{
    use AttachesListingMedia, MovesListing;

    private const FIELDS = ['piece_type_id', 'karat_code', 'stated_weight_g', 'making_charge_per_g', 'asking_price', 'description'];

    private const SINGLES = [
        'video_token' => ListingMediaKind::VIDEO,
        'invoice_token' => ListingMediaKind::INVOICE,
        'stone_certificate_token' => ListingMediaKind::STONE_CERTIFICATE,
    ];

    /**
     * @param  array<string, mixed>  $data  validated; only the keys that were sent
     */
    public function handle(Customer $seller, string $listingId, array $data): Listing
    {
        return DB::transaction(function () use ($seller, $listingId, $data) {
            $listing = $this->lockListing($listingId);

            if (! $listing->state->isEditable()) {
                throw DomainApiException::listingNotEditable();
            }

            $listing->fill(array_intersect_key($data, array_flip(self::FIELDS)))->save();

            if (array_key_exists('branch_option_ids', $data)) {
                $branchIds = CreateListingAction::enabledBranchIds((array) $data['branch_option_ids']);
                DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->delete();
                DB::table('listing_branch_option')->insert(array_map(
                    fn (int $id) => ['listing_id' => $listing->listing_id, 'branch_id' => $id],
                    $branchIds,
                ));
            }

            $this->removeMedia($listing, array_values($data['remove_media_ids'] ?? []));
            $this->attachMedia($listing, $seller, ListingMediaKind::PHOTO, array_values($data['add_photo_tokens'] ?? []));

            foreach (self::SINGLES as $field => $kind) {
                // Present with a token replaces, present with null removes, absent leaves.
                if (array_key_exists($field, $data)) {
                    $this->replaceSingle($listing, $seller, $kind, $data[$field]);
                }
            }

            $this->assertMediaLimits($listing);

            if (array_key_exists('photo_order', $data)) {
                $this->reorderPhotos($listing, array_values((array) $data['photo_order']));
            }

            return $listing->refresh();
        });
    }

    /**
     * Put the listing's photos in the given order; the list must name each of them once.
     *
     * @param  list<string>  $order
     */
    private function reorderPhotos(Listing $listing, array $order): void
    {
        $ids = ListingMedia::query()->where('listing_id', $listing->listing_id)
            ->where('kind', ListingMediaKind::PHOTO->value)->pluck('media_id')->all();

        if (count($order) !== count($ids) || array_diff($ids, $order) !== [] || array_diff($order, $ids) !== []) {
            throw ValidationException::withMessages(['photo_order' => ['List every photo of this listing exactly once.']]);
        }

        foreach ($order as $position => $id) {
            ListingMedia::query()->whereKey($id)->update(['position' => $position]);
        }
    }
}
