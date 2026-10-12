<?php

namespace App\Enums;

/** Where a commission credit came from. */
enum CreditSource: string
{
    case PROMO_CODE = 'promo_code';
    case REFERRAL_FRIEND = 'referral_friend';
    case REFERRAL_INVITER = 'referral_inviter';
}
