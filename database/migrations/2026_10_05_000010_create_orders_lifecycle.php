<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The life of an order after acceptance (spec 012). Mirrors docs/Database
 * schema: 04_schema_market.sql §9–§11 (`order_branch_change`,
 * `order_deadline_extension`, `seller_cancellation`, `inspection_result`,
 * `settlement_decision`, `collection`, `seller_return`, the new
 * `order_state_change` and the `"order"` columns), 05_schema_security.sql (two
 * new order moves, the order guard on SQLSTATE DH006, the forced row-level
 * security of the new tables and the non-elevated `order` scope — research
 * R2), 03_schema_ledger.sql (one payment and one forfeit per order, who may
 * release a deposit) and 02_schema_identity.sql (`cancellations_reset_at`, the
 * `repeated_cancellations` reason).
 *
 * `locked_seller_unit_rate` is not backfilled: orders accepted before this
 * migration (local data only — spec 011 was never released) keep it NULL and
 * settle on the seller rate current at payment (research R6, analysis U1).
 *
 * ROLLBACK: down() refuses once any order has moved past acceptance or any of
 * the new tables (other than the order history) has rows: those rows are tied
 * to append-only ledger entries (refunds, payments, forfeits). This reversal is
 * intentionally impossible with data and needs a designated second reviewer,
 * recorded in the PR before merge (Constitution, "Migrations are reversible").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- Two moves for a corrected inspection result (05_schema_security.sql, spec 012 R9).
            INSERT INTO order_transition (from_state, to_state, note) VALUES
              ('awaiting_balance','weight_adjust_pending','corrected result needs the buyer''s approval (spec 012)'),
              ('awaiting_balance','cancelled_inspection','corrected result: karat mismatch / counterfeit (spec 012)');

            -- "order": the seller's locked rate, the regrade price, the settlement
            -- figures and the money of each ending (04 §9, spec 012 R3, R6).
            ALTER TABLE "order"
              ADD COLUMN locked_seller_unit_rate NUMERIC(18,4),
              ADD COLUMN decision_due_deadline   TIMESTAMPTZ,
              ADD COLUMN proposed_price          NUMERIC(18,4) CHECK (proposed_price IS NULL OR proposed_price > 0),
              ADD COLUMN proposed_by             UUID REFERENCES staff(staff_id),
              ADD COLUMN proposed_at             TIMESTAMPTZ,
              ADD COLUMN final_weight_g          NUMERIC(10,3),
              ADD COLUMN final_buyer_total       NUMERIC(18,4),
              ADD COLUMN final_seller_gross      NUMERIC(18,4),
              ADD COLUMN commission_amount       NUMERIC(18,4),
              ADD COLUMN vat_amount              NUMERIC(18,4),
              ADD COLUMN spread_amount           NUMERIC(18,4),  -- may be negative (locked rates crossed)
              ADD COLUMN seller_proceeds         NUMERIC(18,4),
              ADD COLUMN balance_amount          NUMERIC(18,4),
              ADD COLUMN settlement_txn_id       UUID REFERENCES ledger_transaction(ledger_txn_id),
              ADD COLUMN forfeit_txn_id          UUID REFERENCES ledger_transaction(ledger_txn_id),
              ADD COLUMN release_txn_id          UUID REFERENCES ledger_transaction(ledger_txn_id),
              ADD COLUMN reach_reminder_sent_at   TIMESTAMPTZ,
              ADD COLUMN balance_reminder_sent_at TIMESTAMPTZ,
              ADD CONSTRAINT order_proposal_shape CHECK (
                (proposed_price IS NULL) = (proposed_by IS NULL) AND (proposed_price IS NULL) = (proposed_at IS NULL)
              ),
              ADD CONSTRAINT order_settlement_shape CHECK (
                (final_buyer_total IS NULL) = (final_seller_gross IS NULL)
                AND (final_buyer_total IS NULL) = (commission_amount IS NULL)
                AND (final_buyer_total IS NULL) = (vat_amount IS NULL)
                AND (final_buyer_total IS NULL) = (spread_amount IS NULL)
                AND (final_buyer_total IS NULL) = (seller_proceeds IS NULL)
                AND (final_buyer_total IS NULL) = (balance_amount IS NULL)
                AND (final_buyer_total IS NULL) = (settlement_txn_id IS NULL)
                AND (final_buyer_total IS NULL OR state IN ('ready_to_collect','completed','disputed'))
              );

            CREATE INDEX idx_order_reach_due   ON "order"(reach_branch_deadline) WHERE state = 'awaiting_delivery';
            CREATE INDEX idx_order_balance_due ON "order"(balance_due_deadline)  WHERE state = 'awaiting_balance';
            CREATE INDEX idx_order_decision_due ON "order"(decision_due_deadline) WHERE state = 'weight_adjust_pending';
            CREATE INDEX idx_order_collect_due ON "order"(collect_deadline)      WHERE state = 'ready_to_collect';
            CREATE INDEX idx_order_accepted    ON "order"(accepted_at DESC, order_id);

            -- Order history (spec 012, Principle I): one permanent row per move, naming
            -- who moved it. from_state NULL is the creation. txid ties the row to the
            -- transaction of the move: trg_order_change_recorded refuses a commit that
            -- moved an order without one.
            CREATE TABLE order_state_change (
              change_id    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              from_state   order_state,
              to_state     order_state NOT NULL,
              actor_customer_id UUID REFERENCES customer(customer_id),
              actor_staff_id    UUID REFERENCES staff(staff_id),
              note         TEXT CHECK (char_length(note) <= 1000),
              changed_at   TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              txid         BIGINT NOT NULL DEFAULT txid_current(),
              CONSTRAINT order_change_one_actor CHECK ((actor_customer_id IS NULL) <> (actor_staff_id IS NULL))
            );
            CREATE INDEX idx_order_state_change_order ON order_state_change(order_id, changed_at);
            CREATE TRIGGER trg_order_state_change_immutable BEFORE UPDATE OR DELETE ON order_state_change
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            -- Admin-made branch changes on an open order (04 §9). The counter keeps
            -- running; an extension is a separate deadline row.
            CREATE TABLE order_branch_change (
              change_id    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              from_branch  SMALLINT NOT NULL REFERENCES branch(branch_id),
              to_branch    SMALLINT NOT NULL REFERENCES branch(branch_id),
              changed_by   UUID NOT NULL REFERENCES staff(staff_id),
              extended_to  TIMESTAMPTZ,
              reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),  -- spec 012: required
              changed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT branch_change_moves CHECK (from_branch <> to_branch)
            );
            CREATE INDEX idx_order_branch_change_order ON order_branch_change(order_id);

            -- Deadline extensions (admin-granted), audited (04 §9).
            CREATE TABLE order_deadline_extension (
              extension_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              which        TEXT NOT NULL CHECK (which IN ('reach_branch','balance','collect')),
              old_deadline TIMESTAMPTZ NOT NULL,
              new_deadline TIMESTAMPTZ NOT NULL,
              granted_by   UUID NOT NULL REFERENCES staff(staff_id),
              reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),  -- spec 012: required
              granted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT extension_moves_forward CHECK (new_deadline > old_deadline)
            );
            CREATE INDEX idx_order_deadline_extension_order ON order_deadline_extension(order_id);

            -- Seller cancellation record (counts toward the suspension threshold, 04 §9).
            -- spec 012: one per order; by_sweep = the reach-branch deadline passed.
            CREATE TABLE seller_cancellation (
              cancellation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
              seller_id    UUID NOT NULL REFERENCES customer(customer_id),
              by_sweep     BOOLEAN NOT NULL,
              cancelled_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()  -- the moment, not the transaction (compared with the reset)
            );
            CREATE INDEX idx_seller_cancellation_seller ON seller_cancellation(seller_id, cancelled_at);

            -- Inspections (IMMUTABLE results, 04 §10). spec 012: the inspector's two flags.
            CREATE TABLE inspection_result (
              inspection_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id       UUID NOT NULL REFERENCES "order"(order_id),
              branch_id      SMALLINT NOT NULL REFERENCES branch(branch_id),
              inspected_by   UUID NOT NULL REFERENCES staff(staff_id),
              stated_karat   SMALLINT,
              stated_weight_g NUMERIC(10,3),
              measured_karat SMALLINT,
              measured_weight_g NUMERIC(10,3) CHECK (measured_weight_g IS NULL OR measured_weight_g > 0),
              measured_stone_grade TEXT CHECK (char_length(measured_stone_grade) <= 100),
              certificate_number TEXT CHECK (char_length(certificate_number) <= 100),
              inspector_note TEXT CHECK (char_length(inspector_note) <= 2000),
              is_counterfeit BOOLEAN NOT NULL DEFAULT FALSE,
              stone_below_claim BOOLEAN NOT NULL DEFAULT FALSE,
              karat_mismatch BOOLEAN NOT NULL,
              weight_diff_pct NUMERIC(8,4),
              outcome        TEXT NOT NULL CHECK (outcome IN
                               ('pass','weight_adjust','karat_cancel','stone_regrade','fake_cancel')),
              supersedes_id  UUID REFERENCES inspection_result(inspection_id),
              created_at     TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              CONSTRAINT karat_rule CHECK (karat_mismatch = (stated_karat IS DISTINCT FROM measured_karat)),
              CONSTRAINT karat_mismatch_forces_cancel CHECK (NOT karat_mismatch OR outcome = 'karat_cancel')
            );
            CREATE INDEX idx_inspection_order ON inspection_result(order_id, created_at);
            CREATE UNIQUE INDEX one_correction_per_result ON inspection_result(supersedes_id) WHERE supersedes_id IS NOT NULL;
            CREATE TRIGGER inspection_no_update BEFORE UPDATE OR DELETE ON inspection_result
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            -- The buyer's decision on an adjusted price (04 §10). spec 012: one per result.
            CREATE TABLE settlement_decision (
              decision_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              inspection_id UUID NOT NULL UNIQUE REFERENCES inspection_result(inspection_id),
              buyer_accepted BOOLEAN NOT NULL,
              old_price    NUMERIC(18,4) NOT NULL,
              new_price    NUMERIC(18,4) NOT NULL,
              decided_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_settlement_decision_order ON settlement_decision(order_id);

            -- Collection (04 §11): a physical handover, no money. spec 012: the code is
            -- also kept encrypted so its owner can read it in the app (research R10),
            -- and wrong attempts lock the handover for a while.
            CREATE TABLE collection (
              collection_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
              code_hash    TEXT NOT NULL,
              code_encrypted TEXT NOT NULL,
              failed_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
              locked_until TIMESTAMPTZ,
              is_proxy     BOOLEAN NOT NULL DEFAULT FALSE,
              proxy_name   TEXT,
              proxy_phone  TEXT,
              proxy_id_storage_ref TEXT,
              collected_at TIMESTAMPTZ,
              handover_by  UUID REFERENCES staff(staff_id),
              CONSTRAINT proxy_needs_details CHECK (
                NOT is_proxy OR (proxy_name IS NOT NULL AND proxy_id_storage_ref IS NOT NULL)
              ),
              CONSTRAINT collection_handover_shape CHECK ((collected_at IS NULL) = (handover_by IS NULL))
            );

            -- The piece going back to its seller (04 §11): after a no-pay (with the
            -- forfeit compensation) or an inspection cancel / declined adjustment
            -- (no compensation). return_deadline is CALENDAR weeks (Part 3 §1.3).
            CREATE TABLE seller_return (
              seller_return_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
              listing_id   UUID NOT NULL REFERENCES listing(listing_id),
              seller_id    UUID NOT NULL REFERENCES customer(customer_id),
              branch_id    SMALLINT NOT NULL REFERENCES branch(branch_id),
              code_hash    TEXT NOT NULL,
              code_encrypted TEXT NOT NULL,
              failed_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
              locked_until TIMESTAMPTZ,
              return_deadline TIMESTAMPTZ NOT NULL,
              collected_at TIMESTAMPTZ,
              handover_by  UUID REFERENCES staff(staff_id),
              relisted_at  TIMESTAMPTZ,
              compensation_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT seller_return_one_end CHECK (NOT (collected_at IS NOT NULL AND relisted_at IS NOT NULL)),
              CONSTRAINT seller_return_handover_shape CHECK ((collected_at IS NULL) = (handover_by IS NULL))
            );
            CREATE INDEX idx_seller_return_deadline ON seller_return(return_deadline)
              WHERE collected_at IS NULL AND relisted_at IS NULL;

            -- 02_schema_identity.sql (spec 012): the cancellation count restarts at reinstatement.
            ALTER TABLE customer ADD COLUMN cancellations_reset_at TIMESTAMPTZ;
            ALTER TABLE customer DROP CONSTRAINT customer_suspended_reason_check;
            ALTER TABLE customer ADD CONSTRAINT customer_suspended_reason_check CHECK (
              suspended_reason IS NULL OR suspended_reason IN (
                'piece_misrepresented','off_platform_dealing','repeated_disputes',
                'reported_by_users','identity_unconfirmed','customer_request','other',
                'repeated_cancellations')
            );

            -- 03_schema_ledger.sql (spec 012): one payment and one forfeit per order.
            CREATE UNIQUE INDEX one_balance_payment_per_order ON ledger_transaction(order_id)
              WHERE event_kind = 'balance_payment' AND order_id IS NOT NULL;
            CREATE UNIQUE INDEX one_deposit_forfeit_per_order ON ledger_transaction(order_id)
              WHERE event_kind = 'deposit_forfeit' AND order_id IS NOT NULL;
            SQL);

        DB::unprepared(<<<'SQL'
            -- "order" guard (spec 012): moves only along order_transition, identity and
            -- locked figures frozen, settlement figures and money links frozen once set,
            -- never deleted. Its own SQLSTATE DH006 -> 409 illegal_order_transition.
            CREATE OR REPLACE FUNCTION assert_order_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'orders are never deleted' USING ERRCODE = 'DH006';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM order_transition WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'illegal order transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH006';
                END IF;
              END IF;
              IF NEW.order_ref IS DISTINCT FROM OLD.order_ref OR NEW.buy_request_id IS DISTINCT FROM OLD.buy_request_id
                 OR NEW.listing_id IS DISTINCT FROM OLD.listing_id OR NEW.buyer_id IS DISTINCT FROM OLD.buyer_id
                 OR NEW.seller_id IS DISTINCT FROM OLD.seller_id OR NEW.locked_total_price IS DISTINCT FROM OLD.locked_total_price
                 OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at
                 OR (OLD.locked_seller_unit_rate IS NOT NULL AND NEW.locked_seller_unit_rate IS DISTINCT FROM OLD.locked_seller_unit_rate) THEN
                RAISE EXCEPTION 'order % identity columns cannot change', OLD.order_id USING ERRCODE = 'DH006';
              END IF;
              IF OLD.final_buyer_total IS NOT NULL AND (
                   NEW.final_weight_g IS DISTINCT FROM OLD.final_weight_g
                   OR NEW.final_buyer_total IS DISTINCT FROM OLD.final_buyer_total
                   OR NEW.final_seller_gross IS DISTINCT FROM OLD.final_seller_gross
                   OR NEW.commission_amount IS DISTINCT FROM OLD.commission_amount
                   OR NEW.vat_amount IS DISTINCT FROM OLD.vat_amount
                   OR NEW.spread_amount IS DISTINCT FROM OLD.spread_amount
                   OR NEW.seller_proceeds IS DISTINCT FROM OLD.seller_proceeds
                   OR NEW.balance_amount IS DISTINCT FROM OLD.balance_amount) THEN
                RAISE EXCEPTION 'order % settlement figures cannot change', OLD.order_id USING ERRCODE = 'DH006';
              END IF;
              IF (OLD.settlement_txn_id IS NOT NULL AND NEW.settlement_txn_id IS DISTINCT FROM OLD.settlement_txn_id)
                 OR (OLD.forfeit_txn_id IS NOT NULL AND NEW.forfeit_txn_id IS DISTINCT FROM OLD.forfeit_txn_id)
                 OR (OLD.release_txn_id IS NOT NULL AND NEW.release_txn_id IS DISTINCT FROM OLD.release_txn_id) THEN
                RAISE EXCEPTION 'order % money links cannot change', OLD.order_id USING ERRCODE = 'DH006';
              END IF;
              RETURN NEW;
            END $$;

            -- Every order move has its history row in the same transaction (Principle I).
            CREATE OR REPLACE FUNCTION order_change_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF NEW.state IS NOT DISTINCT FROM OLD.state THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
              SELECT EXISTS (SELECT 1 FROM order_state_change c
                             WHERE c.order_id = NEW.order_id AND c.to_state = NEW.state
                               AND c.txid = txid_current()) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'order % moved to % without a history row', NEW.order_id, NEW.state
                  USING ERRCODE = 'DH006';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER trg_order_change_recorded
              AFTER UPDATE OF state ON "order"
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION order_change_recorded();

            -- Each ending has exactly its money (spec 012 R3, replaces spec 011's
            -- trg_order_cancel_refunded): a cancellation refunds the buyer, a no-pay
            -- forfeits the deposit, a payment settles.
            DROP TRIGGER IF EXISTS trg_order_cancel_refunded ON "order";
            DROP FUNCTION IF EXISTS order_cancel_refunded();

            CREATE OR REPLACE FUNCTION order_money_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_kind ledger_event_kind;
              v_ok BOOLEAN;
            BEGIN
              v_kind := CASE
                WHEN NEW.state IN ('cancelled_staff','cancelled_seller','cancelled_inspection') THEN 'deposit_release'
                WHEN NEW.state = 'cancelled_buyer_nopay' THEN 'deposit_forfeit'
                WHEN NEW.state = 'ready_to_collect' THEN 'balance_payment'
                ELSE NULL END;
              IF v_kind IS NULL THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (SELECT 1 FROM ledger_transaction t
                              WHERE t.event_kind = v_kind AND t.order_id = NEW.order_id
                                AND (v_kind <> 'deposit_release' OR t.buy_request_id = NEW.buy_request_id)) INTO v_ok;
              IF v_ok AND NEW.state = 'cancelled_buyer_nopay' THEN
                SELECT NOT EXISTS (SELECT 1 FROM ledger_transaction t
                                    WHERE t.event_kind = 'deposit_release' AND t.buy_request_id = NEW.buy_request_id) INTO v_ok;
              END IF;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'order % is % without its % entry', NEW.order_id, NEW.state, v_kind USING ERRCODE = 'DH006';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER trg_order_money
              AFTER UPDATE OF state ON "order"
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION order_money_recorded();

            -- A released deposit belongs to a request that has ended, or to an accepted
            -- one whose order was cancelled (spec 011 R22, extended by spec 012).
            CREATE OR REPLACE FUNCTION deposit_release_allowed() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_state buy_request_state;
              v_order order_state;
            BEGIN
              IF NEW.event_kind <> 'deposit_release' OR NEW.buy_request_id IS NULL THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
              SELECT r.state, o.state INTO v_state, v_order
                FROM buy_request r LEFT JOIN "order" o ON o.buy_request_id = r.buy_request_id
               WHERE r.buy_request_id = NEW.buy_request_id;
              PERFORM set_config('app.rls_scope', prev_scope, true);

              IF v_state IS NULL OR v_state = 'queued'
                 OR (v_state = 'accepted' AND v_order IS DISTINCT FROM 'cancelled_staff'
                     AND v_order IS DISTINCT FROM 'cancelled_seller' AND v_order IS DISTINCT FROM 'cancelled_inspection') THEN
                RAISE EXCEPTION 'deposit of buy request % released while it is still %', NEW.buy_request_id, v_state
                  USING ERRCODE = 'DH005';
              END IF;
              RETURN NULL;
            END $$;

            -- The column side of the non-seller listing moves (spec 011 R2, extended to
            -- the 'order' scope by spec 012 R2): a customer who is not the seller changes
            -- nothing on a listing but its state (and its queue count).
            CREATE OR REPLACE FUNCTION listing_queue_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF dahab_rls_scope() IN ('queue','order') AND OLD.seller_id IS DISTINCT FROM dahab_current_customer_id()
                 AND (to_jsonb(NEW) - 'state' - 'active_queue_count' - 'state_changed_at' - 'listed_at')
                     IS DISTINCT FROM (to_jsonb(OLD) - 'state' - 'active_queue_count' - 'state_changed_at' - 'listed_at') THEN
                RAISE EXCEPTION 'listing %: only its state and queue count change in the % scope', OLD.listing_id, dahab_rls_scope()
                  USING ERRCODE = 'DH004';
              END IF;
              RETURN NEW;
            END $$;
            SQL);

        DB::unprepared(<<<'SQL'
            -- Row-level security (spec 012 research R2; 05_schema_security.sql). The new
            -- tables follow their order: a party (the order's seller or buyer, filtered
            -- by order_isolation) or an elevated scope. Customers write them only in the
            -- non-elevated 'order' scope, which the customer order Actions push and audit.
            ALTER TABLE order_state_change       ENABLE ROW LEVEL SECURITY;
            ALTER TABLE order_state_change       FORCE  ROW LEVEL SECURITY;
            ALTER TABLE order_branch_change      ENABLE ROW LEVEL SECURITY;
            ALTER TABLE order_branch_change      FORCE  ROW LEVEL SECURITY;
            ALTER TABLE order_deadline_extension ENABLE ROW LEVEL SECURITY;
            ALTER TABLE order_deadline_extension FORCE  ROW LEVEL SECURITY;
            ALTER TABLE seller_cancellation      ENABLE ROW LEVEL SECURITY;
            ALTER TABLE seller_cancellation      FORCE  ROW LEVEL SECURITY;
            ALTER TABLE inspection_result        ENABLE ROW LEVEL SECURITY;
            ALTER TABLE inspection_result        FORCE  ROW LEVEL SECURITY;
            ALTER TABLE settlement_decision      ENABLE ROW LEVEL SECURITY;
            ALTER TABLE settlement_decision      FORCE  ROW LEVEL SECURITY;
            ALTER TABLE collection               ENABLE ROW LEVEL SECURITY;
            ALTER TABLE collection               FORCE  ROW LEVEL SECURITY;
            ALTER TABLE seller_return            ENABLE ROW LEVEL SECURITY;
            ALTER TABLE seller_return            FORCE  ROW LEVEL SECURITY;

            CREATE POLICY order_state_change_isolation ON order_state_change FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = order_state_change.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) IN ('order','queue')
                     AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = order_state_change.order_id)));

            -- Staff-written tables: parties read their own order's rows.
            CREATE POLICY order_branch_change_isolation ON order_branch_change FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = order_branch_change.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY order_deadline_extension_isolation ON order_deadline_extension FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = order_deadline_extension.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY inspection_result_isolation ON inspection_result FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = inspection_result.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()));

            -- Customer-written tables, only in the 'order' scope and only for their own order.
            CREATE POLICY seller_cancellation_isolation ON seller_cancellation FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = seller_cancellation.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order' AND seller_id = (SELECT dahab_current_customer_id())
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = seller_cancellation.order_id)));
            CREATE POLICY settlement_decision_isolation ON settlement_decision FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = settlement_decision.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = settlement_decision.order_id
                                   AND o.buyer_id = (SELECT dahab_current_customer_id()))));
            CREATE POLICY collection_isolation ON collection FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = collection.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = collection.order_id
                                   AND o.buyer_id = (SELECT dahab_current_customer_id()))));
            CREATE POLICY seller_return_isolation ON seller_return FOR ALL
              USING ((SELECT dahab_rls_elevated())
                     OR EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = seller_return.order_id))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = seller_return.order_id)));

            -- The 'order' scope on existing tables (spec 012 R2). Every inner SELECT on
            -- "order" is filtered by order_isolation: the caller's own orders only.
            CREATE POLICY buy_request_order_read ON buy_request FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.buy_request_id = buy_request.buy_request_id));
            CREATE POLICY listing_order_read ON listing FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing.listing_id));
            -- The buyer's own moves of the listing they bought (pay -> sold; decline ->
            -- awaiting_seller_return). The seller's moves pass listing_isolation.
            CREATE POLICY listing_order_move ON listing FOR UPDATE
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing.listing_id
                                   AND o.buyer_id = (SELECT dahab_current_customer_id())))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'order' AND state IN ('sold','awaiting_seller_return')
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing.listing_id
                                   AND o.buyer_id = (SELECT dahab_current_customer_id())));
            CREATE POLICY listing_state_change_order_insert ON listing_state_change FOR INSERT
              WITH CHECK ((SELECT dahab_rls_scope()) = 'order'
                          AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
                          AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing_state_change.listing_id));
            -- INSERT ... RETURNING needs the new row to be visible too.
            CREATE POLICY listing_state_change_order_read ON listing_state_change FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing_state_change.listing_id));
            CREATE POLICY listing_media_order_read ON listing_media FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'order' AND NOT is_private
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing_media.listing_id));
            CREATE POLICY listing_branch_option_order_read ON listing_branch_option FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.listing_id = listing_branch_option.listing_id));
            -- The other party's display_ref (column privacy is the Resources' job).
            CREATE POLICY customer_order_read ON customer FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o
                                  WHERE o.seller_id = customer.customer_id OR o.buyer_id = customer.customer_id));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $moved = DB::selectOne(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM "order" WHERE state NOT IN ('awaiting_delivery','cancelled_staff'))
                OR EXISTS (SELECT 1 FROM order_branch_change) OR EXISTS (SELECT 1 FROM order_deadline_extension)
                OR EXISTS (SELECT 1 FROM seller_cancellation) OR EXISTS (SELECT 1 FROM inspection_result)
                OR EXISTS (SELECT 1 FROM settlement_decision) OR EXISTS (SELECT 1 FROM collection)
                OR EXISTS (SELECT 1 FROM seller_return) AS any
            SQL);

        if ($moved->any) {
            throw new RuntimeException(
                'Refusing to roll back spec 012: orders have moved past acceptance and are tied to append-only ledger entries. '
                .'See the migration docblock; this reversal needs a designated second reviewer.'
            );
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS customer_order_read ON customer;
            DROP POLICY IF EXISTS listing_branch_option_order_read ON listing_branch_option;
            DROP POLICY IF EXISTS listing_media_order_read ON listing_media;
            DROP POLICY IF EXISTS listing_state_change_order_read ON listing_state_change;
            DROP POLICY IF EXISTS listing_state_change_order_insert ON listing_state_change;
            DROP POLICY IF EXISTS listing_order_move ON listing;
            DROP POLICY IF EXISTS listing_order_read ON listing;
            DROP POLICY IF EXISTS buy_request_order_read ON buy_request;

            DROP TABLE IF EXISTS seller_return;
            DROP TABLE IF EXISTS collection;
            DROP TABLE IF EXISTS settlement_decision;
            DROP TABLE IF EXISTS inspection_result;
            DROP TABLE IF EXISTS seller_cancellation;
            DROP TABLE IF EXISTS order_deadline_extension;
            DROP TABLE IF EXISTS order_branch_change;
            DROP TABLE IF EXISTS order_state_change;

            DROP INDEX IF EXISTS one_deposit_forfeit_per_order;
            DROP INDEX IF EXISTS one_balance_payment_per_order;

            ALTER TABLE customer DROP CONSTRAINT customer_suspended_reason_check;
            ALTER TABLE customer ADD CONSTRAINT customer_suspended_reason_check CHECK (
              suspended_reason IS NULL OR suspended_reason IN (
                'piece_misrepresented','off_platform_dealing','repeated_disputes',
                'reported_by_users','identity_unconfirmed','customer_request','other')
            );
            ALTER TABLE customer DROP COLUMN IF EXISTS cancellations_reset_at;

            DROP TRIGGER IF EXISTS trg_order_money ON "order";
            DROP FUNCTION IF EXISTS order_money_recorded();
            DROP TRIGGER IF EXISTS trg_order_change_recorded ON "order";
            DROP FUNCTION IF EXISTS order_change_recorded();

            DROP INDEX IF EXISTS idx_order_accepted;
            DROP INDEX IF EXISTS idx_order_collect_due;
            DROP INDEX IF EXISTS idx_order_decision_due;
            DROP INDEX IF EXISTS idx_order_balance_due;
            DROP INDEX IF EXISTS idx_order_reach_due;
            ALTER TABLE "order"
              DROP CONSTRAINT IF EXISTS order_settlement_shape,
              DROP CONSTRAINT IF EXISTS order_proposal_shape,
              DROP COLUMN locked_seller_unit_rate, DROP COLUMN decision_due_deadline,
              DROP COLUMN proposed_price, DROP COLUMN proposed_by, DROP COLUMN proposed_at,
              DROP COLUMN final_weight_g, DROP COLUMN final_buyer_total, DROP COLUMN final_seller_gross,
              DROP COLUMN commission_amount, DROP COLUMN vat_amount, DROP COLUMN spread_amount,
              DROP COLUMN seller_proceeds, DROP COLUMN balance_amount,
              DROP COLUMN settlement_txn_id, DROP COLUMN forfeit_txn_id, DROP COLUMN release_txn_id,
              DROP COLUMN reach_reminder_sent_at, DROP COLUMN balance_reminder_sent_at;

            DELETE FROM order_transition WHERE from_state = 'awaiting_balance'
              AND to_state IN ('weight_adjust_pending','cancelled_inspection');

            -- spec 011's bodies.
            CREATE OR REPLACE FUNCTION listing_queue_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF dahab_rls_scope() = 'queue' AND OLD.seller_id IS DISTINCT FROM dahab_current_customer_id()
                 AND (to_jsonb(NEW) - 'state' - 'active_queue_count' - 'state_changed_at' - 'listed_at')
                     IS DISTINCT FROM (to_jsonb(OLD) - 'state' - 'active_queue_count' - 'state_changed_at' - 'listed_at') THEN
                RAISE EXCEPTION 'listing %: only its state and queue count change in the queue scope', OLD.listing_id
                  USING ERRCODE = 'DH004';
              END IF;
              RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION deposit_release_allowed() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_state buy_request_state;
              v_order order_state;
            BEGIN
              IF NEW.event_kind <> 'deposit_release' OR NEW.buy_request_id IS NULL THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
              SELECT r.state, o.state INTO v_state, v_order
                FROM buy_request r LEFT JOIN "order" o ON o.buy_request_id = r.buy_request_id
               WHERE r.buy_request_id = NEW.buy_request_id;
              PERFORM set_config('app.rls_scope', prev_scope, true);

              IF v_state IS NULL OR v_state = 'queued' OR (v_state = 'accepted' AND v_order IS DISTINCT FROM 'cancelled_staff') THEN
                RAISE EXCEPTION 'deposit of buy request % released while it is still %', NEW.buy_request_id, v_state
                  USING ERRCODE = 'DH005';
              END IF;
              RETURN NULL;
            END $$;

            CREATE OR REPLACE FUNCTION order_cancel_refunded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF NEW.state <> 'cancelled_staff' THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (SELECT 1 FROM ledger_transaction t
                              WHERE t.event_kind = 'deposit_release' AND t.buy_request_id = NEW.buy_request_id
                                AND t.order_id = NEW.order_id) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'order % cancelled without refunding the buyer', NEW.order_id USING ERRCODE = 'DH005';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER trg_order_cancel_refunded
              AFTER UPDATE OF state ON "order"
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION order_cancel_refunded();

            CREATE OR REPLACE FUNCTION assert_order_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'orders are never deleted' USING ERRCODE = 'DH005';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM order_transition WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'illegal order transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH005';
                END IF;
              END IF;
              IF NEW.order_ref IS DISTINCT FROM OLD.order_ref OR NEW.buy_request_id IS DISTINCT FROM OLD.buy_request_id
                 OR NEW.listing_id IS DISTINCT FROM OLD.listing_id OR NEW.buyer_id IS DISTINCT FROM OLD.buyer_id
                 OR NEW.seller_id IS DISTINCT FROM OLD.seller_id OR NEW.locked_total_price IS DISTINCT FROM OLD.locked_total_price THEN
                RAISE EXCEPTION 'order % identity columns cannot change', OLD.order_id USING ERRCODE = 'DH005';
              END IF;
              RETURN NEW;
            END $$;
            SQL);
    }
};
