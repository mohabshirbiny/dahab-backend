<?php

namespace App\Notifications\Contracts;

use App\Models\Customer;
use App\Notifications\Messages\InboxMessage;

/**
 * A customer notification that also lands in the in-app inbox (spec 017
 * FR-030): it lists `inbox` in `via()` for a Customer and builds the item
 * here. Codes and confirmation links never implement it.
 */
interface InboxNotification
{
    public function toInbox(Customer $customer): ?InboxMessage;
}
