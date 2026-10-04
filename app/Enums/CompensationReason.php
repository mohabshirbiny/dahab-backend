<?php

namespace App\Enums;

/** Why Dahab pays compensation — the design's list (spec 014 FR-015, research R8). */
enum CompensationReason: string
{
    case IGI_DELAY = 'igi_delay';
    case DAHAB_MISTAKE = 'dahab_mistake';
    case WASTED_TRIP = 'wasted_trip';
    case DISPUTE_SETTLEMENT = 'dispute_settlement';
    case GOODWILL = 'goodwill';

    public function label(): string
    {
        return match ($this) {
            self::IGI_DELAY => 'A delay caused by IGI',
            self::DAHAB_MISTAKE => "A mistake on Dahab's side",
            self::WASTED_TRIP => 'A wasted trip to IGI',
            self::DISPUTE_SETTLEMENT => 'Settlement of a dispute',
            self::GOODWILL => 'Goodwill',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::IGI_DELAY => 'تأخير من IGI',
            self::DAHAB_MISTAKE => 'خطأ من دهب',
            self::WASTED_TRIP => 'مشوار ضايع لـ IGI',
            self::DISPUTE_SETTLEMENT => 'تسوية نزاع',
            self::GOODWILL => 'بادرة حسن نية',
        };
    }
}
