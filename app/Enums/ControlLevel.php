<?php

namespace App\Enums;

/**
 * The three levels of an operating control (schema §14, spec 019 US7). Each has its own
 * permission: stopping new listings is lighter than pausing a category, and stopping
 * everything is the founders' decision.
 */
enum ControlLevel: string
{
    case STOP_NEW_LISTINGS = 'stop_new_listings';
    case PAUSE_CATEGORY = 'pause_category';
    case STOP_EVERYTHING = 'stop_everything';

    public function permission(): StaffPermission
    {
        return match ($this) {
            self::STOP_NEW_LISTINGS => StaffPermission::CATEGORY_STOP_NEW,
            self::PAUSE_CATEGORY => StaffPermission::CATEGORY_PAUSE,
            self::STOP_EVERYTHING => StaffPermission::PLATFORM_STOP_EVERYTHING,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::STOP_NEW_LISTINGS => 'New listings stopped',
            self::PAUSE_CATEGORY => 'Category paused',
            self::STOP_EVERYTHING => 'Everything stopped',
        };
    }

    /** Heavier levels count for more when the page shows one state per category. */
    public function severity(): int
    {
        return match ($this) {
            self::STOP_NEW_LISTINGS => 1,
            self::PAUSE_CATEGORY => 2,
            self::STOP_EVERYTHING => 3,
        };
    }
}
