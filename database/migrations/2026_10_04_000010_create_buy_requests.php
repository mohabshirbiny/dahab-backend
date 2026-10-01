<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buy requests and the order they become (spec 011). Mirrors
 * docs/Database schema: `order_state` (01_schema_core.sql, with
 * `cancelled_staff`), sections 8–9 of 04_schema_market.sql (`buy_request`,
 * `sync_listing_queue` counting only, `"order"` with `order_ref_seq`) and from
 * 05_schema_security.sql the allowed moves (`buy_request_transition`,
 * `order_transition`, two listing moves out of `accepted`), the guard and
 * money triggers (SQLSTATE DH005) and the forced row-level security with the
 * non-elevated `queue` scope (research R2).
 *
 * Deferred checks read under a scope they set themselves (spec 011 analysis
 * H1): a deferred trigger fires at commit under whatever scope is current
 * then, and one customer's action (a buyer joining) moves another customer's
 * listing. `listing_change_recorded()` from spec 010 gets the same treatment.
 *
 * ROLLBACK: down() refuses while any buy_request exists. Every request is tied
 * to append-only ledger entries (its deposit hold and release); dropping the
 * requests would orphan money held in customers' `cust_held` accounts. This
 * reversal is intentionally impossible with data and needs a designated
 * second reviewer from the backend/database review group, recorded in the PR
 * before merge (Constitution, "Migrations are reversible").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // migrate:fresh drops tables but not types (same guard as the earlier migrations).
        DB::unprepared('DROP TYPE IF EXISTS order_state CASCADE');
        DB::unprepared('DROP TYPE IF EXISTS buy_request_state CASCADE');
        // Nor standalone sequences.
        DB::unprepared('DROP SEQUENCE IF EXISTS order_ref_seq');

        DB::unprepared(<<<'SQL'
            -- A single buyer's position on a piece (01_schema_core.sql).
            CREATE TYPE buy_request_state AS ENUM (
              'queued',            -- in line, deposit held, price locked
              'accepted',          -- chosen by the seller -> becomes the order's buyer
              'released_not_chosen',-- seller took someone else; deposit refunded
              'released_declined', -- seller declined / piece withdrawn / seller suspended; refunded
              'released_expired',  -- seller reply deadline passed; deposit refunded
              'withdrawn_by_buyer' -- buyer left the queue; deposit refunded
            );

            -- Order lifecycle (01_schema_core.sql). spec 011 reaches awaiting_delivery
            -- and cancelled_staff only.
            CREATE TYPE order_state AS ENUM (
              'awaiting_delivery',
              'at_inspection',
              'inspection_passed',
              'weight_adjust_pending',
              'awaiting_balance',
              'ready_to_collect',
              'completed',
              'cancelled_seller',
              'cancelled_buyer_nopay',
              'cancelled_inspection',
              'disputed',
              'cancelled_staff'    -- spec 011: staff cancelled the acceptance; final
            );

            CREATE TABLE buy_request_transition (
              from_state   buy_request_state NOT NULL,
              to_state     buy_request_state NOT NULL,
              note         TEXT,
              PRIMARY KEY (from_state, to_state)
            );
            INSERT INTO buy_request_transition (from_state, to_state, note) VALUES
              ('queued','accepted','seller took the first in line'),
              ('queued','released_not_chosen','seller accepted someone ahead; refund'),
              ('queued','released_declined','seller declined this request; refund'),
              ('queued','released_expired','seller reply deadline passed; refund'),
              ('queued','withdrawn_by_buyer','buyer left the queue; refund');

            CREATE TABLE order_transition (
              from_state   order_state NOT NULL,
              to_state     order_state NOT NULL,
              note         TEXT,
              PRIMARY KEY (from_state, to_state)
            );
            INSERT INTO order_transition (from_state, to_state, note) VALUES
              ('awaiting_delivery','at_inspection','seller reached the branch'),
              ('awaiting_delivery','cancelled_seller','seller cancelled after accepting, or reach-branch deadline missed'),
              ('awaiting_delivery','cancelled_staff','staff cancelled the acceptance; deposit refunded (spec 011)'),
              ('at_inspection','inspection_passed','karat+weight ok within tolerance'),
              ('at_inspection','weight_adjust_pending','weight diff above tolerance'),
              ('at_inspection','cancelled_inspection','karat mismatch / fake'),
              ('weight_adjust_pending','awaiting_balance','buyer accepted new price'),
              ('weight_adjust_pending','cancelled_inspection','buyer declined new price'),
              ('inspection_passed','awaiting_balance','proceed to balance (or first-sale payout)'),
              ('awaiting_balance','ready_to_collect','buyer paid the balance'),
              ('awaiting_balance','cancelled_buyer_nopay','pay deadline missed'),
              ('ready_to_collect','completed','collected; commission + spread taken'),
              ('at_inspection','disputed','dispute opened'),
              ('awaiting_balance','disputed','dispute opened'),
              ('ready_to_collect','disputed','dispute opened'),
              ('disputed','awaiting_balance','dispute resolved, resume'),
              ('disputed','cancelled_inspection','dispute resolved against sale'),
              ('disputed','ready_to_collect','dispute resolved, resume');

            -- spec 011: the staff cancellation (research R22) puts the piece back on
            -- the market or withdraws it; both carry the staff reason.
            INSERT INTO listing_transition (from_state, to_state, note) VALUES
              ('accepted','live','staff cancelled the acceptance; back on the market (spec 011)'),
              ('accepted','withdrawn','staff cancelled the acceptance and withdrew the piece (spec 011)');

            ALTER TABLE listing_state_change DROP CONSTRAINT listing_change_note_required;
            ALTER TABLE listing_state_change ADD CONSTRAINT listing_change_note_required CHECK (
              note IS NOT NULL OR NOT (
                to_state IN ('changes_requested','rejected')
                OR (to_state = 'withdrawn' AND actor_staff_id IS NOT NULL)
                OR from_state = 'accepted'
              )
            );

            -- 8. Buy requests = the QUEUE (04_schema_market.sql §8).
            CREATE TABLE buy_request (
              buy_request_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              listing_id     UUID NOT NULL REFERENCES listing(listing_id),
              buyer_id       UUID NOT NULL REFERENCES customer(customer_id),
              state          buy_request_state NOT NULL DEFAULT 'queued',
              queue_position INTEGER NOT NULL,
              -- The karat's sell-side rate per gram at join; NULL for pure diamond
              -- (spec 011 research R5; set for every listing with a karat).
              locked_unit_rate NUMERIC(18,4),
              locked_total_price NUMERIC(18,4) NOT NULL,
              deposit_amount NUMERIC(18,4) NOT NULL,
              deposit_hold_txn_id UUID NOT NULL REFERENCES ledger_transaction(ledger_txn_id),
              -- spec 011: the deposit terms accepted with this request.
              deposit_acceptance_id UUID NOT NULL UNIQUE REFERENCES agreement_acceptance(acceptance_id),
              requested_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              seller_reply_deadline TIMESTAMPTZ NOT NULL,
              resolved_at    TIMESTAMPTZ,
              notify_when_free BOOLEAN NOT NULL DEFAULT FALSE,
              -- spec 011: when the "free again" message went out (once per leave).
              free_notified_at TIMESTAMPTZ,
              CONSTRAINT deposit_positive CHECK (deposit_amount > 0),
              CONSTRAINT buy_request_price_positive CHECK (locked_total_price > 0),
              CONSTRAINT buy_request_resolved_shape CHECK ((state = 'queued') = (resolved_at IS NULL)),
              UNIQUE (listing_id, queue_position)
            );

            CREATE UNIQUE INDEX one_active_request_per_buyer_listing
              ON buy_request(listing_id, buyer_id)
              WHERE state IN ('queued','accepted');
            CREATE INDEX idx_buy_request_listing_state ON buy_request(listing_id, state);
            CREATE INDEX idx_buy_request_buyer ON buy_request(buyer_id, requested_at DESC);
            CREATE INDEX idx_buy_request_due ON buy_request(seller_reply_deadline) WHERE state = 'queued';

            -- 9. Orders (04_schema_market.sql §9; spec 011 creates only what acceptance needs).
            CREATE SEQUENCE order_ref_seq;

            CREATE TABLE "order" (
              order_id       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_ref      TEXT UNIQUE NOT NULL,
              listing_id     UUID NOT NULL REFERENCES listing(listing_id),
              buy_request_id UUID NOT NULL UNIQUE REFERENCES buy_request(buy_request_id),
              seller_id      UUID NOT NULL REFERENCES customer(customer_id),
              buyer_id       UUID NOT NULL REFERENCES customer(customer_id),
              state          order_state NOT NULL DEFAULT 'awaiting_delivery',
              branch_id      SMALLINT NOT NULL REFERENCES branch(branch_id),
              accepted_by    UUID NOT NULL REFERENCES customer(customer_id),
              accepted_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
              reach_branch_deadline TIMESTAMPTZ NOT NULL,
              locked_total_price NUMERIC(18,4) NOT NULL,
              balance_due_deadline  TIMESTAMPTZ,
              collect_deadline      TIMESTAMPTZ,
              completed_at   TIMESTAMPTZ,
              created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
              -- spec 011: the staff cancellation (research R22).
              cancelled_by   UUID REFERENCES staff(staff_id),
              cancelled_at   TIMESTAMPTZ,
              cancel_reason  TEXT CHECK (cancel_reason IS NULL OR char_length(cancel_reason) BETWEEN 10 AND 1000),
              CONSTRAINT order_cancel_shape CHECK (
                (state = 'cancelled_staff') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)
              )
            );
            CREATE INDEX idx_order_state ON "order"(state);
            CREATE INDEX idx_order_seller ON "order"(seller_id);
            CREATE INDEX idx_order_buyer ON "order"(buyer_id);
            CREATE INDEX idx_order_listing ON "order"(listing_id);

            -- The ledger links (03_schema_ledger.sql "FK added in Part 3"). The request
            -- FK is deferred: a join posts the hold naming the pre-generated request id,
            -- then inserts the request with deposit_hold_txn_id.
            ALTER TABLE ledger_transaction
              ADD CONSTRAINT lt_request_fk FOREIGN KEY (buy_request_id) REFERENCES buy_request(buy_request_id)
                DEFERRABLE INITIALLY DEFERRED,
              ADD CONSTRAINT lt_order_fk FOREIGN KEY (order_id) REFERENCES "order"(order_id);

            -- Exactly one hold and at most one release per request, at the engine.
            CREATE UNIQUE INDEX one_deposit_hold_per_request ON ledger_transaction(buy_request_id)
              WHERE event_kind = 'deposit_hold' AND buy_request_id IS NOT NULL;
            CREATE UNIQUE INDEX one_deposit_release_per_request ON ledger_transaction(buy_request_id)
              WHERE event_kind = 'deposit_release' AND buy_request_id IS NOT NULL;
            SQL);

        DB::unprepared(<<<'SQL'
            -- The scope a trigger reads under (spec 011 analysis H1): an elevated
            -- caller (staff, system, maintenance, bootstrap) keeps its own view;
            -- anyone else reads as the non-elevated 'queue' scope. Never an elevation.
            CREATE OR REPLACE FUNCTION dahab_queue_read_scope(prev text) RETURNS text AS $$
              SELECT CASE WHEN prev IN ('staff','system','bootstrap','maintenance') THEN prev ELSE 'queue' END;
            $$ LANGUAGE sql IMMUTABLE;

            -- Request guard (spec 011 research R10): born queued, moves only along
            -- buy_request_transition, identity and locked figures frozen, never
            -- deleted; stamps resolved_at when it leaves the queue. SQLSTATE DH005.
            CREATE OR REPLACE FUNCTION buy_request_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'buy requests are never deleted' USING ERRCODE = 'DH005';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.state <> 'queued' THEN
                  RAISE EXCEPTION 'a buy request starts queued, not %', NEW.state USING ERRCODE = 'DH005';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.listing_id IS DISTINCT FROM OLD.listing_id
                 OR NEW.buyer_id IS DISTINCT FROM OLD.buyer_id
                 OR NEW.queue_position IS DISTINCT FROM OLD.queue_position
                 OR NEW.locked_unit_rate IS DISTINCT FROM OLD.locked_unit_rate
                 OR NEW.locked_total_price IS DISTINCT FROM OLD.locked_total_price
                 OR NEW.deposit_amount IS DISTINCT FROM OLD.deposit_amount
                 OR NEW.deposit_hold_txn_id IS DISTINCT FROM OLD.deposit_hold_txn_id
                 OR NEW.deposit_acceptance_id IS DISTINCT FROM OLD.deposit_acceptance_id
                 OR NEW.requested_at IS DISTINCT FROM OLD.requested_at
                 OR NEW.seller_reply_deadline IS DISTINCT FROM OLD.seller_reply_deadline THEN
                RAISE EXCEPTION 'buy request % locked columns cannot change', OLD.buy_request_id USING ERRCODE = 'DH005';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM buy_request_transition
                               WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'illegal buy request transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH005';
                END IF;
                IF OLD.state = 'queued' THEN
                  NEW.resolved_at := clock_timestamp();
                END IF;
              ELSIF NEW.resolved_at IS DISTINCT FROM OLD.resolved_at THEN
                RAISE EXCEPTION 'buy request % resolved_at is set by the guard', OLD.buy_request_id USING ERRCODE = 'DH005';
              END IF;
              -- In the queue scope a seller moves other buyers' requests: the state only,
              -- never the buyer's own choices (research R2, the column side of the policy).
              IF dahab_rls_scope() = 'queue' AND OLD.buyer_id IS DISTINCT FROM dahab_current_customer_id()
                 AND (NEW.notify_when_free IS DISTINCT FROM OLD.notify_when_free
                      OR NEW.free_notified_at IS DISTINCT FROM OLD.free_notified_at) THEN
                RAISE EXCEPTION 'only the buyer changes their own request %', OLD.buy_request_id USING ERRCODE = 'DH005';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_buy_request_guard
              BEFORE INSERT OR UPDATE OR DELETE ON buy_request
              FOR EACH ROW EXECUTE FUNCTION buy_request_guard();

            -- The column side of listing_queue_move (research R2): in the queue scope a
            -- customer who is not the seller changes nothing on a listing but its state
            -- (live <-> reserved, checked by the policy) and its queue count.
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

            CREATE TRIGGER trg_listing_queue_guard
              BEFORE UPDATE ON listing
              FOR EACH ROW EXECUTE FUNCTION listing_queue_guard();

            -- Queue depth (04_schema_market.sql §8, changed by spec 011 research R3):
            -- keeps listing.active_queue_count only. The live <-> reserved moves go
            -- through the application's listing mover, with a history row and actor.
            -- The count reads under the 'queue' scope (it must see every buyer's
            -- request); the listing update runs under the caller's own scope and must
            -- touch the row.
            CREATE OR REPLACE FUNCTION sync_listing_queue() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_listing UUID := COALESCE(NEW.listing_id, OLD.listing_id);
              v_count INTEGER;
              v_rows INTEGER;
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
            BEGIN
              PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
              SELECT count(*) INTO v_count FROM buy_request
               WHERE listing_id = v_listing AND state = 'queued';
              PERFORM set_config('app.rls_scope', prev_scope, true);

              UPDATE listing SET active_queue_count = v_count
               WHERE listing_id = v_listing AND active_queue_count IS DISTINCT FROM v_count;
              GET DIAGNOSTICS v_rows = ROW_COUNT;
              IF v_rows = 0 AND NOT EXISTS (SELECT 1 FROM listing WHERE listing_id = v_listing AND active_queue_count = v_count) THEN
                RAISE EXCEPTION 'queue count of listing % could not be updated in scope %', v_listing, prev_scope
                  USING ERRCODE = 'DH005';
              END IF;
              RETURN NULL;
            END $$;

            CREATE TRIGGER trg_sync_queue
              AFTER INSERT OR UPDATE OF state ON buy_request
              FOR EACH ROW EXECUTE FUNCTION sync_listing_queue();

            -- Every movement of a deposit is on the ledger (spec 011 FR-022, research
            -- R10). At commit: a new request holds exactly its deposit, and a request
            -- that left the queue for a released / withdrawn state has its refund.
            CREATE OR REPLACE FUNCTION buy_request_money_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
              v_state buy_request_state;
            BEGIN
              PERFORM set_config('app.rls_scope', 'ledger', true);
              IF TG_OP = 'INSERT' THEN
                SELECT EXISTS (
                  SELECT 1 FROM ledger_transaction t
                  JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
                  JOIN account a ON a.account_id = p.account_id
                  WHERE t.ledger_txn_id = NEW.deposit_hold_txn_id
                    AND t.event_kind = 'deposit_hold' AND t.buy_request_id = NEW.buy_request_id
                    AND a.kind = 'cust_held' AND a.customer_id = NEW.buyer_id
                    AND p.amount = NEW.deposit_amount
                ) INTO v_ok;
              ELSE
                -- Re-read: an earlier queued event may see an older version of the row.
                PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
                SELECT state INTO v_state FROM buy_request WHERE buy_request_id = NEW.buy_request_id;
                PERFORM set_config('app.rls_scope', 'ledger', true);
                IF v_state IN ('queued','accepted') THEN
                  v_ok := TRUE;
                ELSE
                  SELECT EXISTS (
                    SELECT 1 FROM ledger_transaction t
                    JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
                    JOIN account a ON a.account_id = p.account_id
                    WHERE t.event_kind = 'deposit_release' AND t.buy_request_id = NEW.buy_request_id
                      AND a.kind = 'cust_held' AND a.customer_id = NEW.buyer_id
                      AND p.amount = -NEW.deposit_amount
                  ) INTO v_ok;
                END IF;
              END IF;
              PERFORM set_config('app.rls_scope', prev_scope, true);

              IF NOT v_ok THEN
                RAISE EXCEPTION 'buy request % has no matching deposit entry for state %', NEW.buy_request_id, NEW.state
                  USING ERRCODE = 'DH005';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER trg_buy_request_money
              AFTER INSERT OR UPDATE OF state ON buy_request
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION buy_request_money_recorded();

            -- A released deposit belongs to a request that has ended: released or
            -- withdrawn, or accepted with its order cancelled by staff (research R22).
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

            CREATE CONSTRAINT TRIGGER trg_deposit_release_allowed
              AFTER INSERT ON ledger_transaction
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION deposit_release_allowed();

            -- A cancelled order has refunded its buyer (research R22).
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

            -- A listing's state and its queue agree at commit (research R3): live has
            -- no queued request, reserved has at least one. Re-reads the row under a
            -- scope set here, whatever scope is current at commit.
            CREATE OR REPLACE FUNCTION listing_queue_consistent() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_state listing_state;
              v_count INTEGER;
            BEGIN
              PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
              SELECT l.state, (SELECT count(*) FROM buy_request r WHERE r.listing_id = l.listing_id AND r.state = 'queued')
                INTO v_state, v_count
                FROM listing l WHERE l.listing_id = NEW.listing_id;
              PERFORM set_config('app.rls_scope', prev_scope, true);

              IF (v_state = 'live' AND v_count > 0) OR (v_state = 'reserved' AND v_count = 0) THEN
                RAISE EXCEPTION 'listing % is % with % queued requests', NEW.listing_id, v_state, v_count
                  USING ERRCODE = 'DH004';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER trg_listing_queue_consistent
              AFTER UPDATE OF state, active_queue_count ON listing
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION listing_queue_consistent();

            -- spec 010's history check, now reading under a scope it sets itself
            -- (spec 011 analysis H1): a buyer's join moves the seller's listing.
            CREATE OR REPLACE FUNCTION listing_change_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF TG_OP = 'UPDATE' AND NEW.state IS NOT DISTINCT FROM OLD.state THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', dahab_queue_read_scope(prev_scope), true);
              SELECT EXISTS (SELECT 1 FROM listing_state_change c
                             WHERE c.listing_id = NEW.listing_id
                               AND c.to_state = NEW.state
                               AND c.txid = txid_current()) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'listing % moved to % without a history row', NEW.listing_id, NEW.state
                  USING ERRCODE = 'DH004';
              END IF;
              RETURN NULL;
            END $$;

            -- "order" guard: moves only along order_transition (05_schema_security.sql).
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

            CREATE TRIGGER trg_order_transition
              BEFORE UPDATE OR DELETE ON "order"
              FOR EACH ROW EXECUTE FUNCTION assert_order_transition();

            -- The chosen branch must be one the seller named at listing (04 §9).
            CREATE OR REPLACE FUNCTION assert_branch_in_options() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM listing_branch_option
                WHERE listing_id = NEW.listing_id AND branch_id = NEW.branch_id
              ) THEN
                RAISE EXCEPTION 'branch % is not among the listing''s named options', NEW.branch_id USING ERRCODE = 'DH005';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_order_branch_subset
              BEFORE INSERT OR UPDATE OF branch_id ON "order"
              FOR EACH ROW EXECUTE FUNCTION assert_branch_in_options();
            SQL);

        DB::unprepared(<<<'SQL'
            -- Row-level security (spec 011 research R2; 05_schema_security.sql).
            -- 'queue' is NOT an elevation: it is pushed only by the buy-request
            -- Actions (and the seller's withdrawal from reserved) and keeps the
            -- caller's customer id. A customer's own reads of their requests never
            -- use it. Column privacy (buyers shown by display_ref only) is the job of
            -- the Resources, guarded by BuyRequestLeakTest.
            ALTER TABLE buy_request ENABLE ROW LEVEL SECURITY;
            ALTER TABLE buy_request FORCE  ROW LEVEL SECURITY;
            ALTER TABLE "order"     ENABLE ROW LEVEL SECURITY;
            ALTER TABLE "order"     FORCE  ROW LEVEL SECURITY;

            CREATE POLICY buy_request_isolation ON buy_request FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR buyer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR buyer_id = (SELECT dahab_current_customer_id()));
            -- The queue service counts a line and a buyer's place in it.
            CREATE POLICY buy_request_queue_read ON buy_request FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue');
            -- Only the buyer, or the seller of the listing, moves a request in this scope.
            CREATE POLICY buy_request_queue_move ON buy_request FOR UPDATE
              USING ((SELECT dahab_rls_scope()) = 'queue' AND (
                       buyer_id = (SELECT dahab_current_customer_id())
                       OR EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = buy_request.listing_id
                                    AND l.seller_id = (SELECT dahab_current_customer_id()))))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'queue' AND (
                       buyer_id = (SELECT dahab_current_customer_id())
                       OR EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = buy_request.listing_id
                                    AND l.seller_id = (SELECT dahab_current_customer_id()))));

            CREATE POLICY order_isolation ON "order" FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR seller_id = (SELECT dahab_current_customer_id())
                          OR buyer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR seller_id = (SELECT dahab_current_customer_id())
                          OR buyer_id = (SELECT dahab_current_customer_id()));

            -- A buyer's summary of a piece they asked for, in any later state.
            CREATE POLICY listing_queue_read ON listing FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue' AND listed_at IS NOT NULL);
            -- The buyer's own live <-> reserved flips (a seller's moves pass listing_isolation).
            CREATE POLICY listing_queue_move ON listing FOR UPDATE
              USING      ((SELECT dahab_rls_scope()) = 'queue' AND state IN ('live','reserved'))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'queue' AND state IN ('live','reserved'));

            CREATE POLICY listing_queue_seq_queue_read ON listing_queue_seq FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue');
            CREATE POLICY listing_queue_seq_queue_bump ON listing_queue_seq FOR UPDATE
              USING ((SELECT dahab_rls_scope()) = 'queue') WITH CHECK ((SELECT dahab_rls_scope()) = 'queue');

            CREATE POLICY listing_state_change_queue_read ON listing_state_change FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue');
            CREATE POLICY listing_state_change_queue_insert ON listing_state_change FOR INSERT
              WITH CHECK ((SELECT dahab_rls_scope()) = 'queue'
                          AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL);

            CREATE POLICY listing_branch_option_queue_read ON listing_branch_option FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue');
            CREATE POLICY listing_media_queue_read ON listing_media FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue' AND NOT is_private);

            -- The head buyer's display_ref and suspension, for the seller's queue.
            CREATE POLICY customer_queue_read ON customer FOR SELECT
              USING ((SELECT dahab_rls_scope()) = 'queue'
                     AND EXISTS (SELECT 1 FROM buy_request r WHERE r.buyer_id = customer.customer_id));

            -- spec 011: the price tolerance (01_schema_core.sql §3).
            INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
              ('buyrequest.price_tolerance_pct', 0.5, 'percent',
               'A buy request locks the fresh price if the confirmed one is within this percent');

            -- spec 011: the deposit terms, version 1 (draft for the legal clinic; no fixed
            -- percentage or split, both are settings).
            INSERT INTO legal_document (code, version, body_en, body_ar, is_material, published_by)
            SELECT 'deposit_agreement', 1,
                   'I agree that the deposit shown before I send this request is held from my wallet while my request waits. It comes back in full if the seller declines, takes someone else or does not reply in time, if Dahab cancels the sale, or if I leave the queue. If the seller accepts and I then do not pay the balance in time after inspection, I lose the deposit as agreed compensation, part of it paid to the seller, as the platform''s terms set out.',
                   'موافق إن العربون اللي ظاهر قبل ما أبعت الطلب يتحجز من محفظتي طول ما طلبي مستني. العربون بيرجعلي كامل لو البايع رفض أو اختار حد تاني أو مردش في الميعاد، أو لو دهب لغت البيعة، أو لو خرجت من الطابور. ولو البايع قبل ومدفعتش الباقي في ميعاده بعد الفحص، العربون بيضيع كتعويض متفق عليه، وجزء منه بيروح للبايع حسب شروط المنصة.',
                   FALSE, staff_id
            FROM staff WHERE is_system = TRUE;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::selectOne('SELECT EXISTS (SELECT 1 FROM buy_request) AS any')->any) {
            throw new RuntimeException(
                'Refusing to roll back spec 011: buy requests exist and are tied to append-only ledger entries (held deposits). '
                .'See the migration docblock; this reversal needs a designated second reviewer.'
            );
        }

        DB::unprepared(<<<'SQL'
            DELETE FROM legal_document WHERE code = 'deposit_agreement'
              AND NOT EXISTS (SELECT 1 FROM agreement_acceptance a WHERE a.legal_doc_id = legal_document.legal_doc_id);
            DELETE FROM setting WHERE setting_key = 'buyrequest.price_tolerance_pct';

            DROP POLICY IF EXISTS customer_queue_read ON customer;
            DROP POLICY IF EXISTS listing_media_queue_read ON listing_media;
            DROP POLICY IF EXISTS listing_branch_option_queue_read ON listing_branch_option;
            DROP POLICY IF EXISTS listing_state_change_queue_insert ON listing_state_change;
            DROP POLICY IF EXISTS listing_state_change_queue_read ON listing_state_change;
            DROP POLICY IF EXISTS listing_queue_seq_queue_bump ON listing_queue_seq;
            DROP POLICY IF EXISTS listing_queue_seq_queue_read ON listing_queue_seq;
            DROP POLICY IF EXISTS listing_queue_move ON listing;
            DROP POLICY IF EXISTS listing_queue_read ON listing;

            DROP TRIGGER IF EXISTS trg_listing_queue_consistent ON listing;
            DROP TRIGGER IF EXISTS trg_listing_queue_guard ON listing;
            DROP TRIGGER IF EXISTS trg_deposit_release_allowed ON ledger_transaction;
            DROP INDEX IF EXISTS one_deposit_release_per_request;
            DROP INDEX IF EXISTS one_deposit_hold_per_request;
            ALTER TABLE ledger_transaction DROP CONSTRAINT IF EXISTS lt_order_fk;
            ALTER TABLE ledger_transaction DROP CONSTRAINT IF EXISTS lt_request_fk;

            DROP TABLE IF EXISTS "order";
            DROP SEQUENCE IF EXISTS order_ref_seq;
            DROP TABLE IF EXISTS buy_request;
            DROP TABLE IF EXISTS order_transition;
            DROP TABLE IF EXISTS buy_request_transition;

            DROP FUNCTION IF EXISTS assert_branch_in_options();
            DROP FUNCTION IF EXISTS assert_order_transition();
            DROP FUNCTION IF EXISTS order_cancel_refunded();
            DROP FUNCTION IF EXISTS deposit_release_allowed();
            DROP FUNCTION IF EXISTS listing_queue_consistent();
            DROP FUNCTION IF EXISTS listing_queue_guard();
            DROP FUNCTION IF EXISTS buy_request_money_recorded();
            DROP FUNCTION IF EXISTS sync_listing_queue();
            DROP FUNCTION IF EXISTS buy_request_guard();
            DROP FUNCTION IF EXISTS dahab_queue_read_scope(text);

            DELETE FROM listing_transition WHERE from_state = 'accepted' AND to_state IN ('live','withdrawn');
            ALTER TABLE listing_state_change DROP CONSTRAINT listing_change_note_required;
            ALTER TABLE listing_state_change ADD CONSTRAINT listing_change_note_required CHECK (
              note IS NOT NULL OR NOT (
                to_state IN ('changes_requested','rejected')
                OR (to_state = 'withdrawn' AND actor_staff_id IS NOT NULL)
              )
            );

            -- spec 010's body of the history check.
            CREATE OR REPLACE FUNCTION listing_change_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'UPDATE' AND NEW.state IS NOT DISTINCT FROM OLD.state THEN
                RETURN NULL;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM listing_state_change c
                             WHERE c.listing_id = NEW.listing_id
                               AND c.to_state = NEW.state
                               AND c.txid = txid_current()) THEN
                RAISE EXCEPTION 'listing % moved to % without a history row', NEW.listing_id, NEW.state
                  USING ERRCODE = 'DH004';
              END IF;
              RETURN NULL;
            END $$;

            DROP TYPE IF EXISTS order_state;
            DROP TYPE IF EXISTS buy_request_state;
        SQL);
    }
};
