-- =====================================================================
-- Part 3 of 4: marketplace core
--   listings, the buyer queue, orders, inspections, withdrawals,
--   payout accounts. Business rules from the updated documents are
--   enforced here with constraints where a constraint can carry them.
-- =====================================================================
SET search_path = dahab, public;

-- ---------------------------------------------------------------------
-- 7. Listings
--    A piece put up for sale. Branch choice is MADE AT LISTING (the
--    seller names the branch or branches she is willing to deliver to);
--    the final branch is chosen at acceptance from that set.
-- ---------------------------------------------------------------------
CREATE TABLE listing (
  listing_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  seller_id      UUID NOT NULL REFERENCES customer(customer_id),
  category       piece_category NOT NULL,
  piece_type_id  SMALLINT NOT NULL REFERENCES piece_type(piece_type_id),
  karat_code     SMALLINT REFERENCES karat(karat_code),   -- NULL for pure diamond
  stated_weight_g NUMERIC(10,3),                           -- NULL for pure diamond
  making_charge_per_g NUMERIC(18,4),                       -- gold making charge
  asking_price   NUMERIC(18,4),                            -- stones / whole-piece ask
  description    TEXT,
  state          listing_state NOT NULL DEFAULT 'draft',
  -- Denormalised current queue depth for fast display; kept in step with
  -- buy_request via trigger. Source of truth is the buy_request rows.
  active_queue_count INTEGER NOT NULL DEFAULT 0,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  listed_at      TIMESTAMPTZ,                              -- when it FIRST went live (set by listing_guard)
  -- spec 010: when the state last changed (stamped by listing_guard). Orders
  -- the review queue "oldest first" and gives the waiting time.
  state_changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT gold_needs_karat_weight CHECK (
    category = 'diamond'
    OR (karat_code IS NOT NULL AND stated_weight_g IS NOT NULL)
  ),
  CONSTRAINT queue_count_nonneg CHECK (active_queue_count >= 0),
  -- spec 010: gold is priced by making charge, stones by one asking price.
  CONSTRAINT listing_price_shape CHECK (
    (category = 'gold' AND making_charge_per_g IS NOT NULL AND asking_price IS NULL)
    OR (category <> 'gold' AND asking_price IS NOT NULL AND making_charge_per_g IS NULL)
  ),
  -- spec 010: positive weight; money people type has at most 2 decimals.
  CONSTRAINT listing_amounts CHECK (
    (stated_weight_g IS NULL OR stated_weight_g > 0)
    AND (making_charge_per_g IS NULL
         OR (making_charge_per_g >= 0 AND making_charge_per_g = round(making_charge_per_g, 2)))
    AND (asking_price IS NULL OR (asking_price > 0 AND asking_price = round(asking_price, 2)))
  ),
  CONSTRAINT listing_description_len CHECK (description IS NULL OR char_length(description) <= 2000),
  -- spec 010: anything that has been on the market knows when it went live.
  CONSTRAINT listing_listed_shape CHECK (
    listed_at IS NOT NULL OR state IN ('draft','in_review','changes_requested','rejected')
  )
);

CREATE INDEX idx_listing_state  ON listing(state, state_changed_at);
CREATE INDEX idx_listing_seller ON listing(seller_id, created_at DESC);
-- spec 010: the public market page (newest first).
CREATE INDEX idx_listing_market ON listing(listed_at DESC, listing_id)
  WHERE state IN ('live','reserved');

-- Photos / video / uploaded original invoice / uploaded stone certificate.
-- Only the invoice is private; the stone certificate is public once the
-- listing is live (spec 010). storage_ref names a chunk-encrypted object on
-- the private disk; mime is what it is served as; position orders the photos.
CREATE TABLE listing_media (
  media_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id   UUID NOT NULL REFERENCES listing(listing_id),
  kind         TEXT NOT NULL CHECK (kind IN
                 ('photo','video','invoice','stone_certificate')),
  storage_ref  TEXT NOT NULL,
  is_private   BOOLEAN NOT NULL DEFAULT FALSE,   -- invoice stays private pre-sale
  mime         TEXT NOT NULL,                    -- spec 010
  position     SMALLINT NOT NULL DEFAULT 0,      -- spec 010
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_listing_media_listing ON listing_media(listing_id, kind, position);

-- Branches the seller named at listing (the willing set). The final
-- branch chosen at acceptance MUST be one of these.
CREATE TABLE listing_branch_option (
  listing_id  UUID NOT NULL REFERENCES listing(listing_id),
  branch_id   SMALLINT NOT NULL REFERENCES branch(branch_id),
  PRIMARY KEY (listing_id, branch_id)
);

-- Ownership declaration accepted at listing (evidence trail).
-- (Additionally recorded in agreement_acceptance; this ties it to a piece.)
CREATE TABLE listing_ownership_declaration (
  listing_id   UUID PRIMARY KEY REFERENCES listing(listing_id),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  accepted_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  legal_doc_id SMALLINT NOT NULL REFERENCES legal_document(legal_doc_id)
);

-- Listing history (added by spec 010). One permanent row per state change:
-- who moved the listing, from what to what, and the message or reason
-- (the reviewer's "changes needed" text, a rejection or take-down reason,
-- 'account_suspended' / 'account_reinstated' for holds). from_state NULL is
-- the creation. listing_transition (Part 4) is the table of ALLOWED moves;
-- this is the record of the moves that happened. txid ties the row to the
-- transaction of the move: trg_listing_change_recorded refuses a commit that
-- moved a listing without one.
CREATE TABLE listing_state_change (
  change_id    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  listing_id   UUID NOT NULL REFERENCES listing(listing_id),
  from_state   listing_state,
  to_state     listing_state NOT NULL,
  actor_customer_id UUID REFERENCES customer(customer_id),
  actor_staff_id    UUID REFERENCES staff(staff_id),
  note         TEXT CHECK (char_length(note) <= 1000),
  changed_at   TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),  -- the moment of the move, not of the transaction
  txid         BIGINT NOT NULL DEFAULT txid_current(),
  CONSTRAINT listing_change_one_actor CHECK (
    (actor_customer_id IS NULL) <> (actor_staff_id IS NULL)
  ),
  -- A send-back, a rejection and a staff take-down always say why.
  CONSTRAINT listing_change_note_required CHECK (
    note IS NOT NULL OR NOT (
      to_state IN ('changes_requested','rejected')
      OR (to_state = 'withdrawn' AND actor_staff_id IS NOT NULL)
      OR from_state = 'accepted'  -- spec 011: a staff cancellation carries its reason
    )
  )
);

CREATE INDEX idx_listing_state_change_listing ON listing_state_change(listing_id, changed_at);

CREATE OR REPLACE FUNCTION listing_state_change_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'listing history is append-only' USING ERRCODE = 'DH004';
END $$;

CREATE TRIGGER trg_listing_state_change_immutable
  BEFORE UPDATE OR DELETE ON listing_state_change
  FOR EACH ROW EXECUTE FUNCTION listing_state_change_immutable();

-- ---------------------------------------------------------------------
-- 8. Buy requests = the QUEUE
--    Multiple buyers may request one listing. They queue in arrival
--    order (queue_position). Each holds their own deposit and their own
--    locked price. On acceptance the seller takes the first active in
--    line; all other active requests are released and refunded at once.
--    A price is locked PER REQUEST at request time.
-- ---------------------------------------------------------------------
CREATE TABLE buy_request (
  buy_request_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id     UUID NOT NULL REFERENCES listing(listing_id),
  buyer_id       UUID NOT NULL REFERENCES customer(customer_id),
  state          buy_request_state NOT NULL DEFAULT 'queued',
  -- Arrival order within the listing. Assigned from listing_queue_seq under
  -- the listing lock (spec 011 research R4). Lower = earlier = ahead in line.
  queue_position INTEGER NOT NULL,
  -- Price locked for THIS buyer at request time. spec 011: locked_unit_rate is
  -- the karat's sell-side rate per gram; NULL for pure diamond (set for every
  -- listing with a karat — the table has no category column, the application
  -- sets it; research R5).
  locked_unit_rate NUMERIC(18,4),
  locked_total_price NUMERIC(18,4) NOT NULL, -- full piece price at lock
  deposit_amount NUMERIC(18,4) NOT NULL,     -- deposit.buyer_pct of locked_total_price, half-up to the piastre
  -- The ledger transaction that placed the deposit hold (available->held).
  -- spec 011: NOT NULL; the hold is posted just before the insert (lt_request_fk is deferred).
  deposit_hold_txn_id UUID NOT NULL REFERENCES ledger_transaction(ledger_txn_id),
  -- spec 011: the deposit terms accepted with this request (legal_document 'deposit_agreement').
  deposit_acceptance_id UUID NOT NULL UNIQUE REFERENCES agreement_acceptance(acceptance_id),
  requested_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Seller reply deadline: requested_at + deadline.seller_reply_hours (clock hours).
  seller_reply_deadline TIMESTAMPTZ NOT NULL,
  resolved_at    TIMESTAMPTZ,                 -- when it left 'queued' (stamped by trg_buy_request_guard)
  -- If the buyer withdrew and asked to be told when the piece is free.
  notify_when_free BOOLEAN NOT NULL DEFAULT FALSE,
  -- spec 011: when the "free again" message went out (at most once per leave).
  free_notified_at TIMESTAMPTZ,
  CONSTRAINT deposit_positive CHECK (deposit_amount > 0),
  CONSTRAINT buy_request_price_positive CHECK (locked_total_price > 0),              -- spec 011
  CONSTRAINT buy_request_resolved_shape CHECK ((state = 'queued') = (resolved_at IS NULL)), -- spec 011
  -- A buyer can hold only one ACTIVE request per listing at a time.
  -- Enforced via a partial unique index below (queued/accepted only).
  UNIQUE (listing_id, queue_position)
);

CREATE UNIQUE INDEX one_active_request_per_buyer_listing
  ON buy_request(listing_id, buyer_id)
  WHERE state IN ('queued','accepted');

CREATE INDEX idx_buy_request_listing_state ON buy_request(listing_id, state);
CREATE INDEX idx_buy_request_buyer ON buy_request(buyer_id, requested_at DESC);
-- spec 011: the seller-reply sweep.
CREATE INDEX idx_buy_request_due ON buy_request(seller_reply_deadline) WHERE state = 'queued';

-- Per-listing monotonic queue position. A dedicated table of counters
-- avoids gaps-vs-reuse ambiguity and races under concurrency.
CREATE TABLE listing_queue_seq (
  listing_id UUID PRIMARY KEY REFERENCES listing(listing_id),
  next_pos   INTEGER NOT NULL DEFAULT 1
);

-- As built by spec 011 --------------------------------------------------
-- The scope a trigger reads under (analysis H1): an elevated caller keeps its
-- own view; anyone else reads as the non-elevated 'queue' scope.
CREATE OR REPLACE FUNCTION dahab_queue_read_scope(prev text) RETURNS text AS $$
  SELECT CASE WHEN prev IN ('staff','system','bootstrap','maintenance') THEN prev ELSE 'queue' END;
$$ LANGUAGE sql IMMUTABLE;

-- Request guard (SQLSTATE DH005 -> 409 illegal_buy_request_transition): born
-- queued; moves only along buy_request_transition; listing_id, buyer_id,
-- queue_position, locked_unit_rate, locked_total_price, deposit_amount,
-- deposit_hold_txn_id, deposit_acceptance_id, requested_at and
-- seller_reply_deadline frozen; never deleted; stamps resolved_at on leaving
-- queued. In the 'queue' scope a seller moving another buyer's request may
-- change its state only (not notify_when_free / free_notified_at).
-- (Full body: database/migrations/2026_10_04_000010_create_buy_requests.php.)
CREATE TRIGGER trg_buy_request_guard
  BEFORE INSERT OR UPDATE OR DELETE ON buy_request
  FOR EACH ROW EXECUTE FUNCTION buy_request_guard();

-- Keep listing.active_queue_count in step with the queued requests. spec 011
-- (research R3): the function counts ONLY; the live <-> reserved moves are made
-- by the application's listing mover with a listing_state_change row naming
-- the actor, and trg_listing_queue_consistent (deferred) refuses a commit where
-- a live listing has queued requests or a reserved one has none.
CREATE OR REPLACE FUNCTION sync_listing_queue() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_listing UUID := COALESCE(NEW.listing_id, OLD.listing_id);
  v_count INTEGER;
  v_rows INTEGER;
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
BEGIN
  -- The count must see every buyer's request: read under the queue scope.
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

-- spec 011: deferred checks, each reading under a scope it sets itself.
--  * trg_buy_request_money (buy_request, AFTER INSERT / UPDATE OF state): a new
--    request has its deposit_hold of exactly deposit_amount on the buyer's
--    cust_held; a request that left queued for a released / withdrawn state has
--    its deposit_release (-deposit_amount on cust_held). DH005.
--  * trg_deposit_release_allowed (ledger_transaction, AFTER INSERT): a
--    deposit_release names a request that has ended, or an accepted one whose
--    order is cancelled_staff. DH005.
--  * trg_order_cancel_refunded ("order", AFTER UPDATE OF state): a
--    cancelled_staff order has its deposit_release. DH005.
--  * trg_listing_queue_consistent (listing, AFTER UPDATE OF state,
--    active_queue_count): live => 0 queued, reserved => >= 1. DH004.
--  * listing_change_recorded() (spec 010) now reads listing_state_change under
--    dahab_queue_read_scope(): a buyer's join moves the seller's listing.
-- Engine-level "exactly once": unique indexes on ledger_transaction(buy_request_id)
-- for event_kind = 'deposit_hold' and for event_kind = 'deposit_release'.
-- trg_listing_queue_guard (listing, BEFORE UPDATE): in the 'queue' scope a
-- customer who is not the seller changes nothing but state and the queue count. DH004.

-- ---------------------------------------------------------------------
-- 9. Orders (one accepted buyer's purchase)
--    Created when the seller accepts a buy_request. The final branch is
--    chosen here and MUST be one the seller named at listing. The reach-
--    branch deadline counter starts at acceptance (working hours at that
--    branch). A branch change later keeps the remaining time; only an
--    admin can change it, and may extend if the new branch is closed.
-- ---------------------------------------------------------------------
CREATE TABLE "order" (
  order_id       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_ref      TEXT UNIQUE NOT NULL,          -- e.g. DH-2026-004417
  listing_id     UUID NOT NULL REFERENCES listing(listing_id),
  buy_request_id UUID NOT NULL UNIQUE REFERENCES buy_request(buy_request_id),
  seller_id      UUID NOT NULL REFERENCES customer(customer_id),
  buyer_id       UUID NOT NULL REFERENCES customer(customer_id),
  state          order_state NOT NULL DEFAULT 'awaiting_delivery',
  -- Final chosen branch. FK-checked to be one of the listing's options
  -- via the trigger below (a plain FK can't express the subset rule).
  branch_id      SMALLINT NOT NULL REFERENCES branch(branch_id),
  accepted_by    UUID NOT NULL REFERENCES customer(customer_id), -- the seller
  accepted_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Deadline to reach the branch, in working hours, resolved to a wall
  -- clock instant using branch_hours + branch_closure at acceptance.
  reach_branch_deadline TIMESTAMPTZ NOT NULL,
  -- Locked commercial figures copied from the accepted buy_request.
  locked_total_price NUMERIC(18,4) NOT NULL,
  balance_due_deadline  TIMESTAMPTZ,            -- set after inspection pass
  collect_deadline      TIMESTAMPTZ,            -- set after balance paid
  completed_at   TIMESTAMPTZ,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- spec 011: the staff cancellation of an acceptance (research R22).
  cancelled_by   UUID REFERENCES staff(staff_id),
  cancelled_at   TIMESTAMPTZ,
  cancel_reason  TEXT CHECK (cancel_reason IS NULL OR char_length(cancel_reason) BETWEEN 10 AND 1000),
  CONSTRAINT order_cancel_shape CHECK (
    (state = 'cancelled_staff') = (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)
  )
);

-- As built by spec 011: created at acceptance with order_ref
-- 'DH-' || year (Cairo) || '-' || lpad(nextval('order_ref_seq'), 6) — the
-- number never resets.
-- As built by spec 012 (specs/012-orders): every state of the life after
-- acceptance is reached except 'disputed' (disputes are a later spec);
-- tax_invoice is not created yet. The tables below §9–§11 exist as shown.
CREATE SEQUENCE order_ref_seq;
CREATE INDEX idx_order_listing ON "order"(listing_id);

CREATE INDEX idx_order_state ON "order"(state);
CREATE INDEX idx_order_seller ON "order"(seller_id);
CREATE INDEX idx_order_buyer ON "order"(buyer_id);

-- spec 012 (research R3, R6): the seller's rate locked at acceptance
-- (sellers_get for gold, the unadjusted mid for gold with diamond, NULL for a
-- pure diamond; orders accepted before spec 012 settle on the rate current at
-- payment), the price staff propose after a stone regrade, the settlement
-- figures stored at pay-balance (spread may be negative when the locked rates
-- crossed — Dahab absorbs it), the ledger entry of each ending, the reminders.
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
  ADD COLUMN spread_amount           NUMERIC(18,4),
  ADD COLUMN seller_proceeds         NUMERIC(18,4),
  ADD COLUMN balance_amount          NUMERIC(18,4),
  ADD COLUMN settlement_txn_id       UUID REFERENCES ledger_transaction(ledger_txn_id),
  ADD COLUMN forfeit_txn_id          UUID REFERENCES ledger_transaction(ledger_txn_id),
  ADD COLUMN release_txn_id          UUID REFERENCES ledger_transaction(ledger_txn_id),
  ADD COLUMN reach_reminder_sent_at   TIMESTAMPTZ,
  ADD COLUMN balance_reminder_sent_at TIMESTAMPTZ,
  ADD CONSTRAINT order_proposal_shape CHECK (
    (proposed_price IS NULL) = (proposed_by IS NULL) AND (proposed_price IS NULL) = (proposed_at IS NULL)),
  ADD CONSTRAINT order_settlement_shape CHECK (  -- all null or all set; set only once paid
    (final_buyer_total IS NULL) = (final_seller_gross IS NULL)
    AND (final_buyer_total IS NULL) = (commission_amount IS NULL) AND (final_buyer_total IS NULL) = (vat_amount IS NULL)
    AND (final_buyer_total IS NULL) = (spread_amount IS NULL) AND (final_buyer_total IS NULL) = (seller_proceeds IS NULL)
    AND (final_buyer_total IS NULL) = (balance_amount IS NULL) AND (final_buyer_total IS NULL) = (settlement_txn_id IS NULL)
    AND (final_buyer_total IS NULL OR state IN ('ready_to_collect','completed','disputed')));

-- spec 012: the order's history (Principle I). One permanent row per move,
-- naming who made it; from_state NULL is the creation. The deferred
-- trg_order_change_recorded refuses a commit that moved an order without one.
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
CREATE TRIGGER trg_order_state_change_immutable BEFORE UPDATE OR DELETE ON order_state_change
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

-- Enforce: chosen branch must be one the seller named at listing.
CREATE OR REPLACE FUNCTION assert_branch_in_options() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM listing_branch_option
    WHERE listing_id = NEW.listing_id AND branch_id = NEW.branch_id
  ) THEN
    RAISE EXCEPTION 'branch % is not among the listing''s named options', NEW.branch_id USING ERRCODE = 'DH005'; -- spec 011
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER trg_order_branch_subset
  BEFORE INSERT OR UPDATE OF branch_id ON "order"
  FOR EACH ROW EXECUTE FUNCTION assert_branch_in_options();

-- Admin-made branch changes on an open order (kept for audit + the rule
-- that the counter keeps running; an extension is a separate deadline row).
CREATE TABLE order_branch_change (
  change_id    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  from_branch  SMALLINT NOT NULL REFERENCES branch(branch_id),
  to_branch    SMALLINT NOT NULL REFERENCES branch(branch_id),
  changed_by   UUID NOT NULL REFERENCES staff(staff_id),  -- admin only
  extended_to  TIMESTAMPTZ,                                -- if admin extended
  reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),  -- spec 012: required
  changed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT branch_change_moves CHECK (from_branch <> to_branch)
);

-- Deadline extensions (admin-granted), audited.
CREATE TABLE order_deadline_extension (
  extension_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  -- spec 014: 'decision' = the buyer's price-decision window, pushed only when a
  -- dispute frozen at weight_adjust_pending resumes (the frozen time given back).
  which        TEXT NOT NULL CHECK (which IN ('reach_branch','balance','collect','decision')),
  old_deadline TIMESTAMPTZ NOT NULL,
  new_deadline TIMESTAMPTZ NOT NULL,
  granted_by   UUID NOT NULL REFERENCES staff(staff_id),
  reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),  -- spec 012: required
  granted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT extension_moves_forward CHECK (new_deadline > old_deadline)
);
-- spec 014: why an extension was written, when it was not a plain staff grant.
-- dispute_id = the frozen time given back on resume; extension_request_id = the
-- seller's request for more time, accepted. Never both.
ALTER TABLE order_deadline_extension
  ADD COLUMN dispute_id UUID,             -- FK to dispute added in 05 §16
  ADD COLUMN extension_request_id UUID,   -- FK to order_extension_request below
  ADD CONSTRAINT extension_single_cause CHECK (dispute_id IS NULL OR extension_request_id IS NULL);

-- spec 014: the seller asks for more time to reach the branch (reason + a line);
-- staff accept with 6 / 12 / 24 / 48 working hours (written through the extend
-- above) or refuse; a waiting request lapses when the order leaves
-- awaiting_delivery. One waiting per order. Guard DH010 (05 §18).
CREATE TABLE order_extension_request (
  request_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  seller_id    UUID NOT NULL REFERENCES customer(customer_id),
  reason       TEXT NOT NULL CHECK (reason IN ('travelling','emergency','branch_closed','other')),
  detail       TEXT NOT NULL CHECK (char_length(detail) BETWEEN 10 AND 1000),
  deadline_at_request TIMESTAMPTZ NOT NULL,
  state        TEXT NOT NULL DEFAULT 'waiting' CHECK (state IN ('waiting','accepted','refused','lapsed')),
  requested_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  answered_by  UUID REFERENCES staff(staff_id),
  answered_at  TIMESTAMPTZ,
  answer_note  TEXT CHECK (answer_note IS NULL OR char_length(answer_note) BETWEEN 10 AND 1000),
  hours_granted SMALLINT CHECK (hours_granted IN (6,12,24,48)),
  extension_id UUID REFERENCES order_deadline_extension(extension_id),
  CONSTRAINT extension_request_hours CHECK ((state = 'accepted') = (hours_granted IS NOT NULL)),
  CONSTRAINT extension_request_link  CHECK ((state = 'accepted') = (extension_id IS NOT NULL)),
  CONSTRAINT extension_request_answer CHECK (
    state NOT IN ('accepted','refused') OR (answered_by IS NOT NULL AND answered_at IS NOT NULL AND answer_note IS NOT NULL))
);
CREATE UNIQUE INDEX uq_extension_request_waiting ON order_extension_request(order_id) WHERE state = 'waiting';
CREATE INDEX idx_extension_request_queue ON order_extension_request(state, requested_at);
ALTER TABLE order_deadline_extension
  ADD CONSTRAINT order_deadline_extension_request_fk
  FOREIGN KEY (extension_request_id) REFERENCES order_extension_request(request_id);

-- Seller cancellation record (counts toward suspension threshold).
-- spec 012: one per order; by_sweep = the reach-branch deadline passed; the
-- moment (clock_timestamp) is compared with customer.cancellations_reset_at.
CREATE TABLE seller_cancellation (
  cancellation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
  seller_id    UUID NOT NULL REFERENCES customer(customer_id),
  by_sweep     BOOLEAN NOT NULL,
  cancelled_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

-- ---------------------------------------------------------------------
-- 10. Inspections (IMMUTABLE results)
--     A submitted result is never edited. A correction is a NEW row that
--     supersedes an earlier one; both are kept. Enforced append-only.
--     Karat rule: ANY difference between stated and measured karat marks
--     the result as a mismatch that cancels the sale. Weight within the
--     tolerance auto-adjusts; above needs approval.
-- ---------------------------------------------------------------------
CREATE TABLE inspection_result (
  inspection_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id       UUID NOT NULL REFERENCES "order"(order_id),
  branch_id      SMALLINT NOT NULL REFERENCES branch(branch_id),
  inspected_by   UUID NOT NULL REFERENCES staff(staff_id),  -- igi_branch
  -- What the listing claimed, copied in for an immutable side-by-side.
  stated_karat   SMALLINT,
  stated_weight_g NUMERIC(10,3),
  -- What IGI measured.
  measured_karat SMALLINT,
  measured_weight_g NUMERIC(10,3),
  -- Stones (diamonds): grade fields as free-form + certificate number.
  measured_stone_grade TEXT,
  certificate_number TEXT,
  inspector_note TEXT,
  -- spec 012: the inspector's two flags (counterfeit -> fake_cancel; a stone
  -- below its claim -> stone_regrade). The outcome is never sent by a client.
  is_counterfeit BOOLEAN NOT NULL DEFAULT FALSE,
  stone_below_claim BOOLEAN NOT NULL DEFAULT FALSE,
  -- Derived outcomes, set by the settlement service at insert:
  karat_mismatch BOOLEAN NOT NULL,
  weight_diff_pct NUMERIC(8,4),
  outcome        TEXT NOT NULL CHECK (outcome IN
                   ('pass','weight_adjust','karat_cancel','stone_regrade','fake_cancel')),
  -- If this row corrects an earlier one.
  supersedes_id  UUID REFERENCES inspection_result(inspection_id),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Guard rail: the karat rule is structural, not a tunable threshold.
  CONSTRAINT karat_rule CHECK (
    karat_mismatch = (stated_karat IS DISTINCT FROM measured_karat)
  ),
  CONSTRAINT karat_mismatch_forces_cancel CHECK (
    NOT karat_mismatch OR outcome = 'karat_cancel'
  )
);

CREATE INDEX idx_inspection_order ON inspection_result(order_id);
-- spec 012: a result is corrected at most once (the latest one counts).
CREATE UNIQUE INDEX one_correction_per_result ON inspection_result(supersedes_id) WHERE supersedes_id IS NOT NULL;

-- Append-only: results are immutable; corrections supersede.
CREATE TRIGGER inspection_no_update BEFORE UPDATE OR DELETE ON inspection_result
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

-- Buyer's decision when a weight adjustment (above tolerance) or a stone
-- regrade needs approval. Refund-in-full if declined.
CREATE TABLE settlement_decision (
  decision_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  inspection_id UUID NOT NULL UNIQUE REFERENCES inspection_result(inspection_id),  -- spec 012: one per result
  buyer_accepted BOOLEAN NOT NULL,
  old_price    NUMERIC(18,4) NOT NULL,
  new_price    NUMERIC(18,4) NOT NULL,
  decided_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 11. Collection (including collection by proxy)
--     IMPORTANT — money timing: the seller is settled and commission +
--     spread + VAT are taken at BALANCE PAYMENT (see order state
--     awaiting_balance -> ready_to_collect and the money service in
--     Part 3), NOT here. Collection is a PHYSICAL handover only: it moves
--     no customer money. This reflects the resolved rule "the seller is
--     paid as soon as the buyer pays" (the only exception is the capped
--     first-sale ADVANCE: on a seller's first use, Dahab fronts the
--     proceeds early as a trust incentive — it still does NOT buy the
--     piece, and recovers the advance from the buyer's later payment;
--     gold only, promo-code-gated, capped by payout.first_sale_cap_egp).
--     Because the seller is already paid, a buyer who
--     pays and never collects does not hold up the seller: the piece simply
--     waits at IGI (listing state uncollected_expired).
-- ---------------------------------------------------------------------
CREATE TABLE collection (
  collection_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
  code_hash    TEXT NOT NULL,                   -- collection code, HMAC with the app key
  -- spec 012 (research R10): also kept encrypted so the buyer reads it in their
  -- own order; never in a list, never to staff. Five wrong codes lock 15 min.
  code_encrypted TEXT NOT NULL,
  failed_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
  locked_until TIMESTAMPTZ,
  -- Proxy collection: buyer authorises another person; Dahab does not
  -- verify the relationship. The authorisation acceptance is in
  -- agreement_acceptance; here we hold the proxy's uploaded ID ref.
  is_proxy     BOOLEAN NOT NULL DEFAULT FALSE,
  proxy_name   TEXT,
  proxy_phone  TEXT,
  proxy_id_storage_ref TEXT,
  collected_at TIMESTAMPTZ,
  handover_by  UUID REFERENCES staff(staff_id), -- igi_branch confirms
  CONSTRAINT proxy_needs_details CHECK (
    NOT is_proxy OR (proxy_name IS NOT NULL AND proxy_id_storage_ref IS NOT NULL)
  )
);
-- spec 014: the buyer names the proxy before the counter (customer endpoint);
-- is_proxy = a proxy is authorised now (cleared on removal). The handover with
-- collector = proxy requires the ID check and records it. Proxy fields never
-- change once collected_at is set (trigger). The seller's payload never
-- carries them.
ALTER TABLE collection
  ADD COLUMN proxy_acceptance_id UUID REFERENCES agreement_acceptance(acceptance_id),
  ADD COLUMN proxy_named_at TIMESTAMPTZ,
  ADD COLUMN collected_by_proxy BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN proxy_id_checked_by UUID REFERENCES staff(staff_id),
  ADD CONSTRAINT proxy_named_with_acceptance CHECK (
    NOT is_proxy OR (proxy_phone IS NOT NULL AND proxy_acceptance_id IS NOT NULL AND proxy_named_at IS NOT NULL)),
  ADD CONSTRAINT proxy_collection_checked CHECK (
    NOT collected_by_proxy OR (is_proxy AND proxy_id_checked_by IS NOT NULL AND collected_at IS NOT NULL));

-- Return of a piece to the seller after the buyer failed to pay the
-- balance. The seller collects the physical piece and receives 50% of the
-- deposit (deposit.seller_forfeit_share_pct) as agreed compensation. The
-- compensation ledger posting (event_kind = deposit_forfeit) is written by
-- the money service when the buyer no-pay is confirmed; this row records
-- the physical handover of the returned piece back to the seller.
CREATE TABLE seller_return (
  seller_return_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL UNIQUE REFERENCES "order"(order_id),
  listing_id   UUID NOT NULL REFERENCES listing(listing_id),
  seller_id    UUID NOT NULL REFERENCES customer(customer_id),
  branch_id    SMALLINT NOT NULL REFERENCES branch(branch_id),
  code_hash    TEXT NOT NULL,                   -- seller collection code, HMAC
  code_encrypted TEXT NOT NULL,                 -- spec 012: the seller reads it in their order
  failed_attempts SMALLINT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
  locked_until TIMESTAMPTZ,
  -- The deadline for the seller to collect the returned piece: CALENDAR
  -- weeks from deadline.seller_return_weeks (Part 3 §1.3; spec 012).
  return_deadline TIMESTAMPTZ NOT NULL,
  collected_at TIMESTAMPTZ,
  handover_by  UUID REFERENCES staff(staff_id), -- igi_branch confirms
  relisted_at  TIMESTAMPTZ,                     -- spec 012: the seller relisted instead
  -- The compensation transaction (50% of the deposit) paid to the seller.
  compensation_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_seller_return_deadline ON seller_return(return_deadline)
  WHERE collected_at IS NULL AND relisted_at IS NULL;
-- spec 012: a return ends once (collected or relisted). A returned piece is
-- opened after a no-pay (with the forfeit compensation) or an inspection
-- cancel / declined adjustment (none).
ALTER TABLE seller_return ADD CONSTRAINT seller_return_one_end
  CHECK (NOT (collected_at IS NOT NULL AND relisted_at IS NOT NULL));

-- spec 012, deferred checks (each reading under a scope it sets itself):
--  * trg_order_change_recorded ("order", AFTER UPDATE OF state): the move has
--    its order_state_change row in the same transaction. DH006.
--  * trg_order_money ("order", AFTER UPDATE OF state; replaces spec 011's
--    trg_order_cancel_refunded): cancelled_staff / cancelled_seller /
--    cancelled_inspection need the request's deposit_release for the order;
--    cancelled_buyer_nopay a deposit_forfeit and no release; ready_to_collect
--    a balance_payment. DH006.
-- The order guard (05_schema_security.sql) moved to its own SQLSTATE DH006.

-- ---------------------------------------------------------------------
-- 12. Payout accounts and withdrawals
--     Money leaves only to an account in the customer's own name. A
--     payout-account change cancels any in-flight withdrawal and pauses
--     new withdrawals for the setting window (48h). Every withdrawal is
--     reviewed by a person before release.
--     As built by spec 013 (specs/013-withdrawals): several accounts per
--     customer, exactly one in use; making a different one in use (not the
--     first time ever) cancels every withdrawal not yet released and opens
--     the pause; a refused account is final; 'removing' keeps its in-use
--     flag until its in-flight withdrawals end. The email second-check is a
--     withdrawal_confirmation row. `on_hold_account_change` and `settled`
--     are not reached. Migration 2026_10_06_000010_create_withdrawals.php.
-- ---------------------------------------------------------------------
CREATE TABLE payout_account (
  payout_account_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  account_name TEXT NOT NULL CHECK (char_length(account_name) BETWEEN 3 AND 120), -- must match the ID
  bank_name    TEXT NOT NULL CHECK (char_length(bank_name) BETWEEN 2 AND 80),
  -- spec 013: an Egyptian IBAN (mod-97 checked by the API) or 8-20 digits, spaces removed.
  account_number_or_iban TEXT NOT NULL CHECK (account_number_or_iban ~ '^(EG[0-9]{27}|[0-9]{8,20})$'),
  state        payout_account_state NOT NULL DEFAULT 'pending_review',
  is_in_use    BOOLEAN NOT NULL DEFAULT FALSE,           -- spec 013: withdrawals go here
  name_checked_by UUID REFERENCES staff(staff_id),       -- the verifier or the refuser
  name_checked_at TIMESTAMPTZ,
  -- When this account was activated/changed; drives the withdrawal pause.
  activated_at TIMESTAMPTZ,
  refusal_reason TEXT CHECK (refusal_reason IN ('name_mismatch','name_shortened','not_in_customer_name','details_invalid','other')), -- spec 013
  refusal_note   TEXT CHECK (char_length(refusal_note) <= 1000),   -- staff only
  removal_requested_at TIMESTAMPTZ,                      -- spec 013
  removed_at   TIMESTAMPTZ,                              -- spec 013
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT payout_in_use_needs_usable CHECK (NOT is_in_use OR state IN ('active','removing')),
  CONSTRAINT payout_refusal_shape CHECK ((state = 'refused') = (refusal_reason IS NOT NULL)),
  CONSTRAINT payout_checked_shape CHECK ((name_checked_by IS NULL) = (name_checked_at IS NULL)),
  CONSTRAINT payout_active_checked CHECK (state NOT IN ('active','removing','refused') OR name_checked_by IS NOT NULL)
);

CREATE INDEX idx_payout_customer ON payout_account(customer_id);
CREATE UNIQUE INDEX one_payout_in_use_per_customer ON payout_account(customer_id) WHERE is_in_use;  -- spec 013
CREATE INDEX idx_payout_review ON payout_account(created_at, payout_account_id) WHERE state = 'pending_review';

-- A per-customer pause window opened by a payout-account change. The
-- withdrawal service refuses new requests while now() < pause_until
-- (spec 013: open withdrawals are cancelled at the change, so release does
-- not re-check it). withdrawals:sweep stamps ended_notified_at and tells the
-- customer once the window is over.
CREATE TABLE withdrawal_pause (
  pause_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  opened_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  pause_until  TIMESTAMPTZ NOT NULL,            -- opened_at + setting hours
  triggered_by_account UUID REFERENCES payout_account(payout_account_id),
  ended_notified_at TIMESTAMPTZ,                -- spec 013
  CONSTRAINT pause_window_valid CHECK (pause_until > opened_at)
);

CREATE INDEX idx_pause_customer_until ON withdrawal_pause(customer_id, pause_until);
CREATE INDEX idx_pause_to_announce ON withdrawal_pause(pause_until) WHERE ended_notified_at IS NULL;

-- The history behind the customer's "Recent changes" (spec 013, new):
-- one row per change, naming exactly one actor. Append-only.
CREATE TABLE payout_account_change (
  change_id    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  payout_account_id UUID NOT NULL REFERENCES payout_account(payout_account_id),
  kind         TEXT NOT NULL CHECK (kind IN ('added','verified','refused','in_use','removal_scheduled','kept','removed','request_cancelled')),
  actor_customer_id UUID REFERENCES customer(customer_id),
  actor_staff_id    UUID REFERENCES staff(staff_id),
  pause_id     UUID REFERENCES withdrawal_pause(pause_id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  CONSTRAINT payout_change_one_actor CHECK ((actor_customer_id IS NULL) <> (actor_staff_id IS NULL))
);
CREATE INDEX idx_payout_change_customer ON payout_account_change(customer_id, change_id DESC);
CREATE TRIGGER trg_payout_account_change_immutable BEFORE UPDATE OR DELETE ON payout_account_change
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

CREATE TABLE withdrawal (
  withdrawal_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  withdrawal_no BIGINT GENERATED BY DEFAULT AS IDENTITY UNIQUE,   -- spec 013: shown as WD-{n}
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  payout_account_id UUID NOT NULL REFERENCES payout_account(payout_account_id),
  amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
  state        withdrawal_state NOT NULL DEFAULT 'requested',
  -- The three ledger entries (event_kind = withdrawal), each at most once (spec 013):
  hold_txn_id    UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id), -- available -> held
  release_txn_id UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),          -- held -> bank
  return_txn_id  UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),          -- held -> available
  reviewed_by  UUID REFERENCES staff(staff_id),   -- finance/ceo
  review_started_at TIMESTAMPTZ,
  -- The hold flag (spec 013): a reason, what the customer is told, a staff note.
  held_at      TIMESTAMPTZ,
  held_by      UUID REFERENCES staff(staff_id),
  hold_reason  TEXT CHECK (hold_reason IN ('name_mismatch','account_changed_recently','identity_pending','money_in_straight_out','other')),
  hold_message TEXT CHECK (char_length(hold_message) BETWEEN 3 AND 500),
  hold_note    TEXT CHECK (char_length(hold_note) BETWEEN 3 AND 1000),
  rejection_reason TEXT CHECK (rejection_reason IN ('account_not_in_name','money_in_straight_out','identity_unconfirmed','customer_request','other')),
  rejection_note   TEXT CHECK (char_length(rejection_note) BETWEEN 3 AND 1000),
  -- The transfer a person sent at Dahab's bank, recorded at release (spec 013).
  bank_txn_number    TEXT CHECK (char_length(bank_txn_number) BETWEEN 3 AND 64),
  transfer_reference TEXT CHECK (char_length(transfer_reference) <= 64),
  value_date   DATE,
  cancelled_by_change BOOLEAN NOT NULL DEFAULT FALSE,
  requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  released_at  TIMESTAMPTZ,
  settled_at   TIMESTAMPTZ,
  ended_at     TIMESTAMPTZ,
  CONSTRAINT withdrawal_hold_shape CHECK (
    (held_at IS NULL) = (held_by IS NULL) AND (held_at IS NULL) = (hold_reason IS NULL)
    AND (held_at IS NULL) = (hold_message IS NULL) AND (held_at IS NULL) = (hold_note IS NULL)
  ),
  -- The hold record survives a reject or cancel; "on hold" is under_review
  -- with held_at set; a held withdrawal is never released.
  CONSTRAINT withdrawal_hold_state CHECK (held_at IS NULL OR state IN ('under_review','rejected','cancelled')),
  CONSTRAINT withdrawal_rejection_shape CHECK (
    (state = 'rejected') = (rejection_reason IS NOT NULL) AND (rejection_reason IS NULL) = (rejection_note IS NULL)
  ),
  CONSTRAINT withdrawal_release_shape CHECK (
    state NOT IN ('released','settled')
    OR (bank_txn_number IS NOT NULL AND release_txn_id IS NOT NULL AND released_at IS NOT NULL AND reviewed_by IS NOT NULL)
  ),
  CONSTRAINT withdrawal_ended_shape CHECK ((state IN ('requested','under_review','on_hold_account_change')) = (ended_at IS NULL))
);

CREATE INDEX idx_withdrawal_customer_state ON withdrawal(customer_id, state);
CREATE INDEX idx_withdrawal_queue ON withdrawal(requested_at, withdrawal_id) WHERE state IN ('requested','under_review');
CREATE INDEX idx_withdrawal_account ON withdrawal(payout_account_id, state);
CREATE INDEX idx_withdrawal_requested ON withdrawal(requested_at, withdrawal_id);

-- The email second-check (Part 1 §2.4; spec 013, new): single use, tied to
-- one customer, amount and account, valid 30 minutes. Only the HMAC of the
-- emailed token is kept. One open (unused, not replaced) per customer.
CREATE TABLE withdrawal_confirmation (
  confirmation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  payout_account_id UUID NOT NULL REFERENCES payout_account(payout_account_id),
  amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
  token_hash   TEXT NOT NULL UNIQUE,
  expires_at   TIMESTAMPTZ NOT NULL,
  confirmed_at TIMESTAMPTZ,
  used_at      TIMESTAMPTZ,
  replaced_at  TIMESTAMPTZ,
  withdrawal_id UUID UNIQUE REFERENCES withdrawal(withdrawal_id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT confirmation_used_after_confirmed CHECK (used_at IS NULL OR confirmed_at IS NOT NULL),
  CONSTRAINT confirmation_used_shape CHECK ((used_at IS NULL) = (withdrawal_id IS NULL))
);
CREATE UNIQUE INDEX one_open_confirmation_per_customer ON withdrawal_confirmation(customer_id)
  WHERE used_at IS NULL AND replaced_at IS NULL;

-- spec 013: the payout-account declaration ticked when adding an account
-- (agreement_acceptance context 'payout_account').
-- INSERT INTO legal_document (code, version, ...) VALUES ('payout_account_declaration', 1, ...);

-- Now wire the ledger's business-object FKs (declared in Part 2).
ALTER TABLE ledger_transaction
  ADD CONSTRAINT lt_listing_fk    FOREIGN KEY (listing_id)     REFERENCES listing(listing_id),
  ADD CONSTRAINT lt_order_fk      FOREIGN KEY (order_id)       REFERENCES "order"(order_id),
  -- spec 011: deferred, so a join posts the hold before inserting the request it names.
  ADD CONSTRAINT lt_request_fk    FOREIGN KEY (buy_request_id) REFERENCES buy_request(buy_request_id) DEFERRABLE INITIALLY DEFERRED,
  -- spec 013: deferred, so a request posts the hold before inserting the withdrawal it names.
  ADD CONSTRAINT lt_withdrawal_fk FOREIGN KEY (withdrawal_id)  REFERENCES withdrawal(withdrawal_id) DEFERRABLE INITIALLY DEFERRED;

-- Tax invoice, issued automatically at completion, filed with ETA.
CREATE TABLE tax_invoice (
  invoice_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  party_role   party_role NOT NULL,             -- one to each side
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  net_amount   NUMERIC(18,4) NOT NULL,
  vat_amount   NUMERIC(18,4) NOT NULL,
  gross_amount NUMERIC(18,4) NOT NULL,
  eta_reference TEXT,                            -- e-invoicing system id
  issued_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  storage_ref  TEXT,
  UNIQUE (order_id, party_role)
);
