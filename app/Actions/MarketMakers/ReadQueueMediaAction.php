<?php

namespace App\Actions\MarketMakers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\ReadListingMediaAction;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A piece's file for the market-maker approver (spec 023 contract staff-promo-api §7). The
 * approver holds `market_maker.approve`, not a `listing.*` permission, so the general listing
 * media route is closed to them. Only a live piece that waits in the queue (or was approved)
 * can be opened; every open is audited, private invoice included.
 */
final class ReadQueueMediaAction
{
    public function __construct(
        private readonly ReadListingMediaAction $read,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $listingId, string $mediaId, ?RequestContext $ctx = null): StreamedResponse
    {
        $listing = Listing::query()->whereKey($listingId)->where('state', ListingState::LIVE->value)->firstOrFail();
        $media = ListingMedia::query()->where('listing_id', $listing->listing_id)->findOrFail($mediaId);

        $this->audit->execute(
            AuditEvent::MARKET_MAKER_MEDIA_VIEWED,
            'success',
            ['listing_id' => $listing->listing_id, 'media_id' => $media->media_id, 'kind' => $media->kind->value,
                'approved' => DB::table('market_maker_approval')->where('listing_id', $listingId)->exists()],
            'listing',
            $listing->listing_id,
            $ctx,
            actorStaffId: $actor->staff_id,
        );

        return $this->read->handle($listing->listing_id, $media->media_id);
    }
}
