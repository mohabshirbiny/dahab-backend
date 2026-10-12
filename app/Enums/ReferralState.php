<?php

namespace App\Enums;

/** Life of a referral: joined, then rewarded or void. */
enum ReferralState: string
{
    case JOINED = 'joined';
    case REWARDED = 'rewarded';
    case VOID = 'void';
}
