<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Models\Customer;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Templates\Templates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "We looked at your report" (spec 017 Q27): inbox only, generic — never what
 * was decided, never anything about the seller.
 */
class ListingReportNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $reference) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof Customer ? ['inbox'] : [];
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        $p = ['reference' => $this->reference];

        return new InboxMessage(
            'listing_report.reviewed',
            Templates::text('listing_report.reviewed.inbox_title', false),
            Templates::text('listing_report.reviewed.inbox_title', true),
            Templates::text('listing_report.reviewed.inbox_body', false, $p),
            Templates::text('listing_report.reviewed.inbox_body', true, $p),
            InboxLinkKind::NONE,
            null,
            ['reference' => $this->reference],
        );
    }
}
