<?php

namespace App\Enums;

/** What opened a withdrawal pause (spec 013; contact changes added by spec 017 research R3). */
enum PauseTrigger: string
{
    case PAYOUT_ACCOUNT = 'payout_account';
    case PHONE_CHANGE = 'phone_change';
    case EMAIL_CHANGE = 'email_change';
    /** Spec 020 D20-2: a reset proves only the phone, so it opens the same pause. */
    case PASSWORD_RESET = 'password_reset';
}
