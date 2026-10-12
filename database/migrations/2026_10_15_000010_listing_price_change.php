<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 022 — change the price of a live piece
 * (specs/022-live-listing-price-change/data-model.md).
 *
 * A seller changes the making charge (gold) or the asking price (stones) of a
 * live piece while nobody is in line. The change is recorded in the append-only
 * `listing_price_change`; guard DH023 refuses a price write outside draft /
 * changes requested / live, on a piece approved for market makers, together
 * with a state move, or without its history row. `listing_guard()` is not
 * redefined.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE listing_price_change (
              change_id         BIGSERIAL PRIMARY KEY,
              listing_id        UUID NOT NULL REFERENCES listing(listing_id),
              field             TEXT NOT NULL CHECK (field IN ('making_charge_per_g','asking_price')),
              old_value         NUMERIC(18,4) NOT NULL CHECK (old_value >= 0 AND old_value = round(old_value, 2)),
              new_value         NUMERIC(18,4) NOT NULL CHECK (new_value >= 0 AND new_value = round(new_value, 2)),
              actor_customer_id UUID REFERENCES customer(customer_id),
              actor_staff_id    UUID REFERENCES staff(staff_id),
              changed_at        TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              txid              BIGINT NOT NULL DEFAULT txid_current(),
              CONSTRAINT listing_price_change_differs CHECK (new_value <> old_value),
              CONSTRAINT listing_price_change_asking_positive CHECK (field <> 'asking_price' OR new_value > 0),
              CONSTRAINT listing_price_change_one_actor CHECK (num_nonnulls(actor_customer_id, actor_staff_id) = 1)
            );
            CREATE INDEX idx_listing_price_change_listing ON listing_price_change (listing_id, change_id);

            CREATE OR REPLACE FUNCTION listing_price_change_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'listing price history is append-only' USING ERRCODE = 'DH023';
            END $$;
            CREATE TRIGGER trg_listing_price_change_immutable BEFORE UPDATE OR DELETE ON listing_price_change
              FOR EACH ROW EXECUTE FUNCTION listing_price_change_immutable();

            ALTER TABLE listing_price_change ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_price_change FORCE  ROW LEVEL SECURITY;
            CREATE POLICY listing_price_change_isolation ON listing_price_change FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR EXISTS (SELECT 1 FROM listing l
                       WHERE l.listing_id = listing_price_change.listing_id
                         AND l.seller_id = (SELECT dahab_current_customer_id())))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                       actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
                       AND EXISTS (SELECT 1 FROM listing l
                                    WHERE l.listing_id = listing_price_change.listing_id
                                      AND l.seller_id = (SELECT dahab_current_customer_id()))));
            SQL);

        DB::unprepared(<<<'SQL'
            -- Is this piece's price fixed? True while a market-maker approval exists (spec 019 C3).
            -- Read with the system scope, then the caller's scope is put back; it answers a boolean
            -- about one listing and nothing else. Spec 023 extends it (an advance paid on the piece).
            CREATE OR REPLACE FUNCTION listing_price_locked(p_listing UUID) RETURNS boolean
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_locked BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT EXISTS (SELECT 1 FROM market_maker_approval WHERE listing_id = p_listing) INTO v_locked;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_locked;
            END $$;

            CREATE OR REPLACE FUNCTION listing_price_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.making_charge_per_g IS NOT DISTINCT FROM OLD.making_charge_per_g
                 AND NEW.asking_price IS NOT DISTINCT FROM OLD.asking_price THEN
                RETURN NEW;
              END IF;
              IF OLD.state NOT IN ('draft','changes_requested','live') THEN
                RAISE EXCEPTION 'listing % price cannot change in state %', OLD.listing_id, OLD.state USING ERRCODE = 'DH023';
              END IF;
              IF OLD.state = 'live' AND NEW.state IS DISTINCT FROM OLD.state THEN
                RAISE EXCEPTION 'listing % price and state cannot change together', OLD.listing_id USING ERRCODE = 'DH023';
              END IF;
              IF OLD.state = 'live' AND listing_price_locked(OLD.listing_id) THEN
                RAISE EXCEPTION 'listing % price is locked by a market-maker approval', OLD.listing_id USING ERRCODE = 'DH023';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_listing_price_guard BEFORE UPDATE OF making_charge_per_g, asking_price ON listing
              FOR EACH ROW EXECUTE FUNCTION listing_price_guard();

            -- A change made while live has its listing_price_change row in the same transaction.
            CREATE OR REPLACE FUNCTION listing_price_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF OLD.state <> 'live'
                 OR (NEW.making_charge_per_g IS NOT DISTINCT FROM OLD.making_charge_per_g
                     AND NEW.asking_price IS NOT DISTINCT FROM OLD.asking_price) THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT EXISTS (SELECT 1 FROM listing_price_change c
                              WHERE c.listing_id = NEW.listing_id AND c.txid = txid_current()
                                AND c.new_value = COALESCE(NEW.making_charge_per_g, NEW.asking_price))
                INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'listing % price changed without a history row', NEW.listing_id USING ERRCODE = 'DH023';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_listing_price_recorded
              AFTER UPDATE OF making_charge_per_g, asking_price ON listing
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION listing_price_recorded();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::selectOne('SELECT EXISTS (SELECT 1 FROM listing_price_change) AS used')->used) {
            throw new RuntimeException('Spec 022 price changes exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_listing_price_recorded ON listing;
            DROP TRIGGER IF EXISTS trg_listing_price_guard ON listing;
            DROP FUNCTION IF EXISTS listing_price_recorded();
            DROP FUNCTION IF EXISTS listing_price_guard();
            DROP FUNCTION IF EXISTS listing_price_locked(UUID);
            DROP TABLE IF EXISTS listing_price_change;
            DROP FUNCTION IF EXISTS listing_price_change_immutable();
            SQL);
    }
};
