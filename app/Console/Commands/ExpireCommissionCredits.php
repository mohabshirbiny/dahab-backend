<?php

namespace App\Console\Commands;

use App\Actions\Promo\ExpireCommissionCreditsAction;
use App\Support\DatabaseActor;
use Illuminate\Console\Command;

/**
 * Expire commission credits past their date and lapse stale referrals (spec 023 T038).
 * Scheduled hourly in routes/console.php; runs as the system actor.
 */
class ExpireCommissionCredits extends Command
{
    protected $signature = 'commission-credits:expire';

    protected $description = 'Expire commission credits past their expiry and void referrals whose friend never sold in time';

    public function handle(ExpireCommissionCreditsAction $expire): int
    {
        $done = DatabaseActor::elevate('system', fn () => $expire->handle());

        $this->info("Expired {$done['credits']} credit(s), voided {$done['referrals']} referral(s).");

        return self::SUCCESS;
    }
}
