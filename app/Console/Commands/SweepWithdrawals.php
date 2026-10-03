<?php

namespace App\Console\Commands;

use App\Actions\Withdrawals\AnnounceEndedPausesAction;
use App\Support\DatabaseActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The withdrawal-pause expiry (spec 013 FR-015; Part 2 §11, Part 3 §12).
 * Scheduled every minute in routes/console.php; runs as the system actor, one
 * transaction per pause, so one failure never blocks the rest.
 */
class SweepWithdrawals extends Command
{
    protected $signature = 'withdrawals:sweep';

    protected $description = 'Tell customers when their withdrawal pause has ended';

    public function handle(AnnounceEndedPausesAction $announce): int
    {
        return DatabaseActor::elevate('system', function () use ($announce) {
            $told = 0;
            $failed = 0;

            foreach ($announce->due() as $pauseId) {
                try {
                    $told += $announce->handle($pauseId) ? 1 : 0;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('withdrawals.sweep.failed', ['pause_id' => $pauseId, 'error' => $e->getMessage()]);
                }
            }

            $this->components->info("Withdrawals: {$told} customers told their pause ended.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
