<?php

namespace App\Actions\MarketMakers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Listing;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * "Leave" on the approvals page (spec 023 R20, D23-7): the approver looked and decided to do nothing.
 * Changes nothing about the piece, which stays in the queue; the look is logged with the figures
 * seen. No table.
 */
final class LeaveMarketMakerQueueAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{listing_id: string, left_at: string} */
    public function handle(Staff $actor, string $listingId, ?string $note, ?RequestContext $ctx = null): array
    {
        $listing = Listing::query()->whereKey($listingId)->firstOrFail();
        if ($listing->state !== ListingState::LIVE) {
            throw DomainApiException::listingNotLive();
        }
        if (DB::table('market_maker_approval')->where('listing_id', $listingId)->exists()) {
            throw (new ModelNotFoundException)->setModel(Listing::class, [$listingId]);
        }

        $this->audit->execute(
            AuditEvent::MARKET_MAKER_LEFT,
            'success',
            [
                'listing_id' => $listing->listing_id,
                'asking_price' => $listing->asking_price === null ? null : bcadd((string) $listing->asking_price, '0', 4),
                'making_charge_per_g' => $listing->making_charge_per_g === null ? null : bcadd((string) $listing->making_charge_per_g, '0', 4),
                'views' => (int) DB::table('listing_view')->where('listing_id', $listingId)->count(),
                'note' => $note,
            ],
            'listing',
            $listing->listing_id,
            $ctx,
            actorStaffId: $actor->staff_id,
        );

        return ['listing_id' => $listing->listing_id, 'left_at' => now()->toIso8601String()];
    }
}
