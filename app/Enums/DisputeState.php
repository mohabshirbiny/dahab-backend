<?php

namespace App\Enums;

/** `dispute.state` (05 §16; moves along `dispute_transition`, guard DH009). `resolved` is final. */
enum DisputeState: string
{
    case OPEN = 'open';
    case PASSED_ON = 'passed_on';
    case RESOLVED = 'resolved';

    /** What the raiser sees (spec 014 FR-007): a pass-on reads "being looked at". */
    public function customerState(): string
    {
        return match ($this) {
            self::OPEN => 'open',
            self::PASSED_ON => 'being_looked_at',
            self::RESOLVED => 'resolved',
        };
    }
}
