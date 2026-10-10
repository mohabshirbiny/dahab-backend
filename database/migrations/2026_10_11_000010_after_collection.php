<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 018 — after collection: the free relist and the rating
 * (specs/018-after-collection/data-model.md).
 *
 * Guards: DH004 (listing — the new draft → live move and its INSERT checks),
 * DH012 (no seller invoice on a sale of no commission), DH016 (an order rating
 * and the free-relist window never change once written).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- The window of the free relist, stored by the staff handover (research R1).
            ALTER TABLE collection
              ADD COLUMN free_relist_until TIMESTAMPTZ,
              ADD CONSTRAINT collection_free_relist_shape CHECK (
                free_relist_until IS NULL OR (collected_at IS NOT NULL AND free_relist_until > collected_at));

            CREATE OR REPLACE FUNCTION collection_free_relist_once() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.free_relist_until IS NOT NULL AND NEW.free_relist_until IS DISTINCT FROM OLD.free_relist_until THEN
                RAISE EXCEPTION 'the free-relist window is set once' USING ERRCODE = 'DH016';
              END IF;
              IF OLD.free_relist_until IS NULL AND NEW.free_relist_until IS NOT NULL
                 AND (OLD.collected_at IS NOT NULL OR NEW.collected_at IS NULL) THEN
                RAISE EXCEPTION 'the free-relist window is set with the handover' USING ERRCODE = 'DH016';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_collection_free_relist_once BEFORE UPDATE ON collection
              FOR EACH ROW EXECUTE FUNCTION collection_free_relist_once();

            -- The link that is the 0% commission waiver (research R4): set at INSERT, never changes.
            ALTER TABLE listing
              ADD COLUMN relisted_from_order_id UUID REFERENCES "order"(order_id);
            CREATE UNIQUE INDEX one_free_relist_per_order ON listing (relisted_from_order_id)
              WHERE relisted_from_order_id IS NOT NULL;

            INSERT INTO listing_transition (from_state, to_state, note)
              VALUES ('draft', 'live', 'free relist: no review');

            -- Is this order one a free relist may be made from? Read with the system scope, then the
            -- caller's scope is put back (the seller of the new listing is the buyer of the old order).
            CREATE OR REPLACE FUNCTION free_relist_origin_ok(p_order UUID, p_seller UUID) RETURNS boolean
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT o.state = 'completed' AND o.buyer_id = p_seller
                     AND c.free_relist_until IS NOT NULL AND c.free_relist_until >= clock_timestamp()
                     AND l.relisted_from_order_id IS NULL
                INTO v_ok
                FROM "order" o
                JOIN collection c ON c.order_id = o.order_id
                JOIN listing l ON l.listing_id = o.listing_id
               WHERE o.order_id = p_order;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN COALESCE(v_ok, FALSE);
            END $$;

            -- Listing guard: spec 010's, plus the link rules (research R5).
            CREATE OR REPLACE FUNCTION listing_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'listings are never deleted' USING ERRCODE = 'DH004';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.state <> 'draft' THEN
                  RAISE EXCEPTION 'a listing starts as a draft, not %', NEW.state USING ERRCODE = 'DH004';
                END IF;
                IF NEW.relisted_from_order_id IS NOT NULL
                   AND NOT free_relist_origin_ok(NEW.relisted_from_order_id, NEW.seller_id) THEN
                  RAISE EXCEPTION 'order % cannot be relisted for free by this seller', NEW.relisted_from_order_id
                    USING ERRCODE = 'DH004';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.seller_id IS DISTINCT FROM OLD.seller_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
                 OR NEW.relisted_from_order_id IS DISTINCT FROM OLD.relisted_from_order_id THEN
                RAISE EXCEPTION 'listing % identity columns cannot change', OLD.listing_id USING ERRCODE = 'DH004';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM listing_transition
                               WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'illegal listing transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH004';
                END IF;
                IF OLD.state = 'draft' AND NEW.state = 'live' AND NEW.relisted_from_order_id IS NULL THEN
                  RAISE EXCEPTION 'a draft goes live only as a free relist' USING ERRCODE = 'DH004';
                END IF;
                NEW.state_changed_at := clock_timestamp();
                IF NEW.state = 'live' AND NEW.listed_at IS NULL THEN
                  NEW.listed_at := clock_timestamp();
                END IF;
              END IF;
              RETURN NEW;
            END $$;
            SQL);

        DB::unprepared(<<<'SQL'
            -- One rating per party per order, immutable (research R12).
            CREATE TABLE order_rating (
              rating_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id    UUID NOT NULL REFERENCES "order"(order_id),
              party_role  TEXT NOT NULL CHECK (party_role IN ('seller','buyer')),
              customer_id UUID NOT NULL REFERENCES customer(customer_id),
              stars       SMALLINT NOT NULL CHECK (stars BETWEEN 1 AND 5),
              note        TEXT CHECK (note IS NULL OR char_length(note) BETWEEN 1 AND 500),
              created_at  TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              CONSTRAINT order_rating_one_per_party UNIQUE (order_id, party_role)
            );
            CREATE INDEX idx_order_rating_customer ON order_rating (customer_id, created_at);

            -- The author is the order's seller or buyer for the role they rate as; the order is settled and
            -- not cancelled; the buyer only rates once it is completed. Time windows are the Action's.
            CREATE OR REPLACE FUNCTION order_rating_party() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              o RECORD;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT seller_id, buyer_id, state::text AS state, settlement_txn_id INTO o FROM "order" WHERE order_id = NEW.order_id;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT FOUND
                 OR NEW.customer_id <> (CASE NEW.party_role WHEN 'seller' THEN o.seller_id ELSE o.buyer_id END)
                 OR o.settlement_txn_id IS NULL
                 OR o.state LIKE 'cancelled%'
                 OR (NEW.party_role = 'buyer' AND o.state <> 'completed') THEN
                RAISE EXCEPTION 'order % cannot be rated by this party now', NEW.order_id USING ERRCODE = 'DH016';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_order_rating_party BEFORE INSERT ON order_rating
              FOR EACH ROW EXECUTE FUNCTION order_rating_party();

            CREATE OR REPLACE FUNCTION order_rating_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'a rating never changes' USING ERRCODE = 'DH016';
            END $$;
            CREATE TRIGGER trg_order_rating_immutable BEFORE UPDATE OR DELETE ON order_rating
              FOR EACH ROW EXECUTE FUNCTION order_rating_immutable();

            CREATE TRIGGER trg_order_rating_not_closed BEFORE INSERT ON order_rating
              FOR EACH ROW EXECUTE FUNCTION refuse_closed_customer('customer_id');

            ALTER TABLE order_rating ENABLE ROW LEVEL SECURITY;
            ALTER TABLE order_rating FORCE  ROW LEVEL SECURITY;
            CREATE POLICY order_rating_isolation ON order_rating FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));
            SQL);

        DB::unprepared(<<<'SQL'
            -- A sale of no commission has no seller invoice, and a sale with one has (checked at commit).
            CREATE OR REPLACE FUNCTION tax_invoice_waived_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_commission NUMERIC;
              v_order UUID;
              v_has BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              IF TG_TABLE_NAME = 'tax_invoice' THEN
                v_order := NEW.order_id;
                SELECT commission_amount INTO v_commission FROM "order" WHERE order_id = v_order;
                IF NEW.party_role = 'seller' AND COALESCE(v_commission, 0) = 0 THEN
                  PERFORM set_config('app.rls_scope', prev_scope, true);
                  RAISE EXCEPTION 'a sale of no commission has no seller invoice (order %)', v_order USING ERRCODE = 'DH012';
                END IF;
              ELSE
                v_order := NEW.order_id;
                IF NEW.settlement_txn_id IS NOT NULL AND COALESCE(NEW.commission_amount, 0) > 0 THEN
                  SELECT EXISTS (SELECT 1 FROM tax_invoice WHERE order_id = v_order AND party_role = 'seller') INTO v_has;
                  IF NOT v_has THEN
                    PERFORM set_config('app.rls_scope', prev_scope, true);
                    RAISE EXCEPTION 'order % was settled with a commission but has no seller invoice', v_order USING ERRCODE = 'DH012';
                  END IF;
                END IF;
              END IF;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_tax_invoice_waived
              AFTER INSERT ON tax_invoice
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION tax_invoice_waived_check();
            CREATE CONSTRAINT TRIGGER trg_order_seller_invoice_present
              AFTER UPDATE OF settlement_txn_id ON "order"
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW WHEN (OLD.settlement_txn_id IS NULL AND NEW.settlement_txn_id IS NOT NULL)
              EXECUTE FUNCTION tax_invoice_waived_check();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM order_rating)
                 OR EXISTS (SELECT 1 FROM listing WHERE relisted_from_order_id IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM collection WHERE free_relist_until IS NOT NULL)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 018 data exists: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_order_seller_invoice_present ON "order";
            DROP TRIGGER IF EXISTS trg_tax_invoice_waived ON tax_invoice;
            DROP FUNCTION IF EXISTS tax_invoice_waived_check();
            DROP TABLE IF EXISTS order_rating;
            DROP FUNCTION IF EXISTS order_rating_immutable();
            DROP FUNCTION IF EXISTS order_rating_party();

            CREATE OR REPLACE FUNCTION listing_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'listings are never deleted' USING ERRCODE = 'DH004';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.state <> 'draft' THEN
                  RAISE EXCEPTION 'a listing starts as a draft, not %', NEW.state USING ERRCODE = 'DH004';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.seller_id IS DISTINCT FROM OLD.seller_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'listing % identity columns cannot change', OLD.listing_id USING ERRCODE = 'DH004';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM listing_transition
                               WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'illegal listing transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH004';
                END IF;
                NEW.state_changed_at := clock_timestamp();
                IF NEW.state = 'live' AND NEW.listed_at IS NULL THEN
                  NEW.listed_at := clock_timestamp();
                END IF;
              END IF;
              RETURN NEW;
            END $$;

            DELETE FROM listing_transition WHERE from_state = 'draft' AND to_state = 'live';
            DROP FUNCTION IF EXISTS free_relist_origin_ok(UUID, UUID);
            DROP INDEX IF EXISTS one_free_relist_per_order;
            ALTER TABLE listing DROP COLUMN IF EXISTS relisted_from_order_id;
            DROP TRIGGER IF EXISTS trg_collection_free_relist_once ON collection;
            DROP FUNCTION IF EXISTS collection_free_relist_once();
            ALTER TABLE collection DROP CONSTRAINT IF EXISTS collection_free_relist_shape,
              DROP COLUMN IF EXISTS free_relist_until;
            SQL);
    }
};
