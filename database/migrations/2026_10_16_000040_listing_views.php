<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 023 M4 — the view counter of a listing (specs/023-first-sale-and-referral/data-model.md §4,
 * research R18): one row per viewer per piece per day, where the viewer is a keyed hash (the
 * table cannot say who looked). Staff only (forced RLS, elevated policy). Rows are never edited;
 * a row is deleted only once older than `listing.view_retention_days` (DH027).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE listing_view (
              listing_id  UUID NOT NULL REFERENCES listing(listing_id),
              day         DATE NOT NULL,
              viewer_hash TEXT NOT NULL,
              PRIMARY KEY (listing_id, day, viewer_hash)
            );
            CREATE INDEX idx_listing_view_day ON listing_view (day);

            CREATE OR REPLACE FUNCTION listing_view_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_days INTEGER;
            BEGIN
              IF TG_OP = 'UPDATE' THEN
                RAISE EXCEPTION 'listing views are never edited' USING ERRCODE = 'DH027';
              END IF;
              SELECT value_numeric::int INTO v_days FROM setting WHERE setting_key = 'listing.view_retention_days';
              IF v_days IS NULL OR OLD.day >= (now() AT TIME ZONE 'Africa/Cairo')::date - v_days THEN
                RAISE EXCEPTION 'a listing view is kept for % days', v_days USING ERRCODE = 'DH027';
              END IF;
              RETURN OLD;
            END $$;
            CREATE TRIGGER trg_listing_view_guard BEFORE UPDATE OR DELETE ON listing_view
              FOR EACH ROW EXECUTE FUNCTION listing_view_guard();

            ALTER TABLE listing_view ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_view FORCE  ROW LEVEL SECURITY;
            CREATE POLICY listing_view_staff ON listing_view FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // View counts are not business records (data-model §4): dropped without a refusal.
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS listing_view;
            DROP FUNCTION IF EXISTS listing_view_guard();
            SQL);
    }
};
