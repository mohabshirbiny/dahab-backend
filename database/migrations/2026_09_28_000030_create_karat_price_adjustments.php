<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-karat, per-side price adjustments (spec 005, schema §3b). They replace
 * the two price_correction.* settings and are seeded with the same values
 * (buy side −15, sell side +15 EGP per gram, fixed) for every karat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE karat_price_adjustment (
                karat_code  SMALLINT NOT NULL REFERENCES karat(karat_code),
                side        TEXT NOT NULL CHECK (side IN ('buy','sell')),
                kind        TEXT NOT NULL CHECK (kind IN ('fixed','percent')),
                value       NUMERIC(18,4) NOT NULL,
                updated_by  UUID REFERENCES staff(staff_id),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (karat_code, side),
                CONSTRAINT karat_price_adjustment_percent CHECK (kind <> 'percent' OR value > -100)
            )
        ");

        DB::statement("
            CREATE TABLE karat_price_adjustment_history (
                history_id  BIGSERIAL PRIMARY KEY,
                karat_code  SMALLINT NOT NULL REFERENCES karat(karat_code),
                side        TEXT NOT NULL CHECK (side IN ('buy','sell')),
                old_kind    TEXT NOT NULL,
                old_value   NUMERIC(18,4) NOT NULL,
                new_kind    TEXT NOT NULL,
                new_value   NUMERIC(18,4) NOT NULL,
                changed_by  UUID NOT NULL REFERENCES staff(staff_id),
                reason      TEXT NOT NULL,
                changed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");
        DB::statement('CREATE INDEX karat_price_adjustment_history_karat ON karat_price_adjustment_history (karat_code, changed_at DESC)');

        DB::unprepared('
            CREATE TRIGGER karat_price_adjustment_history_no_update
                BEFORE UPDATE OR DELETE ON karat_price_adjustment_history
                FOR EACH ROW EXECUTE FUNCTION pricing_block_mutation();
        ');

        DB::statement("
            INSERT INTO karat_price_adjustment (karat_code, side, kind, value)
            SELECT karat_code, 'buy',  'fixed', -15 FROM karat
            UNION ALL
            SELECT karat_code, 'sell', 'fixed',  15 FROM karat
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS karat_price_adjustment_history');
        DB::statement('DROP TABLE IF EXISTS karat_price_adjustment');
    }
};
