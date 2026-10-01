<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\ReleasesRequests;
use App\Enums\BuyRequestEvent;
use App\Enums\BuyRequestState;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use LogicException;

/**
 * Release the whole line of a listing that can no longer be sold (spec 011
 * FR-019, FR-020, research R14): a take-down or the seller's withdrawal from
 * `reserved`, or the seller's suspension. Every queued request becomes
 * `released_declined` with its own refund and its buyer is told why. Runs
 * inside the caller's transaction, after the caller moved the listing
 * (`reserved → withdrawn | suspended_hold`) with the listing locked. Staff
 * paths run elevated; the seller's withdrawal runs in the `queue` scope.
 */
final class ReleaseQueueAction
{
    use ReleasesRequests;

    /** @return int how many requests were released */
    public function releaseAll(Listing $locked, ?Customer $bySeller, ?Staff $byStaff, BuyRequestEvent $why): int
    {
        if (($bySeller === null) === ($byStaff === null)) {
            throw new LogicException('Exactly one actor releases a queue.');
        }

        $released = 0;
        foreach ($this->lockQueue($locked) as $request) {
            $this->releaseRequest($request, BuyRequestState::RELEASED_DECLINED, $locked, $bySeller, $byStaff, $why);
            $released++;
        }

        $this->flushAfterCommit();

        return $released;
    }
}
