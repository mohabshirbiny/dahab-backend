<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seller notes of 2026-10-27 (docs/features/seller-notes-oct27.md).
 *
 * 1. A seller may change the making charge (gold) or the asking price (stones)
 *    while the piece waits for review, as while it is live: guard DH023 now
 *    allows a price write in `in_review` too, with the same history row
 *    (`listing_price_recorded` checks it for `in_review` as for `live`).
 * 2. `seller_sales_count(listing)`: how many sales the piece's seller has
 *    completed (orders settled). The market scope cannot read `order`, so the
 *    function reads it as `system` and returns the count only — never who.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION listing_price_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.making_charge_per_g IS NOT DISTINCT FROM OLD.making_charge_per_g
                 AND NEW.asking_price IS NOT DISTINCT FROM OLD.asking_price THEN
                RETURN NEW;
              END IF;
              IF OLD.state NOT IN ('draft','changes_requested','in_review','live') THEN
                RAISE EXCEPTION 'listing % price cannot change in state %', OLD.listing_id, OLD.state USING ERRCODE = 'DH023';
              END IF;
              IF OLD.state IN ('in_review','live') AND NEW.state IS DISTINCT FROM OLD.state THEN
                RAISE EXCEPTION 'listing % price and state cannot change together', OLD.listing_id USING ERRCODE = 'DH023';
              END IF;
              IF OLD.state = 'live' AND listing_price_locked(OLD.listing_id) THEN
                RAISE EXCEPTION 'listing % price is locked by a market-maker approval', OLD.listing_id USING ERRCODE = 'DH023';
              END IF;
              RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION listing_price_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF OLD.state NOT IN ('in_review','live')
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

            CREATE OR REPLACE FUNCTION seller_sales_count(p_listing UUID) RETURNS integer
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_count INTEGER;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT count(*) INTO v_count
                FROM "order" o JOIN listing l ON l.listing_id = p_listing
               WHERE o.seller_id = l.seller_id AND o.settlement_txn_id IS NOT NULL;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_count;
            END $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS seller_sales_count(UUID);

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
            SQL);
    }
};
