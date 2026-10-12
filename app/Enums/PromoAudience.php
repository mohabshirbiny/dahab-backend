<?php

namespace App\Enums;

/** Who may use a code: its one account or anyone. */
enum PromoAudience: string
{
    case ACCOUNT = 'account';
    case ANYONE = 'anyone';
}
