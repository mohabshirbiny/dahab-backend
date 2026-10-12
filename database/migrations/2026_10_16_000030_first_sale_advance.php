<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 023 M3 — the first-sale advance (specs/023-first-sale-and-referral/data-model.md §3).
 *
 * `first_sale_enablement`: staff turn the advance on for one seller (reason mandatory). One open
 * enablement per seller. `first_sale_advance`: one row per seller, ever (UNIQUE seller_id — the
 * database's "first sale"), one per order; moves once outstanding -> recovered | written_off.
 * Guard DH025. `listing_price_locked()` is replaced so a piece that carried an advance keeps its
 * price (spec 022 coupling, D23-4). The ledger needs no change: `first_sale_payout` and
 * `external_equity` exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE first_sale_enablement (
              enablement_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              seller_id       UUID NOT NULL REFERENCES customer(customer_id),
              enabled_by      UUID NOT NULL REFERENCES staff(staff_id),
              reason          TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),
              created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
              revoked_by      UUID REFERENCES staff(staff_id),
              revoked_at      TIMESTAMPTZ,
              used_advance_id UUID,
              CONSTRAINT first_sale_enablement_revoke_pair CHECK ((revoked_by IS NULL) = (revoked_at IS NULL)),
              CONSTRAINT first_sale_enablement_not_both CHECK (revoked_at IS NULL OR used_advance_id IS NULL)
            );
            CREATE UNIQUE INDEX uq_first_sale_enablement_open ON first_sale_enablement (seller_id)
              WHERE revoked_at IS NULL AND used_advance_id IS NULL;

            CREATE TABLE first_sale_advance (
              advance_id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id                 UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
              seller_id                UUID NOT NULL UNIQUE REFERENCES customer(customer_id),
              listing_id               UUID NOT NULL REFERENCES listing(listing_id),
              amount_egp               NUMERIC(18,4) NOT NULL CHECK (amount_egp > 0),
              cap_applied_egp          NUMERIC(18,4) NOT NULL,
              provisional_proceeds_egp NUMERIC(18,4) NOT NULL,
              basis                    TEXT NOT NULL CHECK (basis IN ('promo_code','staff_enablement')),
              promo_code               TEXT REFERENCES promo_code(code),
              enablement_id            UUID REFERENCES first_sale_enablement(enablement_id),
              advance_txn_id           UUID NOT NULL REFERENCES ledger_transaction(ledger_txn_id),
              status                   TEXT NOT NULL DEFAULT 'outstanding' CHECK (status IN ('outstanding','recovered','written_off')),
              recovered_txn_id         UUID REFERENCES ledger_transaction(ledger_txn_id),
              recovered_egp            NUMERIC(18,4),
              shortfall_absorbed_egp   NUMERIC(18,4),
              written_off_at           TIMESTAMPTZ,
              written_off_reason       TEXT CHECK (written_off_reason IS NULL OR written_off_reason IN
                                         ('buyer_did_not_pay','cancelled_at_inspection','cancelled_by_staff','dispute_against_sale')),
              written_off_amount_egp   NUMERIC(18,4),
              created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT advance_within_cap CHECK (amount_egp <= cap_applied_egp),
              CONSTRAINT advance_basis_ref CHECK ((basis = 'promo_code') = (promo_code IS NOT NULL)
                AND (basis = 'staff_enablement') = (enablement_id IS NOT NULL)),
              CONSTRAINT advance_recovered_shape CHECK ((status = 'recovered') = (recovered_txn_id IS NOT NULL
                AND recovered_egp IS NOT NULL AND shortfall_absorbed_egp IS NOT NULL)),
              CONSTRAINT advance_recovered_sum CHECK (status <> 'recovered' OR recovered_egp + shortfall_absorbed_egp = amount_egp),
              CONSTRAINT advance_written_off_shape CHECK ((status = 'written_off') = (written_off_at IS NOT NULL
                AND written_off_reason IS NOT NULL AND written_off_amount_egp IS NOT NULL)),
              CONSTRAINT advance_written_off_amount CHECK (status <> 'written_off' OR written_off_amount_egp = amount_egp)
            );
            CREATE INDEX idx_first_sale_advance_status ON first_sale_advance (status);
            CREATE INDEX idx_first_sale_advance_listing ON first_sale_advance (listing_id);
            ALTER TABLE first_sale_enablement
              ADD CONSTRAINT first_sale_enablement_advance_fk FOREIGN KEY (used_advance_id) REFERENCES first_sale_advance(advance_id);
            SQL);

        DB::unprepared(<<<'SQL'
            -- DH025: an enablement may only be revoked or used, each once, never deleted.
            CREATE OR REPLACE FUNCTION first_sale_enablement_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'first-sale enablements are never deleted' USING ERRCODE = 'DH025';
              END IF;
              IF NEW.enablement_id IS DISTINCT FROM OLD.enablement_id OR NEW.seller_id IS DISTINCT FROM OLD.seller_id
                 OR NEW.enabled_by IS DISTINCT FROM OLD.enabled_by OR NEW.reason IS DISTINCT FROM OLD.reason
                 OR NEW.created_at IS DISTINCT FROM OLD.created_at
                 OR (OLD.revoked_at IS NOT NULL AND (NEW.revoked_at IS DISTINCT FROM OLD.revoked_at OR NEW.revoked_by IS DISTINCT FROM OLD.revoked_by))
                 OR (OLD.used_advance_id IS NOT NULL AND NEW.used_advance_id IS DISTINCT FROM OLD.used_advance_id) THEN
                RAISE EXCEPTION 'a first-sale enablement is only revoked or used, once' USING ERRCODE = 'DH025';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_first_sale_enablement_guard BEFORE UPDATE OR DELETE ON first_sale_enablement
              FOR EACH ROW EXECUTE FUNCTION first_sale_enablement_guard();

            -- DH025: an advance changes once, forward, and is never deleted.
            CREATE OR REPLACE FUNCTION first_sale_advance_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'first-sale advances are never deleted' USING ERRCODE = 'DH025';
              END IF;
              IF NEW.advance_id IS DISTINCT FROM OLD.advance_id OR NEW.order_id IS DISTINCT FROM OLD.order_id
                 OR NEW.seller_id IS DISTINCT FROM OLD.seller_id OR NEW.listing_id IS DISTINCT FROM OLD.listing_id
                 OR NEW.amount_egp IS DISTINCT FROM OLD.amount_egp OR NEW.cap_applied_egp IS DISTINCT FROM OLD.cap_applied_egp
                 OR NEW.provisional_proceeds_egp IS DISTINCT FROM OLD.provisional_proceeds_egp
                 OR NEW.basis IS DISTINCT FROM OLD.basis OR NEW.promo_code IS DISTINCT FROM OLD.promo_code
                 OR NEW.enablement_id IS DISTINCT FROM OLD.enablement_id
                 OR NEW.advance_txn_id IS DISTINCT FROM OLD.advance_txn_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'a first-sale advance keeps its figures' USING ERRCODE = 'DH025';
              END IF;
              IF OLD.status <> 'outstanding' THEN
                RAISE EXCEPTION 'a first-sale advance that has ended never moves again' USING ERRCODE = 'DH025';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_first_sale_advance_guard BEFORE UPDATE OR DELETE ON first_sale_advance
              FOR EACH ROW EXECUTE FUNCTION first_sale_advance_guard();

            ALTER TABLE first_sale_enablement ENABLE ROW LEVEL SECURITY;
            ALTER TABLE first_sale_enablement FORCE  ROW LEVEL SECURITY;
            ALTER TABLE first_sale_advance    ENABLE ROW LEVEL SECURITY;
            ALTER TABLE first_sale_advance    FORCE  ROW LEVEL SECURITY;

            -- Enablements: staff only (a customer never reads them).
            CREATE POLICY first_sale_enablement_staff ON first_sale_enablement FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));

            -- Advances: staff everything; the seller reads their own; the buyer settling the order
            -- (the `order` scope) reads and moves the advance of the order it settles.
            CREATE POLICY first_sale_advance_staff ON first_sale_advance FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY first_sale_advance_owner ON first_sale_advance FOR SELECT
              USING (seller_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY first_sale_advance_order ON first_sale_advance FOR ALL
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = first_sale_advance.order_id))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = first_sale_advance.order_id));

            -- Whether staff enabled the advance for this seller and it is still open (a customer never
            -- reads the enablement itself): the id, or NULL.
            CREATE OR REPLACE FUNCTION first_sale_open_enablement(p_seller UUID) RETURNS uuid
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_id UUID;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT enablement_id INTO v_id FROM first_sale_enablement
               WHERE seller_id = p_seller AND revoked_at IS NULL AND used_advance_id IS NULL;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_id;
            END $$;

            -- Is this piece's price fixed? True while a market-maker approval exists (spec 019 C3) or
            -- once an advance was paid on it (spec 023 D23-4), whatever the advance's status.
            CREATE OR REPLACE FUNCTION listing_price_locked(p_listing UUID) RETURNS boolean
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_locked BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT EXISTS (SELECT 1 FROM market_maker_approval WHERE listing_id = p_listing)
                  OR EXISTS (SELECT 1 FROM first_sale_advance WHERE listing_id = p_listing) INTO v_locked;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_locked;
            END $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT set_config('app.rls_scope', 'maintenance', true)");
        $used = DB::selectOne('SELECT (EXISTS (SELECT 1 FROM first_sale_enablement) OR EXISTS (SELECT 1 FROM first_sale_advance)) AS used')->used;
        if ($used) {
            throw new RuntimeException('Spec 023 first-sale advances or enablements exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
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

            ALTER TABLE first_sale_enablement DROP CONSTRAINT first_sale_enablement_advance_fk;
            DROP TABLE first_sale_advance;
            DROP TABLE first_sale_enablement;
            DROP FUNCTION IF EXISTS first_sale_open_enablement(UUID);
            DROP FUNCTION IF EXISTS first_sale_advance_guard();
            DROP FUNCTION IF EXISTS first_sale_enablement_guard();
            SQL);
    }
};
