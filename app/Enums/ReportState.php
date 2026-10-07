<?php

namespace App\Enums;

/** A listing report: open, then final once (spec 017 FR-053; guard DH015). */
enum ReportState: string
{
    case OPEN = 'open';
    case DISMISSED = 'dismissed';
    case ACTIONED = 'actioned';
    case LISTING_GONE = 'listing_gone';
}
