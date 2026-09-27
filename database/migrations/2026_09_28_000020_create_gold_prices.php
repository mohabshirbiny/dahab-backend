<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gold prices (spec 005, schema §3b): the append-only `gold_price` record
 * (24K bid and ask, from the feed or a manual entry), the manual requests
 * that may wait for a confirmation, and the feed's health row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE manual_gold_price (
                manual_gold_price_id   BIGSERIAL PRIMARY KEY,
                bid_24k                NUMERIC(18,4) NOT NULL,
                ask_24k                NUMERIC(18,4) NOT NULL,
                previous_gold_price_id BIGINT,
                deviation_pct          NUMERIC(8,4),
                requires_confirmation  BOOLEAN NOT NULL,
                reason                 TEXT NOT NULL,
                entered_by             UUID NOT NULL REFERENCES staff(staff_id),
                status                 TEXT NOT NULL CHECK (status IN ('pending','effective','superseded','lapsed')),
                confirmed_by           UUID REFERENCES staff(staff_id),
                confirmed_at           TIMESTAMPTZ,
                expires_at             TIMESTAMPTZ,
                created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT manual_gold_price_positive CHECK (bid_24k > 0 AND ask_24k >= bid_24k),
                CONSTRAINT manual_gold_price_confirmed CHECK ((confirmed_by IS NULL) = (confirmed_at IS NULL))
            )
        ");
        DB::statement("CREATE UNIQUE INDEX manual_gold_price_one_pending ON manual_gold_price ((true)) WHERE status = 'pending'");

        DB::statement("
            CREATE TABLE gold_price (
                gold_price_id        BIGSERIAL PRIMARY KEY,
                source               TEXT NOT NULL CHECK (source IN ('feed','manual')),
                bid_24k              NUMERIC(18,4) NOT NULL,
                ask_24k              NUMERIC(18,4) NOT NULL,
                manual_gold_price_id BIGINT REFERENCES manual_gold_price(manual_gold_price_id),
                recorded_by          UUID NOT NULL REFERENCES staff(staff_id),
                effective_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT gold_price_positive CHECK (bid_24k > 0 AND ask_24k >= bid_24k),
                CONSTRAINT gold_price_manual_link CHECK (source <> 'manual' OR manual_gold_price_id IS NOT NULL)
            )
        ");
        DB::statement('CREATE INDEX gold_price_current ON gold_price (effective_at DESC, gold_price_id DESC)');
        DB::statement('
            ALTER TABLE manual_gold_price
              ADD CONSTRAINT manual_gold_price_previous FOREIGN KEY (previous_gold_price_id) REFERENCES gold_price(gold_price_id)
        ');

        DB::unprepared("
            CREATE TRIGGER gold_price_no_update
                BEFORE UPDATE OR DELETE ON gold_price
                FOR EACH ROW EXECUTE FUNCTION pricing_block_mutation();

            -- A manual request only moves forward from 'pending'; nothing else changes.
            CREATE OR REPLACE FUNCTION manual_gold_price_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'manual_gold_price rows are never deleted';
                END IF;
                IF OLD.status <> 'pending'
                   OR NEW.status NOT IN ('effective','superseded','lapsed')
                   OR NEW.bid_24k IS DISTINCT FROM OLD.bid_24k
                   OR NEW.ask_24k IS DISTINCT FROM OLD.ask_24k
                   OR NEW.previous_gold_price_id IS DISTINCT FROM OLD.previous_gold_price_id
                   OR NEW.deviation_pct IS DISTINCT FROM OLD.deviation_pct
                   OR NEW.requires_confirmation IS DISTINCT FROM OLD.requires_confirmation
                   OR NEW.reason IS DISTINCT FROM OLD.reason
                   OR NEW.entered_by IS DISTINCT FROM OLD.entered_by
                   OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'manual_gold_price: only a pending request can move to effective, superseded or lapsed';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER manual_gold_price_forward_only
                BEFORE UPDATE OR DELETE ON manual_gold_price
                FOR EACH ROW EXECUTE FUNCTION manual_gold_price_guard();
        ");

        DB::statement('
            CREATE TABLE price_feed_status (
                provider         TEXT PRIMARY KEY,
                last_success_at  TIMESTAMPTZ,
                last_failure_at  TIMESTAMPTZ,
                last_error       TEXT
            )
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS price_feed_status');
        DB::statement('ALTER TABLE IF EXISTS manual_gold_price DROP CONSTRAINT IF EXISTS manual_gold_price_previous');
        DB::statement('DROP TABLE IF EXISTS gold_price');
        DB::statement('DROP TABLE IF EXISTS manual_gold_price');
        DB::statement('DROP FUNCTION IF EXISTS manual_gold_price_guard()');
    }
};
