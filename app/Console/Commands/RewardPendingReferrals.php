<?php

namespace App\Console\Commands;

use App\Actions\Referral\GrantReferralRewardAction;
use Illuminate\Console\Command;

/**
 * The healer behind the reward job (spec 023 R23): a referral still `joined` whose friend has a
 * settled sale of enough value is rewarded. Every five minutes; a lost job costs minutes only.
 */
class RewardPendingReferrals extends Command
{
    protected $signature = 'referrals:reward-pending';

    protected $description = 'Reward referrals whose friend completed a sale but whose reward job was lost';

    public function handle(GrantReferralRewardAction $grant): int
    {
        $this->info('Rewarded '.$grant->healPending().' referral(s).');

        return self::SUCCESS;
    }
}
