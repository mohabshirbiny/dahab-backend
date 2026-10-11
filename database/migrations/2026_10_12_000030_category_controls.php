<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 019 US7: category controls and what customers can see
 * (specs/019-app-content/data-model.md §3).
 *
 * `category_control` is schema §14 as designed — the columns are unchanged — plus the reason a
 * founder gave and the integrity the audit trail needs: a control is never edited or deleted,
 * only cleared once (the guard, SQLSTATE DH019), and at most one control of a level is active
 * for a category. Stopping everything is one row per category, written in one transaction.
 *
 * Four boolean settings, group `visibility`, hold what customers can see; the design's "Now"
 * column is their default. The permissions are `StaffPermission` codes (no migration rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE category_control (
              control_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              category     piece_category NOT NULL,
              level        TEXT NOT NULL CHECK (level IN
                             ('stop_new_listings','pause_category','stop_everything')),
              is_active    BOOLEAN NOT NULL DEFAULT TRUE,
              message_en   TEXT,
              message_ar   TEXT,
              reason       TEXT NOT NULL CHECK (char_length(btrim(reason)) > 0),
              set_by       UUID NOT NULL REFERENCES staff(staff_id),
              set_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
              cleared_by   UUID REFERENCES staff(staff_id),
              cleared_at   TIMESTAMPTZ,
              CONSTRAINT category_control_cleared_pair CHECK ((cleared_by IS NULL) = (cleared_at IS NULL)),
              CONSTRAINT category_control_active_cleared CHECK (is_active = (cleared_at IS NULL))
            );
            CREATE UNIQUE INDEX uq_category_control_active ON category_control (category, level) WHERE is_active;
            CREATE INDEX idx_category_control_history ON category_control (set_at DESC);

            -- A control is a record of what staff decided: it is cleared once and never edited.
            CREATE OR REPLACE FUNCTION category_control_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a category control is never deleted' USING ERRCODE = 'DH019';
              END IF;
              IF OLD.is_active IS NOT TRUE THEN
                RAISE EXCEPTION 'a cleared category control never changes' USING ERRCODE = 'DH019';
              END IF;
              IF NEW.control_id IS DISTINCT FROM OLD.control_id OR NEW.category IS DISTINCT FROM OLD.category
                 OR NEW.level IS DISTINCT FROM OLD.level OR NEW.message_en IS DISTINCT FROM OLD.message_en
                 OR NEW.message_ar IS DISTINCT FROM OLD.message_ar OR NEW.reason IS DISTINCT FROM OLD.reason
                 OR NEW.set_by IS DISTINCT FROM OLD.set_by OR NEW.set_at IS DISTINCT FROM OLD.set_at THEN
                RAISE EXCEPTION 'only the clearing of a category control may be written' USING ERRCODE = 'DH019';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_category_control_guard BEFORE UPDATE OR DELETE ON category_control
              FOR EACH ROW EXECUTE FUNCTION category_control_guard();

            INSERT INTO setting (setting_key, value_bool, unit, description) VALUES
              ('visibility.rapaport_reference', TRUE,  'bool', 'Show the Rapaport reference price to customers'),
              ('visibility.payout_averages',    FALSE, 'bool', 'Show how long payouts usually take and how many sales complete'),
              ('visibility.listing_stats',      TRUE,  'bool', 'Show views and requests on a listing'),
              ('visibility.sharing',            TRUE,  'bool', 'Let customers share a piece');
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM category_control)
                 OR EXISTS (SELECT 1 FROM setting_history WHERE setting_key LIKE 'visibility.%')
                 OR EXISTS (SELECT 1 FROM setting
                             WHERE setting_key IN ('visibility.rapaport_reference','visibility.listing_stats','visibility.sharing')
                               AND value_bool IS DISTINCT FROM TRUE)
                 OR EXISTS (SELECT 1 FROM setting
                             WHERE setting_key = 'visibility.payout_averages' AND value_bool IS DISTINCT FROM FALSE)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 019 controls were used: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_category_control_guard ON category_control;
            DROP FUNCTION IF EXISTS category_control_guard();
            DROP TABLE IF EXISTS category_control;
            DELETE FROM setting WHERE setting_key LIKE 'visibility.%';
            SQL);
    }
};
