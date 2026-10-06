<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Tell one customer something, by id (spec 011 research R13). A buyer's
 * action tells the seller and a seller's action tells other buyers; the actor
 * cannot read the other customer's row (row-level security), so the message
 * is dispatched after commit as a job, which runs as the system actor
 * (DatabaseActorEvents) and loads the recipient itself.
 */
class NotifyCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $customerId,
        public readonly Notification $notification,
    ) {
        // Fixed before the job is queued, so a retry writes the same inbox item (spec 017 R5).
        $this->notification->id ??= (string) Str::uuid();
    }

    public function handle(): void
    {
        Customer::query()->find($this->customerId)?->notifyNow($this->notification);
    }
}
