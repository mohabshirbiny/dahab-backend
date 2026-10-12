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
-- tax_invoice is built by spec 016 (see below). The tables below §9–§11 exist as shown.
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

-- Tax invoice. As built by spec 016 (specs/016-tax-invoices): issued
-- automatically inside the pay-balance settlement (Part 2 §7), never at
-- handover and never by hand; one per order per party; NOT filed with the
-- Egyptian Tax Authority (Part 4 §4 is not integrated: eta_reference stays
-- NULL). Seller: net = the commission posted, vat = the VAT posted.
-- Buyer: net = gross = the buyer total, VAT 0. party_role is TEXT + CHECK
-- (the party_role enum type above was never created by the build).
-- Guards on SQLSTATE DH012 (bodies in
-- database/migrations/2026_10_09_000010_create_tax_invoices.php):
--   tax_document_guard()      append-only; issuer, party, storage_ref and
--                             document_at may go from NULL to a value once
--   tax_invoice_reconciled()  deferred: the invoice equals its order's
--                             settlement columns and ledger lines
-- Forced RLS: a customer reads their own; written only by the buyer's payment
-- (scope 'order') or an elevated scope.
CREATE TABLE tax_invoice (
  invoice_id    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  invoice_no    TEXT NOT NULL UNIQUE,          -- order_ref || '-S' | '-B'
  order_id      UUID NOT NULL REFERENCES "order"(order_id),
  party_role    TEXT NOT NULL CHECK (party_role IN ('seller','buyer')),  -- one to each side
  customer_id   UUID NOT NULL REFERENCES customer(customer_id),
  net_amount    NUMERIC(18,4) NOT NULL CHECK (net_amount > 0),
  vat_amount    NUMERIC(18,4) NOT NULL CHECK (vat_amount >= 0),
  gross_amount  NUMERIC(18,4) NOT NULL,
  vat_rate      NUMERIC(6,3) NOT NULL CHECK (vat_rate >= 0),  -- vat.pct at settlement (0 for the buyer)
  lines         JSONB NOT NULL,                -- snapshot: piece, weight, rate, gold value, making, totals
  issuer        JSONB,                         -- Dahab's details (config/dahab-invoices.php), set once
  party         JSONB,                         -- {full_name, display_ref}, set once by the document job
  eta_reference TEXT,                          -- e-invoicing system id (NULL: not integrated)
  issued_at     TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  storage_ref   TEXT,                          -- encrypted bilingual PDF, set once
  document_at   TIMESTAMPTZ,
  CONSTRAINT tax_invoice_one_per_party UNIQUE (order_id, party_role),
  CONSTRAINT invoice_gross CHECK (gross_amount = net_amount + vat_amount),
  CONSTRAINT invoice_vat_on_seller_only CHECK (party_role = 'seller' OR (vat_amount = 0 AND vat_rate = 0)),
  CONSTRAINT invoice_no_matches_party CHECK (right(invoice_no, 2) = CASE party_role WHEN 'seller' THEN '-S' ELSE '-B' END),
  CONSTRAINT invoice_storage_pair CHECK ((storage_ref IS NULL) = (document_at IS NULL))
);
CREATE INDEX idx_tax_invoice_issued ON tax_invoice(issued_at DESC, invoice_id);
CREATE INDEX idx_tax_invoice_customer ON tax_invoice(customer_id, issued_at DESC);

-- Credit note (spec 016). A registered invoice is never edited; a correction
-- is a credit note against a SELLER invoice, by hand (invoice.correct), with
-- a reason, refunding Dahab's commission and VAT in one balanced
-- 'credit_note' ledger entry. Numbered 'CN-' || year (Cairo) || '-' ||
-- lpad(nextval('credit_note_no_seq'), 6) — never resets. Guards (DH012):
--   credit_note_cap()       before insert, invoice row locked: a seller
--                           invoice only; the sum never above its gross
--   credit_note_recorded()  deferred: the exact refund entry exists
--   tax_document_guard()    append-only; issuer, storage_ref, document_at once
-- Forced RLS: a customer reads their own; written only by an elevated scope.
CREATE SEQUENCE credit_note_no_seq;
CREATE TABLE credit_note (
  credit_note_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  credit_note_no TEXT NOT NULL UNIQUE,
  invoice_id     UUID NOT NULL REFERENCES tax_invoice(invoice_id),
  customer_id    UUID NOT NULL REFERENCES customer(customer_id),
  net_amount     NUMERIC(18,4) NOT NULL CHECK (net_amount > 0),
  vat_amount     NUMERIC(18,4) NOT NULL CHECK (vat_amount >= 0),   -- round½↑(gross × rate / (100 + rate), 4)
  gross_amount   NUMERIC(18,4) NOT NULL CHECK (gross_amount > 0),
  reason         TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),
  issued_by      UUID NOT NULL REFERENCES staff(staff_id),
  ledger_txn_id  UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
  issuer         JSONB,
  issued_at      TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  storage_ref    TEXT,
  document_at    TIMESTAMPTZ,
  CONSTRAINT credit_note_gross CHECK (gross_amount = net_amount + vat_amount),
  CONSTRAINT credit_note_storage_pair CHECK ((storage_ref IS NULL) = (document_at IS NULL))
);
CREATE INDEX idx_credit_note_issued ON credit_note(issued_at DESC, credit_note_id);
CREATE INDEX idx_credit_note_invoice ON credit_note(invoice_id);

-- =====================================================================
-- spec 017 — the customer account (specs/017-customer-account/data-model.md)
-- =====================================================================

-- What opened a withdrawal pause; a contact change names no payout account.
ALTER TABLE withdrawal_pause
  ADD COLUMN trigger_kind TEXT NOT NULL DEFAULT 'payout_account'
    CHECK (trigger_kind IN ('payout_account','phone_change','email_change')),
  ADD CONSTRAINT withdrawal_pause_trigger_account CHECK (trigger_kind = 'payout_account' OR triggered_by_account IS NULL);

-- Closing an account withdraws every piece not in a sale; a draft has no listed_at.
INSERT INTO listing_transition (from_state, to_state, note) VALUES
  ('draft','withdrawn','account closed'), ('in_review','withdrawn','account closed'),
  ('changes_requested','withdrawn','account closed'), ('suspended_hold','withdrawn','account closed');
-- listing_listed_shape: listed_at IS NOT NULL OR state IN ('draft','in_review','changes_requested','rejected','withdrawn')

-- Listing reports: the seller never sees the reporter. Leaves open once (DH015).
CREATE SEQUENCE listing_report_no_seq;
CREATE TABLE listing_report (
  report_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  report_no   BIGINT NOT NULL UNIQUE DEFAULT nextval('listing_report_no_seq'),   -- RPT-n
  listing_id  UUID NOT NULL REFERENCES listing(listing_id),
  reporter_id UUID NOT NULL REFERENCES customer(customer_id),
  reason      TEXT NOT NULL CHECK (reason IN ('photos_not_genuine','price_or_weight_wrong',
                'description_mismatch','not_theirs_to_sell','off_platform_dealing','other')),
  note        TEXT CHECK (char_length(note) <= 1000),
  state       TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','dismissed','actioned','listing_gone')),
  handled_by  UUID REFERENCES staff(staff_id),
  handled_at  TIMESTAMPTZ,
  staff_note  TEXT CHECK (char_length(staff_note) <= 1000),
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT listing_report_handled CHECK ((state = 'open') = (handled_at IS NULL)),
  CONSTRAINT listing_report_staff CHECK (state NOT IN ('dismissed','actioned') OR handled_by IS NOT NULL),
  CONSTRAINT listing_report_dismiss_note CHECK (state <> 'dismissed' OR staff_note IS NOT NULL)
);
CREATE UNIQUE INDEX uq_listing_report_open ON listing_report (listing_id, reporter_id) WHERE state = 'open';
-- Forced RLS: the reporter inserts and reads their own; staff elevated.

-- spec 018 — after collection: the free relist and the rating (specs/018-after-collection/data-model.md)
-- The window of the buyer's free relist, stored by the staff handover (deadline.free_relist_working_hours of
-- working time on the order's branch). NULL = no offer (setting 0, hours unknown, or a sale of a free relist).
ALTER TABLE collection
  ADD COLUMN free_relist_until TIMESTAMPTZ,
  ADD CONSTRAINT collection_free_relist_shape CHECK (
    free_relist_until IS NULL OR (collected_at IS NOT NULL AND free_relist_until > collected_at));

-- The link on the listing IS the 0% commission waiver: set at INSERT, never changes, one per origin order.
ALTER TABLE listing
  ADD COLUMN relisted_from_order_id UUID REFERENCES "order"(order_id);
CREATE UNIQUE INDEX one_free_relist_per_order ON listing (relisted_from_order_id)
  WHERE relisted_from_order_id IS NOT NULL;

INSERT INTO listing_transition (from_state, to_state, note)
  VALUES ('draft', 'live', 'free relist: no review');

-- One rating per party per order, immutable.
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
-- Forced RLS: a customer reads and writes only their own row; staff read through rating.view.

-- =====================================================================
-- spec 022 — change the price of a live piece (specs/022-live-listing-price-change/data-model.md)
-- listing_guard() is NOT redefined: two new triggers on listing guard the price columns (DH023).
-- =====================================================================
CREATE TABLE listing_price_change (
  change_id         BIGSERIAL PRIMARY KEY,
  listing_id        UUID NOT NULL REFERENCES listing(listing_id),
  field             TEXT NOT NULL CHECK (field IN ('making_charge_per_g','asking_price')),
  old_value         NUMERIC(18,4) NOT NULL CHECK (old_value >= 0 AND old_value = round(old_value, 2)),
  new_value         NUMERIC(18,4) NOT NULL CHECK (new_value >= 0 AND new_value = round(new_value, 2)),
  actor_customer_id UUID REFERENCES customer(customer_id),
  actor_staff_id    UUID REFERENCES staff(staff_id),
  changed_at        TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  txid              BIGINT NOT NULL DEFAULT txid_current(),   -- ties the row to the change (trg_listing_price_recorded)
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

-- Forced RLS: the seller reads and inserts their own piece's rows (as themselves); staff read.
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

-- Is this piece's price fixed? True while a market-maker approval exists. Read with the system scope,
-- then the caller's scope is put back; a boolean about one listing and nothing else. Spec 023 extends it.
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

-- A price write is allowed only in draft / changes_requested / live, never together with a state move,
-- and never on a piece approved for market makers.
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

-- A change made while live has its listing_price_change row in the same transaction (checked at commit).
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

-- spec 023 — first-sale advance, customer-side codes, referral, market maker terms (see the migrations 2026_10_16_0000*0).

-- ---------------------------------------------------------------------------
-- spec 023 M1 — promo code terms, the guard DH020 extended, identity_document.id_number_hash, the 16 settings
-- (specs/023-first-sale-and-referral/data-model.md; source: database/migrations/2026_10_16_000010_promo_code_terms.php)
-- ---------------------------------------------------------------------------

ALTER TABLE promo_code
  ADD COLUMN audience           TEXT NOT NULL DEFAULT 'account',
  ADD COLUMN commission_mode    TEXT NOT NULL DEFAULT 'none',
  ADD COLUMN commission_percent NUMERIC(7,4),
  ADD COLUMN use_limit          TEXT NOT NULL DEFAULT 'unlimited',
  ADD COLUMN first_sale_only    BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN pays_advance       BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN min_listed_days    SMALLINT,
  ADD COLUMN requires_approval  BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN starts_on          DATE,
  ADD COLUMN ends_on            DATE;

-- Every spec 019 code is a market-maker code that waives the commission.
SELECT set_config('app.rls_scope', 'maintenance', true);
UPDATE promo_code SET commission_mode = CASE WHEN commission_waived THEN 'waive' ELSE 'none' END;

ALTER TABLE promo_code DROP CONSTRAINT promo_code_kind_check;
ALTER TABLE promo_code
  ADD CONSTRAINT promo_code_kind_check CHECK (kind IN ('first_sale','market_maker','discount')),
  ADD CONSTRAINT promo_code_audience_check CHECK (audience IN ('account','anyone')),
  ADD CONSTRAINT promo_code_audience_tied CHECK ((audience = 'account') = (tied_customer_id IS NOT NULL)),
  ADD CONSTRAINT promo_code_mode_check CHECK (commission_mode IN ('waive','percent','none')),
  ADD CONSTRAINT promo_code_mode_waived CHECK ((commission_mode = 'waive') = commission_waived),
  ADD CONSTRAINT promo_code_percent_shape CHECK ((commission_mode = 'percent') = (commission_percent IS NOT NULL)),
  ADD CONSTRAINT promo_code_percent_range CHECK (commission_percent IS NULL OR (commission_percent > 0 AND commission_percent < 100)),
  ADD CONSTRAINT promo_code_use_limit_check CHECK (use_limit IN ('unlimited','once','monthly')),
  ADD CONSTRAINT promo_code_advance_kind CHECK ((kind = 'first_sale') = pays_advance),
  ADD CONSTRAINT promo_code_advance_first_sale CHECK (NOT pays_advance OR first_sale_only),
  ADD CONSTRAINT promo_code_listed_days CHECK (min_listed_days IS NULL OR min_listed_days BETWEEN 0 AND 3650),
  ADD CONSTRAINT promo_code_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on),
  ADD CONSTRAINT promo_code_kind_terms CHECK (
    (kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread))
    AND (kind = 'market_maker' OR NOT gives_spread)
    AND (kind <> 'discount' OR commission_mode <> 'none'));

-- A code is never deleted, and only its cap and its on/off may change (DH020, now with the new terms).
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
     OR NEW.audience IS DISTINCT FROM OLD.audience
     OR NEW.commission_mode IS DISTINCT FROM OLD.commission_mode
     OR NEW.commission_percent IS DISTINCT FROM OLD.commission_percent
     OR NEW.use_limit IS DISTINCT FROM OLD.use_limit
     OR NEW.first_sale_only IS DISTINCT FROM OLD.first_sale_only
     OR NEW.pays_advance IS DISTINCT FROM OLD.pays_advance
     OR NEW.min_listed_days IS DISTINCT FROM OLD.min_listed_days
     OR NEW.requires_approval IS DISTINCT FROM OLD.requires_approval
     OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
     OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
     OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'only the cap and the on/off of a promo code may change' USING ERRCODE = 'DH020';
  END IF;
  RETURN NEW;
END $$;

-- A row written the spec 019 way (`commission_waived` only) reads as `waive`.
CREATE OR REPLACE FUNCTION promo_code_defaults() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.commission_waived AND NEW.commission_mode = 'none' THEN
    NEW.commission_mode := 'waive';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER trg_promo_code_defaults BEFORE INSERT ON promo_code
  FOR EACH ROW EXECUTE FUNCTION promo_code_defaults();

-- Seller-side uses: the listing the code was applied to and the person it counts against.
ALTER TABLE promo_code_use
  ADD COLUMN listing_id UUID REFERENCES listing(listing_id),
  ADD COLUMN id_key     TEXT,
  ADD COLUMN credit_id  UUID,
  ADD CONSTRAINT promo_code_use_one_target CHECK (num_nonnulls(buy_request_id, listing_id) <= 1);
CREATE INDEX idx_promo_code_use_key ON promo_code_use (code, id_key) WHERE allowed;
CREATE INDEX idx_promo_code_use_listing ON promo_code_use (listing_id) WHERE listing_id IS NOT NULL;

-- The reviewer keys the national-ID number at approval; only its HMAC is kept (research R8).
ALTER TABLE identity_document ADD COLUMN id_number_hash TEXT;

INSERT INTO setting (setting_key, value_bool, unit, description) VALUES
  ('payout.first_sale_enabled',               FALSE, 'bool', 'Pay a first-time gold seller ahead, before the buyer pays (Finance to sign off the ledger first)'),
  ('payout.first_sale_comp_offsets_writeoff', FALSE, 'bool', 'When an advance was paid and the buyer never pays, the seller''s share of the forfeited deposit goes to Dahab'),
  ('promo.seller_codes_enabled',              FALSE, 'bool', 'Customers may use seller-side commission codes on the sell form'),
  ('referral.enabled',                        FALSE, 'bool', 'Invite a friend and referral commission credits work'),
  ('marketmaker.require_approval',            TRUE,  'bool', 'A market-maker purchase needs the piece approved first');
INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
  ('payout.first_sale_exposure_ceiling_egp', 1000000, 'egp',     'Most EGP out in first-sale advances at once, across everyone; above it the advance is skipped'),
  ('referral.friend_percent_off',            50,      'percent', 'The friend''s share off the commission on the sales a referral covers'),
  ('referral.inviter_reward_egp',            200,     'egp',     'Commission credit the inviter earns when the friend completes a sale'),
  ('referral.friend_sales_covered',          1,       'count',   'How many of the friend''s sales the percentage off applies to'),
  ('referral.credit_expiry_days',            90,      'days',    'How long a referral commission credit lasts'),
  ('referral.max_uses_per_code',             10,      'count',   'Friends who may join with one invitation code'),
  ('referral.max_invitations_per_account',   100,     'count',   'Share and Copy taps counted per account'),
  ('referral.max_per_device',                1,       'count',   'Referral redemptions allowed from one device'),
  ('referral.min_friend_sale_egp',           5000,    'egp',     'Smallest sale of the friend that earns the inviter''s reward'),
  ('marketmaker.making_charge_warn_pct',     30,      'percent', 'A making charge above this share of the gold value shows red on the approvals page'),
  ('listing.view_retention_days',            90,      'days',    'How long a listing view is kept');

-- ---------------------------------------------------------------------------
-- spec 023 M2 — commission credits and their events, listing_promo, referral, the cost columns on "order", DH024/DH026/DH027
-- (specs/023-first-sale-and-referral/data-model.md; source: database/migrations/2026_10_16_000020_commission_credit.php)
-- ---------------------------------------------------------------------------

CREATE TABLE referral_code (
  customer_id  UUID PRIMARY KEY REFERENCES customer(customer_id),
  code         TEXT NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9]{8}$'),
  shares_count INT NOT NULL DEFAULT 0 CHECK (shares_count >= 0),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE referral (
  referral_id        UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  inviter_id         UUID NOT NULL REFERENCES customer(customer_id),
  friend_id          UUID NOT NULL REFERENCES customer(customer_id),
  code               TEXT NOT NULL,
  listing_id         UUID REFERENCES listing(listing_id),
  device_fingerprint TEXT,
  id_key             TEXT,
  state              TEXT NOT NULL DEFAULT 'joined' CHECK (state IN ('joined','rewarded','void')),
  joined_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  reward_order_id    UUID REFERENCES "order"(order_id),
  rewarded_at        TIMESTAMPTZ,
  void_reason        TEXT,
  friend_credit_id   UUID,
  inviter_credit_id  UUID,
  CONSTRAINT referral_not_self CHECK (inviter_id <> friend_id),
  CONSTRAINT referral_one_per_friend UNIQUE (friend_id),
  CONSTRAINT referral_rewarded_shape CHECK (state <> 'rewarded'
    OR (reward_order_id IS NOT NULL AND rewarded_at IS NOT NULL AND inviter_credit_id IS NOT NULL)),
  CONSTRAINT referral_void_shape CHECK ((state = 'void') = (void_reason IS NOT NULL))
);
CREATE INDEX idx_referral_inviter ON referral (inviter_id, state);
CREATE INDEX idx_referral_device ON referral (device_fingerprint) WHERE device_fingerprint IS NOT NULL;

CREATE TABLE commission_credit (
  credit_id       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id     UUID NOT NULL REFERENCES customer(customer_id),
  source          TEXT NOT NULL CHECK (source IN ('promo_code','referral_friend','referral_inviter')),
  kind            TEXT NOT NULL CHECK (kind IN ('waive','percent','amount')),
  percent_off     NUMERIC(7,4),
  amount_egp      NUMERIC(18,4),
  amount_left_egp NUMERIC(18,4),
  uses_total      SMALLINT,
  uses_left       SMALLINT,
  listing_id      UUID REFERENCES listing(listing_id),
  promo_code      TEXT REFERENCES promo_code(code),
  referral_id     UUID REFERENCES referral(referral_id),
  status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','exhausted','expired','voided')),
  granted_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at      TIMESTAMPTZ,
  ended_at        TIMESTAMPTZ,
  CONSTRAINT credit_percent_shape CHECK ((kind = 'percent') = (percent_off IS NOT NULL)),
  CONSTRAINT credit_percent_range CHECK (percent_off IS NULL OR (percent_off > 0 AND percent_off < 100)),
  CONSTRAINT credit_amount_shape CHECK ((kind = 'amount') = (amount_egp IS NOT NULL AND amount_left_egp IS NOT NULL)),
  CONSTRAINT credit_amount_range CHECK (amount_egp IS NULL OR (amount_egp > 0 AND amount_left_egp >= 0 AND amount_left_egp <= amount_egp)),
  CONSTRAINT credit_uses_shape CHECK ((kind <> 'amount') = (uses_total IS NOT NULL AND uses_left IS NOT NULL)),
  CONSTRAINT credit_uses_range CHECK (uses_total IS NULL OR (uses_total >= 1 AND uses_left >= 0 AND uses_left <= uses_total)),
  CONSTRAINT credit_source_ref CHECK (
    (source = 'promo_code') = (promo_code IS NOT NULL)
    AND ((source <> 'promo_code') = (referral_id IS NOT NULL))
    AND (source <> 'referral_inviter' OR listing_id IS NULL)
    AND (source NOT IN ('promo_code','referral_friend') OR listing_id IS NOT NULL)),
  CONSTRAINT credit_ended_pair CHECK ((status = 'active') = (ended_at IS NULL)),
  CONSTRAINT credit_status_counters CHECK (
    (status <> 'active' OR (kind = 'amount' AND amount_left_egp > 0) OR (kind <> 'amount' AND uses_left > 0))
    AND (status <> 'exhausted' OR (kind = 'amount' AND amount_left_egp = 0) OR (kind <> 'amount' AND uses_left = 0)))
);
CREATE INDEX idx_commission_credit_customer ON commission_credit (customer_id, status);
CREATE INDEX idx_commission_credit_listing ON commission_credit (listing_id) WHERE listing_id IS NOT NULL;
CREATE INDEX idx_commission_credit_code ON commission_credit (promo_code, granted_at) WHERE promo_code IS NOT NULL;
CREATE INDEX idx_commission_credit_expiry ON commission_credit (expires_at)
  WHERE status = 'active' AND expires_at IS NOT NULL;
CREATE UNIQUE INDEX uq_commission_credit_listing_code ON commission_credit (listing_id, promo_code)
  WHERE promo_code IS NOT NULL AND status IN ('active','exhausted');

ALTER TABLE referral
  ADD CONSTRAINT referral_friend_credit_fk FOREIGN KEY (friend_credit_id) REFERENCES commission_credit(credit_id),
  ADD CONSTRAINT referral_inviter_credit_fk FOREIGN KEY (inviter_credit_id) REFERENCES commission_credit(credit_id);
ALTER TABLE promo_code_use
  ADD CONSTRAINT promo_code_use_credit_fk FOREIGN KEY (credit_id) REFERENCES commission_credit(credit_id);

CREATE TABLE commission_credit_event (
  event_id                BIGSERIAL PRIMARY KEY,
  credit_id               UUID NOT NULL REFERENCES commission_credit(credit_id),
  kind                    TEXT NOT NULL CHECK (kind IN ('granted','spent','expired','voided')),
  order_id                UUID REFERENCES "order"(order_id),
  ordinary_commission_egp NUMERIC(18,4),
  charged_commission_egp  NUMERIC(18,4),
  reduction_egp           NUMERIC(18,4),
  amount_left_after_egp   NUMERIC(18,4),
  uses_left_after         SMALLINT,
  reason                  TEXT,
  actor_customer_id       UUID REFERENCES customer(customer_id),
  actor_staff_id          UUID REFERENCES staff(staff_id),
  created_at              TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  txid                    BIGINT NOT NULL DEFAULT txid_current(),
  CONSTRAINT credit_event_one_actor CHECK (num_nonnulls(actor_customer_id, actor_staff_id) = 1),
  CONSTRAINT credit_event_spent_shape CHECK ((kind = 'spent') = (order_id IS NOT NULL
    AND ordinary_commission_egp IS NOT NULL AND charged_commission_egp IS NOT NULL AND reduction_egp IS NOT NULL)),
  CONSTRAINT credit_event_reduction CHECK (kind <> 'spent'
    OR (reduction_egp > 0 AND reduction_egp = ordinary_commission_egp - charged_commission_egp))
);
CREATE INDEX idx_commission_credit_event_credit ON commission_credit_event (credit_id, event_id);
CREATE INDEX idx_commission_credit_event_order ON commission_credit_event (order_id) WHERE order_id IS NOT NULL;

CREATE TABLE listing_promo (
  listing_id          UUID PRIMARY KEY REFERENCES listing(listing_id),
  promo_code          TEXT REFERENCES promo_code(code),
  referral_inviter_id UUID REFERENCES customer(customer_id),
  -- NULL for a code that gives no commission (a first-sale code that only pays the advance).
  credit_id           UUID REFERENCES commission_credit(credit_id),
  applied_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT listing_promo_one_source CHECK (num_nonnulls(promo_code, referral_inviter_id) = 1)
);

-- DH024: a credit's counters and status move forward only, never deleted.
CREATE OR REPLACE FUNCTION commission_credit_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'commission credits are never deleted' USING ERRCODE = 'DH024';
  END IF;
  IF NEW.credit_id IS DISTINCT FROM OLD.credit_id OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
     OR NEW.source IS DISTINCT FROM OLD.source OR NEW.kind IS DISTINCT FROM OLD.kind
     OR NEW.percent_off IS DISTINCT FROM OLD.percent_off OR NEW.amount_egp IS DISTINCT FROM OLD.amount_egp
     OR NEW.uses_total IS DISTINCT FROM OLD.uses_total OR NEW.listing_id IS DISTINCT FROM OLD.listing_id
     OR NEW.promo_code IS DISTINCT FROM OLD.promo_code OR NEW.referral_id IS DISTINCT FROM OLD.referral_id
     OR NEW.granted_at IS DISTINCT FROM OLD.granted_at OR NEW.expires_at IS DISTINCT FROM OLD.expires_at THEN
    RAISE EXCEPTION 'only the counters and the status of a commission credit change' USING ERRCODE = 'DH024';
  END IF;
  IF OLD.status <> 'active' THEN
    RAISE EXCEPTION 'a commission credit that has ended never moves again' USING ERRCODE = 'DH024';
  END IF;
  IF NEW.uses_left > OLD.uses_left OR NEW.amount_left_egp > OLD.amount_left_egp THEN
    RAISE EXCEPTION 'a commission credit is never topped up' USING ERRCODE = 'DH024';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER trg_commission_credit_guard BEFORE UPDATE OR DELETE ON commission_credit
  FOR EACH ROW EXECUTE FUNCTION commission_credit_guard();

CREATE OR REPLACE FUNCTION commission_credit_event_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'commission credit events are append-only' USING ERRCODE = 'DH024';
END $$;
CREATE TRIGGER trg_commission_credit_event_immutable BEFORE UPDATE OR DELETE ON commission_credit_event
  FOR EACH ROW EXECUTE FUNCTION commission_credit_event_immutable();

-- A counter or status that moved has its event in the same transaction.
CREATE OR REPLACE FUNCTION commission_credit_recorded() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_ok BOOLEAN;
BEGIN
  IF NEW.uses_left IS NOT DISTINCT FROM OLD.uses_left
     AND NEW.amount_left_egp IS NOT DISTINCT FROM OLD.amount_left_egp
     AND NEW.status IS NOT DISTINCT FROM OLD.status THEN
    RETURN NULL;
  END IF;
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT EXISTS (SELECT 1 FROM commission_credit_event e
                  WHERE e.credit_id = NEW.credit_id AND e.txid = txid_current()
                    AND e.kind = CASE NEW.status WHEN 'expired' THEN 'expired' WHEN 'voided' THEN 'voided' ELSE 'spent' END)
    INTO v_ok;
  PERFORM set_config('app.rls_scope', prev_scope, true);
  IF NOT v_ok THEN
    RAISE EXCEPTION 'commission credit % changed without its event', NEW.credit_id USING ERRCODE = 'DH024';
  END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER trg_commission_credit_recorded
  AFTER UPDATE ON commission_credit
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION commission_credit_recorded();

-- Referral: forward only (joined -> rewarded | void), never deleted.
CREATE OR REPLACE FUNCTION referral_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'referrals are never deleted' USING ERRCODE = 'DH024';
  END IF;
  IF NEW.referral_id IS DISTINCT FROM OLD.referral_id OR NEW.inviter_id IS DISTINCT FROM OLD.inviter_id
     OR NEW.friend_id IS DISTINCT FROM OLD.friend_id OR NEW.code IS DISTINCT FROM OLD.code
     OR NEW.joined_at IS DISTINCT FROM OLD.joined_at
     OR NEW.device_fingerprint IS DISTINCT FROM OLD.device_fingerprint OR NEW.id_key IS DISTINCT FROM OLD.id_key
     OR (OLD.listing_id IS NOT NULL AND NEW.listing_id IS DISTINCT FROM OLD.listing_id)
     OR (OLD.friend_credit_id IS NOT NULL AND NEW.friend_credit_id IS DISTINCT FROM OLD.friend_credit_id)
     OR (OLD.inviter_credit_id IS NOT NULL AND NEW.inviter_credit_id IS DISTINCT FROM OLD.inviter_credit_id) THEN
    RAISE EXCEPTION 'a referral keeps who, which code and when' USING ERRCODE = 'DH024';
  END IF;
  IF OLD.state <> 'joined' AND NEW.state IS DISTINCT FROM OLD.state THEN
    RAISE EXCEPTION 'a referral that has ended never moves again' USING ERRCODE = 'DH024';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER trg_referral_guard BEFORE UPDATE OR DELETE ON referral
  FOR EACH ROW EXECUTE FUNCTION referral_guard();

-- DH027: the code of a listing changes only while no buyer is in line.
CREATE OR REPLACE FUNCTION listing_promo_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_listing UUID := COALESCE(NEW.listing_id, OLD.listing_id);
  v_state TEXT;
  v_line BOOLEAN;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT l.state::text INTO v_state FROM listing l WHERE l.listing_id = v_listing;
  SELECT EXISTS (SELECT 1 FROM buy_request b WHERE b.listing_id = v_listing AND b.state IN ('queued','accepted'))
    INTO v_line;
  PERFORM set_config('app.rls_scope', prev_scope, true);
  IF v_state IS NULL OR v_state NOT IN ('draft','changes_requested','in_review','live') OR v_line THEN
    RAISE EXCEPTION 'the code of listing % cannot change now', v_listing USING ERRCODE = 'DH027';
  END IF;
  RETURN COALESCE(NEW, OLD);
END $$;
CREATE TRIGGER trg_listing_promo_guard BEFORE INSERT OR UPDATE OR DELETE ON listing_promo
  FOR EACH ROW EXECUTE FUNCTION listing_promo_guard();

-- The cost figures of a settlement, stored on the order (research R17, R4). All NULL for
-- orders settled before this spec.
ALTER TABLE "order"
  ADD COLUMN commission_ordinary_amount   NUMERIC(18,4),
  ADD COLUMN commission_given_up          NUMERIC(18,4),
  ADD COLUMN commission_waiver_kind       TEXT,
  ADD COLUMN commission_waiver_credit_id  UUID REFERENCES commission_credit(credit_id),
  ADD COLUMN commission_floor_egp         NUMERIC(18,4),
  ADD COLUMN commission_floor_applied     BOOLEAN,
  ADD COLUMN spread_given_up              NUMERIC(18,4),
  ADD COLUMN settled_at                   TIMESTAMPTZ,
  ADD CONSTRAINT order_cost_kind CHECK (commission_waiver_kind IS NULL
    OR commission_waiver_kind IN ('none','free_relist','market_maker','credit')),
  ADD CONSTRAINT order_cost_shape CHECK (
    num_nonnulls(commission_ordinary_amount, commission_given_up, commission_waiver_kind,
                 commission_floor_egp, commission_floor_applied, spread_given_up, settled_at) IN (0, 7)
    AND (commission_ordinary_amount IS NULL OR final_buyer_total IS NOT NULL)),
  ADD CONSTRAINT order_cost_given_up CHECK (commission_given_up IS NULL OR commission_given_up >= 0),
  ADD CONSTRAINT order_cost_sum CHECK (commission_ordinary_amount IS NULL
    OR commission_amount + commission_given_up = commission_ordinary_amount),
  ADD CONSTRAINT order_cost_credit CHECK (commission_waiver_kind IS NULL
    OR (commission_waiver_kind = 'credit') = (commission_waiver_credit_id IS NOT NULL)),
  ADD CONSTRAINT order_cost_none CHECK (commission_waiver_kind IS DISTINCT FROM 'none' OR commission_given_up = 0),
  -- The minimum commission is never broken where a floor applies (research R4).
  ADD CONSTRAINT order_cost_floor CHECK (commission_floor_applied IS NOT TRUE OR commission_amount >= commission_floor_egp),
  ADD CONSTRAINT order_cost_spread CHECK (spread_given_up IS NULL OR spread_given_up >= 0);
CREATE INDEX idx_order_market_maker_cost ON "order" (settled_at) WHERE commission_waiver_kind = 'market_maker';

-- The figures never change once stored.
CREATE OR REPLACE FUNCTION order_cost_frozen() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.commission_ordinary_amount IS NOT NULL AND (
       NEW.commission_ordinary_amount IS DISTINCT FROM OLD.commission_ordinary_amount
       OR NEW.commission_given_up IS DISTINCT FROM OLD.commission_given_up
       OR NEW.commission_waiver_kind IS DISTINCT FROM OLD.commission_waiver_kind
       OR NEW.commission_waiver_credit_id IS DISTINCT FROM OLD.commission_waiver_credit_id
       OR NEW.commission_floor_egp IS DISTINCT FROM OLD.commission_floor_egp
       OR NEW.commission_floor_applied IS DISTINCT FROM OLD.commission_floor_applied
       OR NEW.spread_given_up IS DISTINCT FROM OLD.spread_given_up
       OR NEW.settled_at IS DISTINCT FROM OLD.settled_at) THEN
    RAISE EXCEPTION 'order % cost figures cannot change', OLD.order_id USING ERRCODE = 'DH026';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER trg_order_cost_frozen BEFORE UPDATE ON "order"
  FOR EACH ROW EXECUTE FUNCTION order_cost_frozen();

-- DH026: a credit waiver has exactly one matching spent event (deferred).
CREATE OR REPLACE FUNCTION order_waiver_reconciled() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_n INT;
BEGIN
  IF NEW.commission_waiver_kind IS DISTINCT FROM 'credit' THEN
    RETURN NULL;
  END IF;
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT count(*) INTO v_n FROM commission_credit_event e
   WHERE e.order_id = NEW.order_id AND e.kind = 'spent'
     AND e.credit_id = NEW.commission_waiver_credit_id
     AND e.reduction_egp = NEW.commission_given_up;
  PERFORM set_config('app.rls_scope', prev_scope, true);
  IF v_n <> 1 THEN
    RAISE EXCEPTION 'order % credit waiver has % matching spent events', NEW.order_id, v_n USING ERRCODE = 'DH026';
  END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER trg_order_waiver_reconciled
  AFTER INSERT OR UPDATE ON "order"
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION order_waiver_reconciled();

-- Cross-customer facts as plain answers (the listing_price_locked scope-switch pattern).
CREATE OR REPLACE FUNCTION promo_code_id_uses(p_code TEXT, p_id_key TEXT, p_since TIMESTAMPTZ) RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_n INTEGER;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT count(*)::int INTO v_n
    FROM promo_code_use u LEFT JOIN commission_credit c ON c.credit_id = u.credit_id
   WHERE u.code = p_code AND u.allowed AND u.id_key = p_id_key
     AND ((u.credit_id IS NOT NULL AND c.status <> 'voided')
          OR (u.credit_id IS NULL AND u.listing_id IS NOT NULL AND EXISTS
                (SELECT 1 FROM listing_promo lp WHERE lp.listing_id = u.listing_id AND lp.promo_code = u.code)))
     AND (p_since IS NULL OR u.used_at >= p_since);
  PERFORM set_config('app.rls_scope', prev_scope, true);
  RETURN v_n;
END $$;

-- EGP of transaction value counted against a code's monthly cap (non-voided uses).
CREATE OR REPLACE FUNCTION promo_code_value_since(p_code TEXT, p_since TIMESTAMPTZ) RETURNS numeric
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_sum NUMERIC;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT COALESCE(sum(u.amount_egp), 0) INTO v_sum
    FROM promo_code_use u LEFT JOIN commission_credit c ON c.credit_id = u.credit_id
   WHERE u.code = p_code AND u.allowed AND u.used_at >= p_since
     AND ((u.credit_id IS NOT NULL AND c.status <> 'voided')
          OR (u.credit_id IS NULL AND u.listing_id IS NOT NULL AND EXISTS
                (SELECT 1 FROM listing_promo lp WHERE lp.listing_id = u.listing_id AND lp.promo_code = u.code)));
  PERFORM set_config('app.rls_scope', prev_scope, true);
  RETURN v_sum;
END $$;

CREATE OR REPLACE FUNCTION referral_device_count(p_device TEXT) RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_n INTEGER;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT count(*)::int INTO v_n FROM referral WHERE device_fingerprint = p_device AND state <> 'void';
  PERFORM set_config('app.rls_scope', prev_scope, true);
  RETURN v_n;
END $$;

CREATE OR REPLACE FUNCTION referral_inviter_count(p_inviter UUID) RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_n INTEGER;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT count(*)::int INTO v_n FROM referral WHERE inviter_id = p_inviter AND state <> 'void';
  PERFORM set_config('app.rls_scope', prev_scope, true);
  RETURN v_n;
END $$;

-- A friend resolves a code by value, never by reading `referral_code`.
CREATE OR REPLACE FUNCTION referral_lookup(p_code TEXT) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_id UUID;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT customer_id INTO v_id FROM referral_code WHERE code = p_code;
  PERFORM set_config('app.rls_scope', prev_scope, true);
  RETURN v_id;
END $$;

-- Is `p_id_key` the key of this customer (the same person under another account)?
CREATE OR REPLACE FUNCTION referral_same_person(p_customer UUID, p_id_key TEXT) RETURNS boolean
LANGUAGE plpgsql AS $$
DECLARE
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
  v_hash TEXT;
BEGIN
  PERFORM set_config('app.rls_scope', 'system', true);
  SELECT d.id_number_hash INTO v_hash FROM identity_document d
   WHERE d.customer_id = p_customer AND d.status = 'verified' AND d.id_number_hash IS NOT NULL
   ORDER BY d.reviewed_at DESC NULLS LAST LIMIT 1;
  PERFORM set_config('app.rls_scope', prev_scope, true);
  RETURN p_id_key = COALESCE(v_hash, 'account:' || p_customer::text);
END $$;

ALTER TABLE referral_code            ENABLE ROW LEVEL SECURITY;
ALTER TABLE referral_code            FORCE  ROW LEVEL SECURITY;
ALTER TABLE referral                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE referral                 FORCE  ROW LEVEL SECURITY;
ALTER TABLE commission_credit        ENABLE ROW LEVEL SECURITY;
ALTER TABLE commission_credit        FORCE  ROW LEVEL SECURITY;
ALTER TABLE commission_credit_event  ENABLE ROW LEVEL SECURITY;
ALTER TABLE commission_credit_event  FORCE  ROW LEVEL SECURITY;
ALTER TABLE listing_promo            ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_promo            FORCE  ROW LEVEL SECURITY;

-- Staff (elevated): everything.
CREATE POLICY referral_code_staff ON referral_code FOR ALL
  USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
CREATE POLICY referral_staff ON referral FOR ALL
  USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
CREATE POLICY commission_credit_staff ON commission_credit FOR ALL
  USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
CREATE POLICY commission_credit_event_staff ON commission_credit_event FOR ALL
  USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
CREATE POLICY listing_promo_staff ON listing_promo FOR ALL
  USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));

-- The owner reads their own rows.
CREATE POLICY referral_code_owner ON referral_code FOR SELECT
  USING (customer_id = (SELECT dahab_current_customer_id()));
CREATE POLICY referral_parties ON referral FOR SELECT
  USING (inviter_id = (SELECT dahab_current_customer_id()) OR friend_id = (SELECT dahab_current_customer_id()));
CREATE POLICY commission_credit_owner ON commission_credit FOR SELECT
  USING (customer_id = (SELECT dahab_current_customer_id()));
CREATE POLICY commission_credit_event_follow ON commission_credit_event FOR SELECT
  USING (EXISTS (SELECT 1 FROM commission_credit c WHERE c.credit_id = commission_credit_event.credit_id));
CREATE POLICY listing_promo_owner ON listing_promo FOR SELECT
  USING (EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_promo.listing_id
                   AND l.seller_id = (SELECT dahab_current_customer_id())));

-- The `promo` scope (research R26): the caller's own code, credit and referral rows, nothing else.
CREATE POLICY referral_code_promo_write ON referral_code FOR ALL
  USING ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()))
  WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()));
CREATE POLICY referral_promo_write ON referral FOR ALL
  USING ((SELECT dahab_rls_scope()) = 'promo' AND friend_id = (SELECT dahab_current_customer_id()))
  WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND friend_id = (SELECT dahab_current_customer_id()));
CREATE POLICY commission_credit_promo_write ON commission_credit FOR ALL
  USING ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()))
  WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()));
CREATE POLICY commission_credit_event_promo_insert ON commission_credit_event FOR INSERT
  WITH CHECK ((SELECT dahab_rls_scope()) = 'promo'
              AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
              AND EXISTS (SELECT 1 FROM commission_credit c WHERE c.credit_id = commission_credit_event.credit_id));
CREATE POLICY listing_promo_promo_write ON listing_promo FOR ALL
  USING ((SELECT dahab_rls_scope()) = 'promo' AND EXISTS (SELECT 1 FROM listing l
           WHERE l.listing_id = listing_promo.listing_id AND l.seller_id = (SELECT dahab_current_customer_id())))
  WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND EXISTS (SELECT 1 FROM listing l
           WHERE l.listing_id = listing_promo.listing_id AND l.seller_id = (SELECT dahab_current_customer_id())));

-- The `order` scope: the buyer settling an order spends the credit of that order's seller.
-- The inner SELECT on "order" is filtered by order_isolation: the caller's own orders only.
CREATE POLICY commission_credit_order_spend ON commission_credit FOR ALL
  USING ((SELECT dahab_rls_scope()) = 'order'
         AND EXISTS (SELECT 1 FROM "order" o WHERE o.seller_id = commission_credit.customer_id))
  WITH CHECK ((SELECT dahab_rls_scope()) = 'order'
         AND EXISTS (SELECT 1 FROM "order" o WHERE o.seller_id = commission_credit.customer_id));
CREATE POLICY commission_credit_event_order_insert ON commission_credit_event FOR INSERT
  WITH CHECK ((SELECT dahab_rls_scope()) = 'order'
              AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
              AND EXISTS (SELECT 1 FROM commission_credit c WHERE c.credit_id = commission_credit_event.credit_id));

-- The `promo` scope reads the codes and writes the use log (it is not an elevation).
DROP POLICY promo_code_staff ON promo_code;
CREATE POLICY promo_code_staff ON promo_code FOR ALL
  USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','promo'))
  WITH CHECK ((SELECT dahab_rls_elevated()));
DROP POLICY promo_code_use_staff ON promo_code_use;
CREATE POLICY promo_code_use_staff ON promo_code_use FOR ALL
  USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','promo'))
  WITH CHECK ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','promo'));

-- ---------------------------------------------------------------------------
-- spec 023 M3 — first-sale enablement and advance, DH025, listing_price_locked() extended
-- (specs/023-first-sale-and-referral/data-model.md; source: database/migrations/2026_10_16_000030_first_sale_advance.php)
-- ---------------------------------------------------------------------------

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

-- ---------------------------------------------------------------------------
-- spec 023 M4 — the listing view counter
-- (specs/023-first-sale-and-referral/data-model.md; source: database/migrations/2026_10_16_000040_listing_views.php)
-- ---------------------------------------------------------------------------

CREATE TABLE listing_view (
  listing_id  UUID NOT NULL REFERENCES listing(listing_id),
  day         DATE NOT NULL,
  viewer_hash TEXT NOT NULL,
  PRIMARY KEY (listing_id, day, viewer_hash)
);
CREATE INDEX idx_listing_view_day ON listing_view (day);

CREATE OR REPLACE FUNCTION listing_view_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_days INTEGER;
BEGIN
  IF TG_OP = 'UPDATE' THEN
    RAISE EXCEPTION 'listing views are never edited' USING ERRCODE = 'DH027';
  END IF;
  SELECT value_numeric::int INTO v_days FROM setting WHERE setting_key = 'listing.view_retention_days';
  IF v_days IS NULL OR OLD.day >= (now() AT TIME ZONE 'Africa/Cairo')::date - v_days THEN
    RAISE EXCEPTION 'a listing view is kept for % days', v_days USING ERRCODE = 'DH027';
  END IF;
  RETURN OLD;
END $$;
CREATE TRIGGER trg_listing_view_guard BEFORE UPDATE OR DELETE ON listing_view
  FOR EACH ROW EXECUTE FUNCTION listing_view_guard();

ALTER TABLE listing_view ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_view FORCE  ROW LEVEL SECURITY;
CREATE POLICY listing_view_staff ON listing_view FOR ALL
  USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
