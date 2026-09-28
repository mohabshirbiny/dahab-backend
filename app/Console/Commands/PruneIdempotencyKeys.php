<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Support\DatabaseActor;
use Illuminate\Console\Command;

/**
 * Deletes Idempotency-Key rows past their expiry (spec 007 research R2).
 * The rows are operational, not audit, so deleting them is the design.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete expired Idempotency-Key records';

    public function handle(): int
    {
        $deleted = DatabaseActor::elevate('maintenance', fn () => IdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->delete());

        $this->info("Deleted {$deleted} expired idempotency keys.");

        return self::SUCCESS;
    }
}
