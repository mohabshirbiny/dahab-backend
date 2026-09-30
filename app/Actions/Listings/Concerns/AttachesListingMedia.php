<?php

namespace App\Actions\Listings\Concerns;

use App\Enums\ListingMediaKind;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\IdentityDocumentStorage;
use App\Services\UploadTokenStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning upload tokens into a listing's media rows (spec 010 FR-009,
 * FR-012, research R7). Used only inside the caller's transaction, with the
 * listing row locked, so the per-listing limits cannot be raced. A token is
 * single-use: it is forgotten once the transaction commits.
 */
trait AttachesListingMedia
{
    /** @var list<string> tokens already claimed in this request */
    private array $claimedTokens = [];

    /** @param  list<string>  $tokens */
    private function attachMedia(Listing $listing, Customer $seller, ListingMediaKind $kind, array $tokens): void
    {
        $tokenStore = app(UploadTokenStore::class);

        $position = $kind === ListingMediaKind::PHOTO
            ? ((int) ListingMedia::query()->where('listing_id', $listing->listing_id)->where('kind', $kind->value)->max('position')) + 1
            : 0;

        if ($kind === ListingMediaKind::PHOTO && ! ListingMedia::query()->where('listing_id', $listing->listing_id)->where('kind', $kind->value)->exists()) {
            $position = 0;
        }

        foreach ($tokens as $token) {
            $entry = in_array($token, $this->claimedTokens, true)
                ? null
                : $tokenStore->resolveEntry($token, $seller->customer_id, $kind->purpose());

            if ($entry === null) {
                // Unknown, expired, already used, another purpose or another customer's: indistinguishable.
                throw DomainApiException::uploadTokenInvalid();
            }

            $this->claimedTokens[] = $token;

            ListingMedia::query()->create([
                'listing_id' => $listing->listing_id,
                'kind' => $kind,
                'storage_ref' => $entry['storage_ref'],
                'is_private' => $kind->isPrivate(),
                'mime' => $entry['mime'] ?? 'application/octet-stream',
                'position' => $position++,
            ]);

            DB::afterCommit(fn () => $tokenStore->forget($token));
        }
    }

    /**
     * Remove media rows of this listing; their stored objects are deleted
     * once the transaction commits.
     *
     * @param  list<string>  $mediaIds
     */
    private function removeMedia(Listing $listing, array $mediaIds, string $field = 'remove_media_ids'): void
    {
        if ($mediaIds === []) {
            return;
        }

        $rows = ListingMedia::query()->where('listing_id', $listing->listing_id)->whereIn('media_id', $mediaIds)->get();

        if ($rows->count() !== count(array_unique($mediaIds))) {
            throw ValidationException::withMessages([$field => ['Some of these files do not belong to this listing.']]);
        }

        foreach ($rows as $row) {
            $ref = $row->storage_ref;
            $row->delete();
            DB::afterCommit(fn () => app(IdentityDocumentStorage::class)->delete($ref));
        }
    }

    /** Replace (token), remove (null) the single video / invoice / certificate of a listing. */
    private function replaceSingle(Listing $listing, Customer $seller, ListingMediaKind $kind, ?string $token): void
    {
        $existing = ListingMedia::query()->where('listing_id', $listing->listing_id)->where('kind', $kind->value)->pluck('media_id')->all();
        $this->removeMedia($listing, $existing);

        if ($token !== null) {
            $this->attachMedia($listing, $seller, $kind, [$token]);
        }
    }

    /** At most 6 photos and one each of the others (spec 010 FR-012). */
    private function assertMediaLimits(Listing $listing): void
    {
        $counts = ListingMedia::query()->where('listing_id', $listing->listing_id)
            ->selectRaw('kind, count(*) AS n')->groupBy('kind')->pluck('n', 'kind');

        foreach (ListingMediaKind::cases() as $kind) {
            if ((int) ($counts[$kind->value] ?? 0) > $kind->limit()) {
                $field = $kind === ListingMediaKind::PHOTO ? 'photo_tokens' : $kind->value.'_token';

                throw ValidationException::withMessages([$field => [
                    $kind === ListingMediaKind::PHOTO
                        ? 'A listing can have at most '.$kind->limit().' photos.'
                        : 'A listing can have only one of these files.',
                ]]);
            }
        }
    }
}
