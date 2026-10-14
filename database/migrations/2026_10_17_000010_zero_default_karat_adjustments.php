<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Testing fixes, item 1: the starting per-karat price adjustment is 0 on both sides
 * (was buy −15 / sell +15 EGP per gram, seeded by 2026_09_28_000030). The 24K bid/ask
 * entered is used as is; only purity applies until staff set an adjustment.
 *
 * Only rows staff never changed are reset: a karat + side with no row in
 * karat_price_adjustment_history. Rows staff edited stay as they are (owner's decision).
 * Karats added later already start at 0 (CreateKaratAction / KaratPriceAdjustment::seedZero).
 */
return new class extends Migration
{
    /** The karats the original migration seeded with ±15 (2026_09_27_000010). */
    private const SEEDED = [24, 22, 21, 20, 18];

    public function up(): void
    {
        DB::update("
            UPDATE karat_price_adjustment a
               SET kind = 'fixed', value = 0, updated_at = now()
             WHERE NOT EXISTS (SELECT 1 FROM karat_price_adjustment_history h
                               WHERE h.karat_code = a.karat_code AND h.side = a.side)
        ");
    }

    public function down(): void
    {
        $codes = implode(',', self::SEEDED);

        DB::update("
            UPDATE karat_price_adjustment a
               SET kind = 'fixed', value = CASE a.side WHEN 'buy' THEN -15 ELSE 15 END, updated_at = now()
             WHERE a.karat_code IN ({$codes})
               AND NOT EXISTS (SELECT 1 FROM karat_price_adjustment_history h
                               WHERE h.karat_code = a.karat_code AND h.side = a.side)
        ");
    }
};
