<?php

namespace App\Http\Resources;

use App\Enums\ListingMediaKind;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

/**
 * One media item of a listing, as each surface returns it (spec 010
 * contract "Media"). `url` is the media endpoint of the surface that
 * returned it; the storage key never leaves the Backend.
 */
#[OA\Schema(
    schema: 'ListingMedia',
    description: 'A photo, the video, the original invoice or the stone certificate of a listing (spec 010). Only the invoice is private; the market never returns a private item. `url` is a path on this API that streams the file (send the same credentials as the request that returned it).',
    required: ['id', 'kind', 'is_private', 'mime', 'position', 'url'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'kind', type: 'string', enum: ['photo', 'video', 'invoice', 'stone_certificate']),
        new OA\Property(property: 'is_private', type: 'boolean'),
        new OA\Property(property: 'mime', type: 'string', example: 'image/jpeg'),
        new OA\Property(property: 'position', type: 'integer', description: 'Order of the photos; the first is the card image'),
        new OA\Property(property: 'url', type: 'string', example: '/api/v1/market/listings/{listing}/media/{media}'),
    ],
)]
final class ListingMediaResource
{
    public const MARKET = 'api.v1.market.listings.media';

    public const CUSTOMER = 'api.v1.customer.me.listings.media';

    public const DASHBOARD = 'api.v1.dashboard.listings.media';

    /** @return array<string, mixed> */
    public static function one(ListingMedia $media, string $route): array
    {
        return [
            'id' => $media->media_id,
            'kind' => $media->kind->value,
            'is_private' => $media->is_private,
            'mime' => $media->mime,
            'position' => $media->position,
            'url' => route($route, ['listing' => $media->listing_id, 'media' => $media->media_id], false),
        ];
    }

    /**
     * @param  Collection<int, ListingMedia>  $media
     * @return list<array<string, mixed>>
     */
    public static function many(Collection $media, string $route): array
    {
        return $media->map(fn (ListingMedia $m) => self::one($m, $route))->values()->all();
    }

    /**
     * What anyone may see of a listing: the photos in order, then the video
     * and the stone certificate. Never a private item, whatever was loaded.
     *
     * @return Collection<int, ListingMedia>
     */
    public static function publicOf(Listing $listing): Collection
    {
        return $listing->media->where('is_private', false)->values();
    }

    /** @param  Collection<int, ListingMedia>  $media */
    public static function first(Collection $media, ListingMediaKind $kind, string $route): ?array
    {
        $item = $media->firstWhere('kind', $kind);

        return $item === null ? null : self::one($item, $route);
    }
}
