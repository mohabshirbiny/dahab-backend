<?php

namespace App\Enums;

/** A row of `dispute_change`, the dispute's history (spec 014 R9). */
enum DisputeChangeKind: string
{
    case OPENED = 'opened';
    case PASSED_ON = 'passed_on';
    case RESOLVED = 'resolved';
}
