<?php

namespace App\Actions\MarketMakers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\Listing;
use App\Models\MarketMakerApproval;
use App\Models\Staff;
use App\Support\Pricing\Settings;
use App\Support\RequestContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Approve one piece for market-maker purchase (spec 019 FR-062, Part 3 §4): it must be live and
 * have been listed for `marketmaker.min_list_age_days`. The approval keeps the numbers the
 * approver saw — the asking price, the gold value and the reference guide when there was one —
 * and is unique per piece. Approving changes nothing about the piece for anyone else.
 */
final class ApproveForMarketMakersAction
{
    public function __construct(private readonly RecordAuditLogAction $audit, private readonly Settings $settings) {}

    public function handle(Staff $actor, string $listingId, string $askingPriceSeen, ?string $goldValueSeen, ?string $rapaportGuideSeen, ?RequestContext $ctx = null): MarketMakerApproval
    {
        return DB::transaction(function () use ($actor, $listingId, $askingPriceSeen, $goldValueSeen, $rapaportGuideSeen, $ctx) {
            $listing = Listing::query()->whereKey($listingId)->lockForUpdate()->firstOrFail();

            if ($listing->state !== ListingState::LIVE) {
                throw DomainApiException::listingNotLive();
            }

            $days = $this->settings->integer(SettingKey::MARKETMAKER_MIN_LIST_AGE_DAYS);
            if ($listing->listed_at === null || $listing->listed_at->gt(now()->subDays($days))) {
                throw DomainApiException::listingTooNew($days);
            }

            if (MarketMakerApproval::query()->where('listing_id', $listing->listing_id)->exists()) {
                throw DomainApiException::alreadyApproved();
            }

            try {
                $approval = MarketMakerApproval::query()->create([
                    'listing_id' => $listing->listing_id,
                    'approved_by' => $actor->staff_id,
                    'asking_price' => $askingPriceSeen,
                    'gold_value' => $goldValueSeen,
                    'rapaport_guide' => $rapaportGuideSeen,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    throw DomainApiException::alreadyApproved();
                }
                throw $e;
            }

            $this->audit->execute(
                AuditEvent::MARKET_MAKER_APPROVED,
                'success',
                [
                    'listing_id' => $listing->listing_id,
                    'asking_price' => $askingPriceSeen,
                    'gold_value' => $goldValueSeen,
                    'rapaport_guide' => $rapaportGuideSeen,
                ],
                entityType: 'listing',
                entityId: $listing->listing_id,
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
            );

            return $approval->refresh();
        });
    }
}
