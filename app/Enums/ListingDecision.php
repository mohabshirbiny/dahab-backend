<?php

namespace App\Enums;

/** A staff decision the seller is told about (spec 010 FR-032). */
enum ListingDecision: string
{
    case APPROVED = 'approved';
    case CHANGES_REQUESTED = 'changes_requested';
    case REJECTED = 'rejected';
    case TAKEN_DOWN = 'taken_down';
}
