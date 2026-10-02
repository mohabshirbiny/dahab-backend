-- =====================================================================
-- Part 4 of 4: security, audit, disputes, market makers, reconciliation,
--              and machine-readable state transitions.
-- =====================================================================
SET search_path = dahab, public;

-- ---------------------------------------------------------------------
-- 13. Audit log (append-only, the spine of accountability)
--     Every privileged action records: who, when, from which device and
--     address, what changed (before/after), and a reason where required.
--     No role, including founders, can edit or delete an entry.
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
  audit_id     BIGSERIAL PRIMARY KEY,
  actor_staff_id UUID REFERENCES staff(staff_id),
  actor_customer_id UUID REFERENCES customer(customer_id),
  action       TEXT NOT NULL,                    -- e.g. 'withdrawal.release'
  entity_type  TEXT NOT NULL,                    -- e.g. 'withdrawal'
  entity_id    UUID,
  before_json  JSONB,
  after_json   JSONB,
  reason       TEXT,
  ip_address   INET,
  device_fingerprint TEXT,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT audit_has_actor CHECK (
    actor_staff_id IS NOT NULL OR actor_customer_id IS NOT NULL
  )
);

CREATE INDEX idx_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX idx_audit_actor ON audit_log(actor_staff_id);
CREATE INDEX idx_audit_created ON audit_log(created_at);
-- (spec 006) The audit log viewer lists newest first and pages by
-- (created_at, audit_id); this index serves both.
CREATE INDEX idx_audit_created_id ON audit_log(created_at DESC, audit_id DESC);
-- (spec 007) A customer file's History lists what the customer did, newest first.
CREATE INDEX idx_audit_actor_customer ON audit_log(actor_customer_id, created_at DESC)
  WHERE actor_customer_id IS NOT NULL;

-- Append-only: no updates or deletes, ever.
CREATE TRIGGER audit_no_update BEFORE UPDATE OR DELETE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

-- (spec 007) Server-side idempotency store (Part 2 "Idempotency"). A
-- state-creating POST carries an Idempotency-Key; the first request runs and
-- its response (< 500) is kept for 24 h; a replay of the same key returns
-- it without running again. The same key with a DIFFERENT request_hash is a
-- client bug (422), never the cached response of the other request. Keys are
-- scoped per actor and endpoint. Rows are operational, not audit: they move
-- in_flight -> completed | failed and are pruned after expires_at.
-- Forced RLS: a customer context sees only its own keys.
CREATE TABLE idempotency_key (
  id                BIGSERIAL PRIMARY KEY,
  idem_key          UUID NOT NULL,
  actor_kind        TEXT NOT NULL CHECK (actor_kind IN ('customer','staff')),
  actor_customer_id UUID REFERENCES customer(customer_id),
  actor_staff_id    UUID REFERENCES staff(staff_id),
  endpoint          TEXT NOT NULL,               -- route name
  request_hash      CHAR(64) NOT NULL,           -- SHA-256 of canonical body + route params
  state             TEXT NOT NULL DEFAULT 'in_flight'
                      CHECK (state IN ('in_flight','completed','failed')),
  response_status   SMALLINT,
  response_body     TEXT,                        -- the exact bytes, replayed as sent (JSONB would reorder keys)
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  completed_at      TIMESTAMPTZ,
  expires_at        TIMESTAMPTZ NOT NULL,
  CONSTRAINT idempotency_has_actor CHECK (
       (actor_kind = 'customer' AND actor_customer_id IS NOT NULL AND actor_staff_id IS NULL)
    OR (actor_kind = 'staff'    AND actor_staff_id IS NOT NULL    AND actor_customer_id IS NULL)
  )
);
CREATE UNIQUE INDEX uq_idempotency_key ON idempotency_key
  (actor_kind, COALESCE(actor_customer_id, actor_staff_id), endpoint, idem_key);
CREATE INDEX idx_idempotency_expires ON idempotency_key(expires_at);

-- Document-view logging: every view of an identity document is recorded,
-- including views that lead to no decision.
CREATE TABLE document_view_log (
  view_id      BIGSERIAL PRIMARY KEY,
  document_id  UUID NOT NULL REFERENCES identity_document(document_id),
  viewed_by    UUID NOT NULL REFERENCES staff(staff_id),
  viewed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address   INET
);
CREATE TRIGGER docview_no_update BEFORE UPDATE OR DELETE ON document_view_log
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

-- ---------------------------------------------------------------------
-- 14. Category stops / pauses (operating controls)
--     Three levels per category. Anything with a locked price is left
--     alone. Recorded with the actor and message.
-- ---------------------------------------------------------------------
CREATE TABLE category_control (
  control_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  category     piece_category NOT NULL,
  level        TEXT NOT NULL CHECK (level IN
                 ('stop_new_listings','pause_category','stop_everything')),
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  message_en   TEXT,
  message_ar   TEXT,
  set_by       UUID NOT NULL REFERENCES staff(staff_id),
  set_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  cleared_by   UUID REFERENCES staff(staff_id),
  cleared_at   TIMESTAMPTZ
);

-- ---------------------------------------------------------------------
-- 15. Market maker programme
-- ---------------------------------------------------------------------
CREATE TABLE promo_code (
  code         TEXT PRIMARY KEY,
  kind         TEXT NOT NULL CHECK (kind IN ('first_sale','market_maker')),
  -- Market maker codes are tied to exactly one customer account.
  tied_customer_id UUID REFERENCES customer(customer_id),
  commission_waived BOOLEAN NOT NULL DEFAULT FALSE,
  gives_spread BOOLEAN NOT NULL DEFAULT FALSE,
  monthly_cap_egp NUMERIC(18,4),
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  created_by   UUID NOT NULL REFERENCES staff(staff_id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  deactivated_by UUID REFERENCES staff(staff_id),
  deactivated_at TIMESTAMPTZ,
  CONSTRAINT mm_is_tied CHECK (kind <> 'market_maker' OR tied_customer_id IS NOT NULL)
);

-- Admin approval of a specific piece for market-maker purchase, logged
-- with the numbers the approver saw. Piece must be older than the
-- min_list_age_days setting (checked in the service, recorded here).
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

-- ---------------------------------------------------------------------
-- 16. Disputes
-- ---------------------------------------------------------------------
CREATE TABLE dispute (
  dispute_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  dispute_ref  TEXT UNIQUE NOT NULL,
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  raised_by    UUID NOT NULL REFERENCES customer(customer_id),
  reason       TEXT NOT NULL,
  detail       TEXT,
  state        TEXT NOT NULL DEFAULT 'open'
                 CHECK (state IN ('open','passed_on','resolved')),
  assigned_to  UUID REFERENCES staff(staff_id),
  -- A dispute cannot be closed silently: resolution needs a reply or a
  -- named colleague it was passed to.
  resolution_reply TEXT,
  resolved_by  UUID REFERENCES staff(staff_id),
  opened_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  resolved_at  TIMESTAMPTZ,
  CONSTRAINT resolved_needs_reply CHECK (
    state <> 'resolved' OR (resolution_reply IS NOT NULL AND resolved_by IS NOT NULL)
  )
);

-- Case file assembly for law enforcement (built on request, audited).
CREATE TABLE case_file (
  case_file_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID REFERENCES "order"(order_id),
  requested_by UUID NOT NULL REFERENCES staff(staff_id),
  received_by  TEXT,                             -- who it was handed to
  built_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 17. Reconciliation (daily close)
--     External bank movements (capital, rent, fees, profit) are recorded
--     by hand with proof. Each day is compared and, when clean, locked.
-- ---------------------------------------------------------------------
CREATE TABLE bank_movement (
  movement_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  kind         TEXT NOT NULL,                    -- 'capital_in','rent','bank_charge','profit_draw'
  amount       NUMERIC(18,4) NOT NULL,           -- signed
  occurred_on  DATE NOT NULL,
  reason       TEXT NOT NULL,
  proof_ref    TEXT,                             -- attached document
  recorded_by  UUID NOT NULL REFERENCES staff(staff_id),  -- ceo/finance
  ledger_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
  recorded_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE daily_close (
  close_date   DATE PRIMARY KEY,
  bank_balance NUMERIC(18,4) NOT NULL,
  customer_liability NUMERIC(18,4) NOT NULL,
  dahab_wallet NUMERIC(18,4) NOT NULL,
  difference   NUMERIC(18,4) NOT NULL,
  is_locked    BOOLEAN NOT NULL DEFAULT FALSE,
  closed_by    UUID REFERENCES staff(staff_id),
  closed_at    TIMESTAMPTZ
);
CREATE TRIGGER daily_close_no_reopen BEFORE UPDATE OR DELETE ON daily_close
  FOR EACH ROW WHEN (OLD.is_locked) EXECUTE FUNCTION block_mutation();

-- ---------------------------------------------------------------------
-- 18. State-machine transition tables (machine-readable + guard rails)
--     These declare the legal transitions so the service layer (and a
--     trigger, if desired) can reject an illegal move. They are DATA, so
--     a new transition is a row, reviewed, not a scattered code change.
-- ---------------------------------------------------------------------
CREATE TABLE order_transition (
  from_state   order_state NOT NULL,
  to_state     order_state NOT NULL,
  note         TEXT,
  PRIMARY KEY (from_state, to_state)
);

INSERT INTO order_transition (from_state, to_state, note) VALUES
  ('awaiting_delivery','at_inspection','seller reached the branch'),
  ('awaiting_delivery','cancelled_seller','seller cancelled after accepting, or reach-branch deadline missed'),
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
  ('disputed','ready_to_collect','dispute resolved, resume'),
  ('awaiting_delivery','cancelled_staff','staff cancelled the acceptance; deposit refunded (spec 011)'),
  ('awaiting_balance','weight_adjust_pending','corrected result needs the buyer''s approval (spec 012)'),
  ('awaiting_balance','cancelled_inspection','corrected result: karat mismatch / counterfeit (spec 012)');

CREATE TABLE listing_transition (
  from_state   listing_state NOT NULL,
  to_state     listing_state NOT NULL,
  note         TEXT,
  PRIMARY KEY (from_state, to_state)
);

INSERT INTO listing_transition (from_state, to_state, note) VALUES
  ('draft','in_review','submitted for listing review'),
  ('in_review','changes_requested','reviewer asked for a better photo/detail'),
  ('changes_requested','in_review','resubmitted'),
  ('in_review','live','approved and published'),
  ('in_review','rejected','rejected by the reviewer (spec 010); final'),
  ('live','reserved','first buy request queued'),
  ('reserved','live','queue emptied (all requests released)'),
  ('reserved','accepted','seller accepted the first in the queue'),
  ('accepted','at_inspection','piece delivered to branch'),
  ('at_inspection','settling','inspection recorded'),
  ('settling','sold','completed'),
  ('settling','live','sale fell through; back on the market'),
  -- withdrawn is FINAL (spec 010): no withdrawn -> in_review, no withdrawn -> live.
  ('live','withdrawn','seller/admin took it down'),
  ('reserved','withdrawn','taken down (no locked price affected)'),
  ('live','suspended_hold','category paused / account suspended'),
  ('reserved','suspended_hold','category paused'),
  ('suspended_hold','live','category reopened'),
  -- Buyer paid in full but never collected within the collect window. The
  -- piece stays at IGI; a status is shown to the buyer. Disposition (hand
  -- over / compensate) is a manual decision when the buyer makes contact.
  ('sold','uncollected_expired','buyer paid, never collected, collect window passed'),
  ('uncollected_expired','sold','buyer made contact and collected'),
  -- Buyer did NOT pay the balance in time: the piece returns to the seller,
  -- who collects it and receives 50% of the deposit as compensation. The
  -- listing tracks the physical return of the piece to the seller.
  ('settling','awaiting_seller_return','buyer did not pay; piece returns to the seller'),
  ('accepted','awaiting_seller_return','buyer did not pay before/at delivery; piece returns to the seller'),
  ('at_inspection','awaiting_seller_return','buyer did not pay; piece returns to the seller'),
  ('awaiting_seller_return','withdrawn','seller collected the returned piece'),
  ('awaiting_seller_return','live','seller chose to relist instead of collecting'),
  -- Seller never came for the returned piece within the seller-return window.
  ('awaiting_seller_return','seller_unclaimed','seller-return window passed; seller never came'),
  ('seller_unclaimed','withdrawn','seller finally collected / Dahab handed over'),
  ('seller_unclaimed','live','relisted after contact'),
  -- spec 011: the staff cancellation of an acceptance (order.cancel) relists or withdraws.
  ('accepted','live','staff cancelled the acceptance; back on the market'),
  ('accepted','withdrawn','staff cancelled the acceptance and withdrew the piece');

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

CREATE TABLE withdrawal_transition (
  from_state   withdrawal_state NOT NULL,
  to_state     withdrawal_state NOT NULL,
  note         TEXT,
  PRIMARY KEY (from_state, to_state)
);

INSERT INTO withdrawal_transition (from_state, to_state, note) VALUES
  ('requested','under_review','picked up for review'),
  ('under_review','released','approved and sent to bank'),
  ('under_review','rejected','reviewer rejected; funds returned'),
  ('requested','on_hold_account_change','payout account changed; paused'),
  ('on_hold_account_change','under_review','pause window elapsed'),
  ('requested','cancelled','holder cancelled'),
  ('under_review','cancelled','holder cancelled'),
  ('released','settled','confirmed arrived at bank');

-- Optional generic guard: reject an order state change not in the table.
-- As built (spec 011, spec 012): also refuses a delete and changes to the
-- identity, accepted_at and locked_total_price, freezes locked_seller_unit_rate
-- once set and, once paid, the settlement figures and each *_txn_id; raises
-- SQLSTATE DH006 -> 409 illegal_order_transition (full body in
-- database/migrations/2026_10_05_000010_create_orders_lifecycle.php).
CREATE OR REPLACE FUNCTION assert_order_transition() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.state IS DISTINCT FROM OLD.state THEN
    IF NOT EXISTS (SELECT 1 FROM order_transition
                   WHERE from_state = OLD.state AND to_state = NEW.state) THEN
      RAISE EXCEPTION 'illegal order transition % -> %', OLD.state, NEW.state;
    END IF;
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER trg_order_transition
  BEFORE UPDATE OF state ON "order"
  FOR EACH ROW EXECUTE FUNCTION assert_order_transition();

-- Listing guards (added by spec 010) ------------------------------------
-- A listing is born a draft, is never deleted, keeps its seller, and changes
-- state only along listing_transition. SQLSTATE DH004 -> 409
-- illegal_listing_transition. The guard also stamps state_changed_at and
-- sets listed_at the first time the listing goes live (the wall clock, so
-- two moves in one transaction keep their order). `withdrawn` and
-- `rejected` have no outgoing row: both are final.
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

CREATE TRIGGER trg_listing_guard
  BEFORE INSERT OR UPDATE OR DELETE ON listing
  FOR EACH ROW EXECUTE FUNCTION listing_guard();

-- Every move is recorded: at commit, the creation and each state change must
-- have its listing_state_change row written in the same transaction.
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

CREATE CONSTRAINT TRIGGER trg_listing_change_recorded
  AFTER INSERT OR UPDATE OF state ON listing
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION listing_change_recorded();

-- ---------------------------------------------------------------------
-- 19. Row-Level Security (customer data isolation)
--     Implemented by spec 003 (specs/003-customer-rls-isolation), migration
--     2026_09_26_000040_enable_customer_row_level_security.
--
--     A customer can see and change only their own rows — enforced by the
--     engine, forced even for the application's owning role (defense in
--     depth; Constitution v2 Principle II). Staff access is decided by
--     application permissions (Spatie), not per-role database grants.
--
--     The actor is bound per unit of work (request / queued job / CLI
--     migrate or seed) by App\Support\DatabaseActor as session settings:
--       app.rls_scope          '' | customer | staff | bootstrap | system | maintenance
--       app.current_customer_id, app.current_staff_id
--     and restored when the unit ends. No scope => customer tables are
--     empty and read-only (fail closed). See Part 1 §5.1.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dahab_rls_scope() RETURNS text AS $$
  SELECT COALESCE(current_setting('app.rls_scope', true), '');
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION dahab_rls_elevated() RETURNS boolean AS $$
  SELECT dahab_rls_scope() IN ('staff', 'system', 'bootstrap', 'maintenance');
$$ LANGUAGE sql STABLE;
-- dahab_current_customer_id() / dahab_current_staff_id(): migration 2026_09_19_003010.

-- Tables that exist today ---------------------------------------------
ALTER TABLE customer                ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer                FORCE  ROW LEVEL SECURITY;
CREATE POLICY customer_isolation ON customer FOR ALL
  USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
  WITH CHECK (dahab_rls_elevated() OR customer_id = (SELECT dahab_current_customer_id()));

-- Same shape (ENABLE + FORCE + FOR ALL USING/WITH CHECK on customer_id):
--   customer_password, customer_trusted_device, identity_document
-- and on actor_customer_id:
--   one_time_token   (staff-actor tokens are never visible to a customer)

ALTER TABLE audit_log               ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_log               FORCE  ROW LEVEL SECURITY;
CREATE POLICY audit_log_read  ON audit_log FOR SELECT USING (dahab_rls_elevated());
CREATE POLICY audit_log_write ON audit_log FOR INSERT WITH CHECK (
  dahab_rls_elevated()
  OR (actor_customer_id = dahab_current_customer_id() AND actor_staff_id IS NULL)
);   -- UPDATE/DELETE: no policy (and the append-only trigger blocks them anyway)

ALTER TABLE document_view_log       ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_view_log       FORCE  ROW LEVEL SECURITY;
CREATE POLICY document_view_log_staff ON document_view_log FOR ALL
  USING (dahab_rls_elevated()) WITH CHECK (dahab_rls_elevated());

-- Ledger (added by spec 008, research R3) ------------------------------
-- The scope checks are wrapped in scalar subqueries so PostgreSQL runs
-- them once per query (InitPlan) instead of once per row: the Wallet
-- statement sums hundreds of thousands of lines (SC-004).
-- The 'ledger' scope is NOT in dahab_rls_elevated(): it sees and inserts
-- ledger rows only, and nothing on other customer tables. The money
-- service (PostLedgerEntryAction) pushes it for its reads, locks and
-- inserts; the deferred ledger triggers set it transaction-locally.
-- Customers read their own accounts and lines; nobody writes directly
-- from a customer scope. The only UPDATE policies are lock-only (the
-- ledger scope may SELECT ... FOR UPDATE; WITH CHECK (false) refuses any
-- actual update); there are no DELETE policies, and the append-only
-- triggers block both anyway.
ALTER TABLE account            ENABLE ROW LEVEL SECURITY;
ALTER TABLE account            FORCE  ROW LEVEL SECURITY;
CREATE POLICY account_read ON account FOR SELECT USING (
  (SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'ledger'
  OR customer_id = (SELECT dahab_current_customer_id()));
CREATE POLICY account_write ON account FOR INSERT WITH CHECK (
  (SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'ledger');
-- SELECT ... FOR UPDATE only sees rows that pass an UPDATE policy.
-- This one lets the money service lock rows; WITH CHECK (false)
-- still refuses every actual UPDATE (research R5).
CREATE POLICY account_lock ON account FOR UPDATE
  USING ((SELECT dahab_rls_scope()) = 'ledger') WITH CHECK (false);

ALTER TABLE ledger_posting     ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_posting     FORCE  ROW LEVEL SECURITY;
CREATE POLICY ledger_posting_read ON ledger_posting FOR SELECT USING (
  (SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'ledger'
  OR EXISTS (SELECT 1 FROM account a WHERE a.account_id = ledger_posting.account_id
             AND a.customer_id = (SELECT dahab_current_customer_id())));
CREATE POLICY ledger_posting_write ON ledger_posting FOR INSERT WITH CHECK (
  (SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'ledger');

ALTER TABLE ledger_transaction ENABLE ROW LEVEL SECURITY;
ALTER TABLE ledger_transaction FORCE  ROW LEVEL SECURITY;
CREATE POLICY ledger_transaction_read ON ledger_transaction FOR SELECT USING (
  (SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'ledger'
  OR EXISTS (SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
             WHERE p.ledger_txn_id = ledger_transaction.ledger_txn_id
               AND a.customer_id = (SELECT dahab_current_customer_id())));
CREATE POLICY ledger_transaction_write ON ledger_transaction FOR INSERT WITH CHECK (
  (SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'ledger');
CREATE POLICY ledger_transaction_lock ON ledger_transaction FOR UPDATE
  USING ((SELECT dahab_rls_scope()) = 'ledger') WITH CHECK (false);

-- Top-ups (added by spec 009) ---------------------------------------------
-- A customer sees and changes only their own notices (submit, cancel);
-- staff act in the elevated 'staff' scope. receiving_account is reference
-- data (no customer column) and has no RLS.
ALTER TABLE topup              ENABLE ROW LEVEL SECURITY;
ALTER TABLE topup              FORCE  ROW LEVEL SECURITY;
CREATE POLICY topup_isolation ON topup FOR ALL
  USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
  WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

-- Pattern for tables added by later modules ---------------------------
-- In the SAME migration that creates the table: ENABLE + FORCE RLS and
--   CREATE POLICY <table>_isolation ON <table> FOR ALL
--     USING      (dahab_rls_elevated() OR <owner predicate>)
--     WITH CHECK (dahab_rls_elevated() OR <owner predicate>);
-- Owner predicates planned:
--   payout_account  customer_id = dahab_current_customer_id()
--   withdrawal      customer_id = dahab_current_customer_id()
-- CustomerTableIsolationTest fails the build for any table with a
-- customer_id / actor_customer_id / buyer_id / seller_id column that lacks
-- forced RLS and a policy.

-- Buy requests and orders (added by spec 011) ---------------------------
-- buy_request: buyer_id = current customer; "order": seller or buyer. A
-- customer's own reads of their requests run under this isolation only.
--
-- The non-elevated 'queue' scope (spec 011 research R2; a recorded deviation
-- from Constitution II, see specs/011-buy-requests/plan.md). A queue operation
-- spans two customers: a buyer's join moves the seller's listing and its
-- counter; a seller's accept moves other buyers' requests and refunds them.
-- The scope is pushed only by the buy-request Actions (DatabaseActor::queue(),
-- QueueScopeTest), keeps the caller's customer id, and never narrows an
-- elevated caller. Policies:
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
-- Only the buyer, or the seller of the listing, moves a request in this scope
-- (the guard trigger keeps a seller to the state column).
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
-- The buyer's own live <-> reserved flips; trg_listing_queue_guard keeps a
-- non-seller to the state and queue-count columns.
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

-- The order's life (added by spec 012) -----------------------------------
-- Every table of an order follows its order: visible to its seller and buyer
-- (each inner SELECT on "order" is filtered by order_isolation) or to an
-- elevated scope. Customers write them only in the non-elevated 'order'
-- scope, pushed by the customer order Actions; every write under it is
-- AUDITED with the customer as actor (order.seller_cancelled, order.decided,
-- order.paid, order.relisted — analysis C1), and no customer path elevates
-- (analysis C2). A recorded deviation from Constitution II, like 'queue'.
--   new tables (FORCE RLS): order_state_change, order_branch_change,
--   order_deadline_extension, seller_cancellation, inspection_result,
--   settlement_decision, collection, seller_return
--     <table>_isolation FOR ALL USING (elevated OR EXISTS my order);
--     WITH CHECK: elevated only for the staff tables (branch change,
--     extension, inspection); elevated OR ('order' scope AND my order) for
--     the customer-written ones (seller_cancellation by its seller,
--     settlement_decision and collection by the buyer, seller_return by
--     either; order_state_change also needs actor_customer_id = me).
--   'order' scope on existing tables:
--     buy_request_order_read (SELECT: the request of my order)
--     listing_order_read (SELECT) / listing_order_move (UPDATE by the buyer of
--       an order on it, to sold | awaiting_seller_return only; the column
--       side is listing_queue_guard, now for 'queue' and 'order')
--     listing_state_change_order_insert / _order_read (actor = me)
--     listing_media_order_read (public only), listing_branch_option_order_read
--     customer_order_read (the other party of my order; display_ref only by
--       the Resources)
-- (Full DDL: database/migrations/2026_10_05_000010_create_orders_lifecycle.php.)

-- Listings (added by spec 010) --------------------------------------------
-- A seller sees and changes only their own listings and what hangs off them;
-- staff act in the elevated 'staff' scope.
--
-- PUBLIC MARKET (product-owner decision, spec 010 — replaces the earlier
-- "dedicated view" note): there is NO view and no separate low-privilege
-- role. The unauthenticated market request runs in the read-only scope
-- 'market' (not an elevation; it carries no customer id):
--     public market -> 'market' scope -> listing -> public response Resource
-- In that scope the engine returns only listings in state live/reserved,
-- their branch options and their NON-private media, and accepts no write
-- (the scope has SELECT policies only). Column privacy (seller_id) is the
-- job of the market Resources, guarded by MarketLeakTest / MarketScopeTest.
ALTER TABLE listing                       ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing                       FORCE  ROW LEVEL SECURITY;
ALTER TABLE listing_media                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_media                 FORCE  ROW LEVEL SECURITY;
ALTER TABLE listing_branch_option         ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_branch_option         FORCE  ROW LEVEL SECURITY;
ALTER TABLE listing_ownership_declaration ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_ownership_declaration FORCE  ROW LEVEL SECURITY;
ALTER TABLE listing_state_change          ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_state_change          FORCE  ROW LEVEL SECURITY;
ALTER TABLE listing_queue_seq             ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_queue_seq             FORCE  ROW LEVEL SECURITY;

CREATE POLICY listing_isolation ON listing FOR ALL
  USING      (dahab_rls_elevated() OR seller_id = dahab_current_customer_id())
  WITH CHECK (dahab_rls_elevated() OR seller_id = dahab_current_customer_id());
CREATE POLICY listing_market_read ON listing FOR SELECT
  USING (dahab_rls_scope() = 'market' AND state IN ('live','reserved'));

-- Child rows follow their listing. The inner SELECT on listing is itself
-- filtered by the listing policies above.
CREATE POLICY listing_media_isolation ON listing_media FOR ALL
  USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_media.listing_id AND l.seller_id = dahab_current_customer_id()))
  WITH CHECK (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_media.listing_id AND l.seller_id = dahab_current_customer_id()));
CREATE POLICY listing_media_market_read ON listing_media FOR SELECT
  USING (dahab_rls_scope() = 'market' AND NOT is_private
         AND EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_media.listing_id));

CREATE POLICY listing_branch_option_isolation ON listing_branch_option FOR ALL
  USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_branch_option.listing_id AND l.seller_id = dahab_current_customer_id()))
  WITH CHECK (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_branch_option.listing_id AND l.seller_id = dahab_current_customer_id()));
CREATE POLICY listing_branch_option_market_read ON listing_branch_option FOR SELECT
  USING (dahab_rls_scope() = 'market'
         AND EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_branch_option.listing_id));

-- Never visible to the market.
CREATE POLICY listing_ownership_declaration_isolation ON listing_ownership_declaration FOR ALL
  USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
  WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

CREATE POLICY listing_queue_seq_isolation ON listing_queue_seq FOR ALL
  USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_queue_seq.listing_id AND l.seller_id = dahab_current_customer_id()))
  WITH CHECK (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_queue_seq.listing_id AND l.seller_id = dahab_current_customer_id()));

-- A seller reads the history of their own listing; a row written in the
-- customer scope must name that customer as the actor, never staff.
CREATE POLICY listing_state_change_isolation ON listing_state_change FOR ALL
  USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                WHERE l.listing_id = listing_state_change.listing_id AND l.seller_id = dahab_current_customer_id()))
  WITH CHECK (dahab_rls_elevated() OR (
                actor_customer_id = dahab_current_customer_id() AND actor_staff_id IS NULL
                AND EXISTS (SELECT 1 FROM listing l
                  WHERE l.listing_id = listing_state_change.listing_id AND l.seller_id = dahab_current_customer_id())));

-- =====================================================================
-- Added by feature 001-auth-customer-staff
-- Dashboard roles/permissions: spatie/laravel-permission's standard tables
-- (guard_name = 'staff'). Replaces the earlier custom staff_permission
-- table. Created by the package migration; the only customisation is that
-- model_id is UUID because staff.staff_id is UUID.
-- Changed by spec 002: roles, role -> permission and staff -> role are
-- Dashboard-managed data. Permission codes are the code-defined catalogue
-- (App\Enums\StaffPermission), synced additively by
-- DashboardRolesAndPermissionsSeeder. See
-- docs/Technical Spec/dahab-dashboard-authorization.md.
-- =====================================================================

CREATE TABLE permissions (
  id          BIGSERIAL PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  guard_name  VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP(0),
  updated_at  TIMESTAMP(0),
  UNIQUE (name, guard_name)
);

CREATE TABLE roles (
  id            BIGSERIAL PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,          -- machine name, immutable
  guard_name    VARCHAR(255) NOT NULL,
  display_name  VARCHAR(100) NOT NULL,          -- spec 002
  description   TEXT,                           -- spec 002
  requires_mfa  BOOLEAN NOT NULL DEFAULT FALSE, -- spec 002
  created_at    TIMESTAMP(0),
  updated_at    TIMESTAMP(0),
  UNIQUE (name, guard_name),
  CONSTRAINT roles_name_format CHECK (name ~ '^[a-z][a-z0-9_]{2,49}$'),
  CONSTRAINT roles_guard_staff CHECK (guard_name = 'staff')
);

CREATE TABLE model_has_permissions (
  permission_id BIGINT       NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
  model_type    VARCHAR(255) NOT NULL,
  model_id      UUID         NOT NULL,   -- staff.staff_id
  PRIMARY KEY (permission_id, model_id, model_type)
);
CREATE INDEX model_has_permissions_model_id_model_type_index
  ON model_has_permissions (model_id, model_type);

CREATE TABLE model_has_roles (
  role_id    BIGINT       NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  model_type VARCHAR(255) NOT NULL,
  model_id   UUID         NOT NULL,      -- staff.staff_id
  PRIMARY KEY (role_id, model_id, model_type)
);
CREATE INDEX model_has_roles_model_id_model_type_index
  ON model_has_roles (model_id, model_type);

CREATE TABLE role_has_permissions (
  permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
  role_id       BIGINT NOT NULL REFERENCES roles(id)       ON DELETE CASCADE,
  PRIMARY KEY (permission_id, role_id)
);

-- =====================================================================
-- Added by feature 001-auth-customer-staff
-- One-time tokens for password reset and email verification. Exactly one
-- actor per token (customer OR staff). token_hash is sha256 of the
-- signed payload so a database dump cannot replay a live token.
-- =====================================================================

CREATE TABLE one_time_token (
  id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  token_hash         TEXT NOT NULL UNIQUE,
  purpose            TEXT NOT NULL CHECK (purpose IN (
                        'password_reset_customer',
                        'password_reset_staff',
                        'email_verification'
                     )),
  actor_customer_id  UUID REFERENCES customer(customer_id) ON DELETE CASCADE,
  actor_staff_id     UUID REFERENCES staff(staff_id)       ON DELETE CASCADE,
  payload            JSONB,
  expires_at         TIMESTAMPTZ NOT NULL,
  consumed_at        TIMESTAMPTZ,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT one_time_token_exactly_one_actor CHECK (
    (actor_customer_id IS NOT NULL) <> (actor_staff_id IS NOT NULL)
  )
);
CREATE INDEX idx_one_time_token_actor_customer ON one_time_token(actor_customer_id);
CREATE INDEX idx_one_time_token_actor_staff    ON one_time_token(actor_staff_id);

-- =====================================================================
-- Added by feature 001-auth-customer-staff
-- personal_access_tokens extensions. The base table is created by
-- laravel/sanctum; the columns below are added so a refresh token can
-- be linked to its access token (family_id), rotation can be detected
-- (rotated_at), and the actor kind is explicit at the API layer
-- (actor_kind) instead of inferred from tokenable_type.
-- =====================================================================

-- ALTER TABLE personal_access_tokens
--   ADD COLUMN family_id  UUID,
--   ADD COLUMN rotated_at TIMESTAMPTZ,
--   ADD COLUMN revoked_at TIMESTAMPTZ,
--   ADD COLUMN actor_kind TEXT NOT NULL DEFAULT 'customer'
--     CHECK (actor_kind IN ('customer','staff'));
-- CREATE INDEX idx_pat_family ON personal_access_tokens(family_id);
--
-- Token abilities need no schema change; they live in the Sanctum `abilities`
-- column. Every token carries exactly one of: customer:access,
-- customer:refresh, staff:access, staff:refresh. The wildcard '*' is never
-- issued. tokenable_type is App\Models\Customer or App\Models\Staff, and the
-- `customer` / `staff` guards only resolve tokens owned by their own model.
-- Revocation deletes rows; revoked_at is reserved and not written.
