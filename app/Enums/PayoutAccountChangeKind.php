<?php

namespace App\Enums;

/** One line of a customer's "Recent changes" (`payout_account_change.kind`, spec 013). */
enum PayoutAccountChangeKind: string
{
    case ADDED = 'added';
    case VERIFIED = 'verified';
    case REFUSED = 'refused';
    case IN_USE = 'in_use';
    case REMOVAL_SCHEDULED = 'removal_scheduled';
    case KEPT = 'kept';
    case REMOVED = 'removed';
    case REQUEST_CANCELLED = 'request_cancelled';
}
