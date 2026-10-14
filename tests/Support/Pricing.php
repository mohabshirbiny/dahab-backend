<?php

namespace Tests\Support;

use App\Models\KaratPriceAdjustment;

/**
 * Pricing fixtures shared by tests.
 */
final class Pricing
{
    /**
     * The adjustments every karat was seeded with before the testing fixes (buy −15 / sell +15 EGP
     * per gram, fixed). A karat now starts at 0 / 0; tests whose worked figures were written with
     * the old default set it here explicitly, so their numbers stay as they were.
     */
    public static function legacyAdjustments(): void
    {
        KaratPriceAdjustment::query()->where('side', 'buy')->update(['kind' => 'fixed', 'value' => '-15']);
        KaratPriceAdjustment::query()->where('side', 'sell')->update(['kind' => 'fixed', 'value' => '15']);
    }
}
