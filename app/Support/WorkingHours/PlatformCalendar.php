<?php

namespace App\Support\WorkingHours;

use App\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * "Today" for a closure: in the branch's own timezone, or in the platform
 * timezone for an all-branch holiday. The application clock runs in UTC.
 */
final class PlatformCalendar
{
    public const TIMEZONE = 'Africa/Cairo';

    public static function todayFor(?int $branchId): string
    {
        $timezone = $branchId === null
            ? self::TIMEZONE
            : (Branch::query()->whereKey($branchId)->value('timezone') ?? self::TIMEZONE);

        return CarbonImmutable::now($timezone)->toDateString();
    }
}
