<?php

namespace App\Enums;

/** Life of a commission credit (state machine, data-model 6). */
enum CreditStatus: string
{
    case ACTIVE = 'active';
    case EXHAUSTED = 'exhausted';
    case EXPIRED = 'expired';
    case VOIDED = 'voided';
}
