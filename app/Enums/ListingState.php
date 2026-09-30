<?php

namespace App\Enums;

/**
 * `listing_state` (schema §1, spec 010). A listing moves only along the rows
 * of `listing_transition`; the database refuses anything else. Spec 010
 * reaches draft → in_review → live / changes_requested / rejected,
 * live → withdrawn and live ↔ suspended_hold; the rest belong to later
 * modules. `withdrawn` and `rejected` are final.
 */
enum ListingState: string
{
    case DRAFT = 'draft';
    case IN_REVIEW = 'in_review';
    case CHANGES_REQUESTED = 'changes_requested';
    case LIVE = 'live';
    case RESERVED = 'reserved';
    case ACCEPTED = 'accepted';
    case AT_INSPECTION = 'at_inspection';
    case SETTLING = 'settling';
    case SOLD = 'sold';
    case WITHDRAWN = 'withdrawn';
    case SUSPENDED_HOLD = 'suspended_hold';
    case UNCOLLECTED_EXPIRED = 'uncollected_expired';
    case AWAITING_SELLER_RETURN = 'awaiting_seller_return';
    case SELLER_UNCLAIMED = 'seller_unclaimed';
    case REJECTED = 'rejected';

    /** The states the public market shows. */
    public const PUBLIC = [self::LIVE, self::RESERVED];

    public function isPublic(): bool
    {
        return in_array($this, self::PUBLIC, true);
    }

    /** The seller may edit only a draft or a listing sent back for changes. */
    public function isEditable(): bool
    {
        return $this === self::DRAFT || $this === self::CHANGES_REQUESTED;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::IN_REVIEW => 'Waiting for review',
            self::CHANGES_REQUESTED => 'Changes asked',
            self::LIVE => 'Live',
            self::RESERVED => 'Reserved',
            self::ACCEPTED => 'Accepted',
            self::AT_INSPECTION => 'At inspection',
            self::SETTLING => 'Settling',
            self::SOLD => 'Sold',
            self::WITHDRAWN => 'Withdrawn',
            self::SUSPENDED_HOLD => 'On hold',
            self::UNCOLLECTED_EXPIRED => 'Not collected',
            self::AWAITING_SELLER_RETURN => 'Returning to the seller',
            self::SELLER_UNCLAIMED => 'Not claimed by the seller',
            self::REJECTED => 'Rejected',
        };
    }
}
