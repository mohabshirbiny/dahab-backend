<?php

namespace App\Enums;

/** What looks wrong with a listing — the prototype's six choices (spec 017 FR-052). */
enum ReportReason: string
{
    case PHOTOS_NOT_GENUINE = 'photos_not_genuine';
    case PRICE_OR_WEIGHT_WRONG = 'price_or_weight_wrong';
    case DESCRIPTION_MISMATCH = 'description_mismatch';
    case NOT_THEIRS_TO_SELL = 'not_theirs_to_sell';
    case OFF_PLATFORM_DEALING = 'off_platform_dealing';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PHOTOS_NOT_GENUINE => 'The photos look fake or taken from somewhere else',
            self::PRICE_OR_WEIGHT_WRONG => 'The price or the weight looks wrong',
            self::DESCRIPTION_MISMATCH => 'The description does not match the photos',
            self::NOT_THEIRS_TO_SELL => 'The piece may not be theirs to sell',
            self::OFF_PLATFORM_DEALING => 'The seller is trying to deal outside Dahab',
            self::OTHER => 'Something else',
        };
    }
}
