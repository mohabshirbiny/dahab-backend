<?php

namespace App\Actions\BuyRequests\Concerns;

use App\Support\DatabaseActor;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A queue operation in one transaction under the `queue` scope (spec 011
 * research R2, analysis H1). The scope is the OUTER frame: the transaction
 * commits while it is still set, so the deferred checks (history row,
 * deposit entries, listing/queue agreement) fire with it.
 */
trait RunsInQueue
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inQueue(Closure $work): mixed
    {
        return DatabaseActor::queue(fn () => DB::transaction($work));
    }
}
