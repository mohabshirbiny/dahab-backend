<?php

namespace App\Notifications\Messages;

use App\Enums\InboxLinkKind;
use InvalidArgumentException;

/**
 * One item for the customer's in-app inbox (spec 017 FR-031): a type code
 * (`<area>.<event>`, open for later specs), its parameters, what it opens and
 * both texts, rendered at send time from the same words as the SMS/email.
 */
final class InboxMessage
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $type,
        public readonly string $titleEn,
        public readonly string $titleAr,
        public readonly string $bodyEn,
        public readonly string $bodyAr,
        public readonly InboxLinkKind $linkKind = InboxLinkKind::NONE,
        public readonly ?string $linkId = null,
        public readonly array $params = [],
    ) {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $type) !== 1) {
            throw new InvalidArgumentException("Inbox type [{$type}] must look like area.event.");
        }
        if ($linkKind->needsId() !== ($linkId !== null)) {
            throw new InvalidArgumentException("Inbox link [{$linkKind->value}] ".($linkKind->needsId() ? 'needs' : 'takes no').' id.');
        }
    }

    /** The same message pointing elsewhere (a link resolved after the fact, e.g. an order reference to its id). */
    public function linkedTo(InboxLinkKind $kind, ?string $id): self
    {
        return new self($this->type, $this->titleEn, $this->titleAr, $this->bodyEn, $this->bodyAr, $kind, $id, $this->params);
    }
}
