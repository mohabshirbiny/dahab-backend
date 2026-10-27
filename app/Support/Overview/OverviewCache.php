<?php

namespace App\Support\Overview;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A short reuse of the Overview's global figures (dashboard performance).
 *
 * One entry per section, never per response or per viewer: the figures are
 * the same for every viewer allowed to see them, so a key holds no user data.
 * The caller checks the viewer's permission before asking for a section, so
 * a section the viewer may not see is never read from here nor returned.
 * Indicators only — no money action reads these values.
 */
final class OverviewCache
{
    public const PREFIX = 'dashboard-overview:';

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function section(string $section, Closure $compute): mixed
    {
        $seconds = (int) config('dahab-finance.overview_cache_seconds');

        return $seconds > 0 ? Cache::remember(self::PREFIX.$section, $seconds, $compute) : $compute();
    }
}
