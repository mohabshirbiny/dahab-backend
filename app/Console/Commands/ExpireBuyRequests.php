<?php

namespace App\Console\Commands;

use App\Actions\BuyRequests\ExpireBuyRequestAction;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The seller-reply sweep (spec 011 US4, FR-018; Part 2 §11, Part 3 §12).
 * Scheduled every minute in routes/console.php; runs as the system actor.
 * One transaction per request, so one failure never blocks the rest.
 */
class ExpireBuyRequests extends Command
{
    protected $signature = 'buy-requests:expire';

    protected $description = 'Release and refund the buy requests whose seller-reply deadline has passed';

    public function handle(ExpireBuyRequestAction $expire): int
    {
        return DatabaseActor::elevate('system', function () use ($expire) {
            $released = 0;
            $failed = 0;

            foreach ($expire->due() as $id) {
                try {
                    $released += $expire->handle($id) ? 1 : 0;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('buy_requests.expire.failed', ['buy_request_id' => $id, 'error' => $e->getMessage()]);
                }
            }

            $this->components->info("Buy requests released as expired: {$released}.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }, SystemActor::id());
    }
}
