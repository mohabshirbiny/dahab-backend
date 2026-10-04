<?php

namespace App\Enums;

/** Why a seller asks for more time to reach the branch — the prototype's choices (spec 014 FR-022). */
enum ExtensionRequestReason: string
{
    case TRAVELLING = 'travelling';
    case EMERGENCY = 'emergency';
    case BRANCH_CLOSED = 'branch_closed';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TRAVELLING => 'Travelling or out of Cairo',
            self::EMERGENCY => 'Health or family emergency',
            self::BRANCH_CLOSED => 'The branch was closed when they went',
            self::OTHER => 'Another reason',
        };
    }
}
