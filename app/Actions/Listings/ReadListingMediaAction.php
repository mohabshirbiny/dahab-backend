<?php

namespace App\Actions\Listings;

use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\IdentityDocumentStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One media file of a listing, decrypted and streamed chunk by chunk
 * (spec 010 FR-010, FR-011, research R7). Who may see what is decided by the
 * database scope of the caller: the market scope returns only public media
 * of live listings, a seller only their own, staff everything. The market
 * caller also asks for `$publicOnly`, so the rule does not rest on the scope
 * alone. Never cached: a file is gone the moment its listing leaves the
 * market.
 */
final class ReadListingMediaAction
{
    public function __construct(private readonly IdentityDocumentStorage $storage) {}

    public function handle(string $listingId, string $mediaId, bool $publicOnly = false, ?string $sellerId = null): StreamedResponse
    {
        $listing = Listing::query()
            ->when($publicOnly, fn ($q) => $q->publiclyVisible())
            ->when($sellerId !== null, fn ($q) => $q->where('seller_id', $sellerId))
            ->findOrFail($listingId);

        $media = ListingMedia::query()
            ->where('listing_id', $listing->listing_id)
            ->when($publicOnly, fn ($q) => $q->where('is_private', false))
            ->findOrFail($mediaId);

        $ref = $media->storage_ref;
        $storage = $this->storage;

        // The callback runs after the request's database scope has ended: it reads the disk only.
        return response()->stream(function () use ($storage, $ref) {
            foreach ($storage->readChunked($ref) as $chunk) {
                echo $chunk;
            }
        }, 200, [
            'Content-Type' => $media->mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
