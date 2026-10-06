<?php

namespace App\Enums;

/** Security events on a customer's own account (spec 017): told by SMS, email and the inbox. */
enum AccountEvent: string
{
    case PHONE_CHANGED = 'phone_changed';
    case EMAIL_CHANGED = 'email_changed';
    case PASSWORD_CHANGED = 'password_changed';
    case NEW_DEVICE = 'new_device';
    case ACCOUNT_CLOSED = 'account_closed';
}
