<?php

namespace App\Enums;

/** Why a customer closed their account — the prototype's six choices (spec 017 FR-050). */
enum CloseReason: string
{
    case FINISHED = 'finished';
    case FEES_TOO_HIGH = 'fees_too_high';
    case TOO_SLOW_TO_SELL = 'too_slow_to_sell';
    case DATA_TRUST = 'data_trust';
    case SOMETHING_WENT_WRONG = 'something_went_wrong';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FINISHED => 'I finished what I came for',
            self::FEES_TOO_HIGH => 'Fees are too high',
            self::TOO_SLOW_TO_SELL => 'It took too long to sell',
            self::DATA_TRUST => "I don't trust it with my data",
            self::SOMETHING_WENT_WRONG => 'Something went wrong',
            self::OTHER => 'Another reason',
        };
    }
}
