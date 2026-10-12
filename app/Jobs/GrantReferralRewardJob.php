<?php

namespace App\Jobs;

use App\Actions\Referral\GrantReferralRewardAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Grant the inviter's reward after the friend's sale settled (spec 023 R23). Dispatched after
 * commit by the payment; idempotent. A lost job is healed by `referrals:reward-pending`.
 */
class GrantReferralRewardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $orderId) {}

    public function handle(GrantReferralRewardAction $grant): void
    {
        $grant->forOrder($this->orderId);
    }
}
