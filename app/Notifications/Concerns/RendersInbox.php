<?php

namespace App\Notifications\Concerns;

use App\Enums\InboxLinkKind;
use App\Models\Customer;
use App\Notifications\Messages\InboxMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Builds an inbox item from a notification's own words (spec 017 R5): the
 * mail subject is the title and the SMS text the body, each rendered once in
 * English and once in Arabic, so the inbox never says something the SMS or the
 * email did not. A caller may point the item at a row with `linkTo()`.
 */
trait RendersInbox
{
    public ?string $inboxLinkKind = null;

    public ?string $inboxLinkId = null;

    /** Point the inbox item at a row (an order, a listing…), chained where the notification is made. */
    public function linkTo(InboxLinkKind $kind, ?string $id = null): static
    {
        $this->inboxLinkKind = $kind->value;
        $this->inboxLinkId = $kind->needsId() ? $id : null;

        return $this;
    }

    /** `inbox` first, for a customer; the other channels unchanged. */
    protected function withInbox(mixed $notifiable, array $channels): array
    {
        return $notifiable instanceof Customer ? ['inbox', ...$channels] : $channels;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function inboxMessage(
        Customer $customer,
        string $type,
        InboxLinkKind $kind = InboxLinkKind::NONE,
        ?string $id = null,
        array $params = [],
    ): InboxMessage {
        if ($this->inboxLinkKind !== null) {
            $kind = InboxLinkKind::from($this->inboxLinkKind);
            $id = $this->inboxLinkId;
        }
        if ($kind->needsId() && $id === null) {
            $kind = InboxLinkKind::NONE;
        }

        [$titleEn, $bodyEn] = $this->inboxTexts($customer, 'en');
        [$titleAr, $bodyAr] = $this->inboxTexts($customer, 'ar');

        return new InboxMessage($type, $titleEn, $titleAr, $bodyEn, $bodyAr, $kind, $kind->needsId() ? $id : null, $params);
    }

    /** @return array{0: string, 1: string} the title and body in one language */
    protected function inboxTexts(Customer $customer, string $lang): array
    {
        $as = clone $customer;
        $as->preferred_lang = $lang;

        $title = '';
        if (method_exists($this, 'toMail')) {
            $mail = $this->toMail($as);
            $title = $mail instanceof MailMessage ? (string) $mail->subject : '';
        }

        $sms = $this->toSms($as);
        $body = is_string($sms) ? $sms : $sms->content;
        $prefix = config('app.name').': ';
        if (str_starts_with($body, $prefix)) {
            $body = substr($body, strlen($prefix));
        }

        return [$title !== '' ? $title : $body, $body];
    }
}
