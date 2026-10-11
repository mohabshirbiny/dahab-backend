<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 019 US6, market makers, staff side (specs/019-app-content/data-model.md §4).
 *
 * The three tables of schema §15 exactly as designed, plus the integrity the programme needs:
 * a code is `[A-Z0-9-]{3,32}`, a cap is positive, a deactivation has its pair; a use and an
 * approval are append-only (SQLSTATE DH020); the approval is unique per piece. Staff only:
 * a customer reads none of these rows (forced RLS, elevated scope).
 *
 * `first_sale` stays in the CHECK as designed but is refused by the Action (not built here).
 * The permissions `promo.manage` and `market_maker.approve` are `StaffPermission` codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE promo_code (
              code         TEXT PRIMARY KEY CHECK (code ~ '^[A-Z0-9-]{3,32}$'),
              kind         TEXT NOT NULL CHECK (kind IN ('first_sale','market_maker')),
              tied_customer_id UUID REFERENCES customer(customer_id),
              commission_waived BOOLEAN NOT NULL DEFAULT FALSE,
              gives_spread BOOLEAN NOT NULL DEFAULT FALSE,
              monthly_cap_egp NUMERIC(18,4) CHECK (monthly_cap_egp IS NULL OR monthly_cap_egp > 0),
              is_active    BOOLEAN NOT NULL DEFAULT TRUE,
              created_by   UUID NOT NULL REFERENCES staff(staff_id),
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              deactivated_by UUID REFERENCES staff(staff_id),
              deactivated_at TIMESTAMPTZ,
              CONSTRAINT mm_is_tied CHECK (kind <> 'market_maker' OR tied_customer_id IS NOT NULL),
              CONSTRAINT promo_code_deactivated_pair CHECK ((deactivated_by IS NULL) = (deactivated_at IS NULL))
            );
            CREATE UNIQUE INDEX uq_promo_code_one_per_customer ON promo_code (tied_customer_id)
              WHERE kind = 'market_maker' AND tied_customer_id IS NOT NULL;

            CREATE TABLE market_maker_approval (
              approval_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              listing_id   UUID NOT NULL REFERENCES listing(listing_id),
              approved_by  UUID NOT NULL REFERENCES staff(staff_id),
              asking_price NUMERIC(18,4) NOT NULL,
              gold_value   NUMERIC(18,4),
              rapaport_guide NUMERIC(18,4),
              approved_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
              UNIQUE (listing_id)
            );

            CREATE TABLE promo_code_use (
              use_id       BIGSERIAL PRIMARY KEY,
              code         TEXT NOT NULL REFERENCES promo_code(code),
              order_id     UUID REFERENCES "order"(order_id),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              allowed      BOOLEAN NOT NULL,
              block_reason TEXT,
              used_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_promo_code_use_code ON promo_code_use (code, used_at DESC);

            CREATE OR REPLACE FUNCTION promo_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION '% rows are never changed or deleted', TG_TABLE_NAME USING ERRCODE = 'DH020';
            END $$;
            CREATE TRIGGER trg_promo_code_use_append_only BEFORE UPDATE OR DELETE ON promo_code_use
              FOR EACH ROW EXECUTE FUNCTION promo_append_only();
            CREATE TRIGGER trg_market_maker_approval_append_only BEFORE UPDATE OR DELETE ON market_maker_approval
              FOR EACH ROW EXECUTE FUNCTION promo_append_only();

            -- A code is never deleted, and only its cap and its on/off may change.
            CREATE OR REPLACE FUNCTION promo_code_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a promo code is never deleted' USING ERRCODE = 'DH020';
              END IF;
              IF NEW.code IS DISTINCT FROM OLD.code OR NEW.kind IS DISTINCT FROM OLD.kind
                 OR NEW.tied_customer_id IS DISTINCT FROM OLD.tied_customer_id
                 OR NEW.commission_waived IS DISTINCT FROM OLD.commission_waived
                 OR NEW.gives_spread IS DISTINCT FROM OLD.gives_spread
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'only the cap and the on/off of a promo code may change' USING ERRCODE = 'DH020';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_promo_code_guard BEFORE UPDATE OR DELETE ON promo_code
              FOR EACH ROW EXECUTE FUNCTION promo_code_guard();

            -- Staff only: a customer reads none of these rows.
            ALTER TABLE promo_code ENABLE ROW LEVEL SECURITY;
            ALTER TABLE promo_code FORCE  ROW LEVEL SECURITY;
            CREATE POLICY promo_code_staff ON promo_code FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            ALTER TABLE promo_code_use ENABLE ROW LEVEL SECURITY;
            ALTER TABLE promo_code_use FORCE  ROW LEVEL SECURITY;
            CREATE POLICY promo_code_use_staff ON promo_code_use FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            ALTER TABLE market_maker_approval ENABLE ROW LEVEL SECURITY;
            ALTER TABLE market_maker_approval FORCE  ROW LEVEL SECURITY;
            CREATE POLICY market_maker_approval_staff ON market_maker_approval FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM promo_code) OR EXISTS (SELECT 1 FROM promo_code_use)
                 OR EXISTS (SELECT 1 FROM market_maker_approval)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 019 market-maker records exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS promo_code_use;
            DROP TABLE IF EXISTS market_maker_approval;
            DROP TABLE IF EXISTS promo_code;
            DROP FUNCTION IF EXISTS promo_code_guard();
            DROP FUNCTION IF EXISTS promo_append_only();
            SQL);
    }
};
