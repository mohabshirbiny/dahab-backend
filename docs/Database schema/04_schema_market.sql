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
  listed_at      TIMESTAMPTZ,                              -- when it went live
  CONSTRAINT gold_needs_karat_weight CHECK (
    category = 'diamond'
    OR (karat_code IS NOT NULL AND stated_weight_g IS NOT NULL)
  ),
  CONSTRAINT queue_count_nonneg CHECK (active_queue_count >= 0)
);

CREATE INDEX idx_listing_state ON listing(state);
CREATE INDEX idx_listing_seller ON listing(seller_id);

-- Photos / video / uploaded original invoice / uploaded stone certificate.
CREATE TABLE listing_media (
  media_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id   UUID NOT NULL REFERENCES listing(listing_id),
  kind         TEXT NOT NULL CHECK (kind IN
                 ('photo','video','invoice','stone_certificate')),
  storage_ref  TEXT NOT NULL,
  is_private   BOOLEAN NOT NULL DEFAULT FALSE,   -- invoice stays private pre-sale
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

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
  -- Arrival order within the listing. Assigned from a per-listing
  -- sequence at insert (see trigger). Lower = earlier = ahead in line.
  queue_position INTEGER NOT NULL,
  -- Price locked for THIS buyer at request time.
  locked_unit_rate NUMERIC(18,4) NOT NULL,   -- gold rate used
  locked_total_price NUMERIC(18,4) NOT NULL, -- full piece price at lock
  deposit_amount NUMERIC(18,4) NOT NULL,     -- 20% of locked_total_price
  -- The ledger transaction that placed the deposit hold (available->held).
  deposit_hold_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
  requested_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Seller reply deadline (clock hours), computed from setting at insert.
  seller_reply_deadline TIMESTAMPTZ NOT NULL,
  resolved_at    TIMESTAMPTZ,                 -- when it left 'queued'
  -- If the buyer withdrew and asked to be told when the piece is free.
  notify_when_free BOOLEAN NOT NULL DEFAULT FALSE,
  CONSTRAINT deposit_positive CHECK (deposit_amount > 0),
  -- A buyer can hold only one ACTIVE request per listing at a time.
  -- Enforced via a partial unique index below (queued/accepted only).
  UNIQUE (listing_id, queue_position)
);

CREATE UNIQUE INDEX one_active_request_per_buyer_listing
  ON buy_request(listing_id, buyer_id)
  WHERE state IN ('queued','accepted');

CREATE INDEX idx_buy_request_listing_state ON buy_request(listing_id, state);
CREATE INDEX idx_buy_request_buyer ON buy_request(buyer_id);

-- Per-listing monotonic queue position. A dedicated table of counters
-- avoids gaps-vs-reuse ambiguity and races under concurrency.
CREATE TABLE listing_queue_seq (
  listing_id UUID PRIMARY KEY REFERENCES listing(listing_id),
  next_pos   INTEGER NOT NULL DEFAULT 1
);

-- Keep listing.active_queue_count and listing.state in step with the
-- set of active (queued) requests. Source of truth = buy_request.
CREATE OR REPLACE FUNCTION sync_listing_queue() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_listing UUID := COALESCE(NEW.listing_id, OLD.listing_id);
  v_count INTEGER;
BEGIN
  SELECT count(*) INTO v_count FROM buy_request
   WHERE listing_id = v_listing AND state = 'queued';

  UPDATE listing
     SET active_queue_count = v_count,
         state = CASE
                   WHEN state IN ('live','reserved')
                     THEN CASE WHEN v_count > 0 THEN 'reserved' ELSE 'live' END
                   ELSE state
                 END
   WHERE listing_id = v_listing;
  RETURN NULL;
END $$;

CREATE TRIGGER trg_sync_queue
  AFTER INSERT OR UPDATE OF state ON buy_request
  FOR EACH ROW EXECUTE FUNCTION sync_listing_queue();

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
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_order_state ON "order"(state);
CREATE INDEX idx_order_seller ON "order"(seller_id);
CREATE INDEX idx_order_buyer ON "order"(buyer_id);

-- Enforce: chosen branch must be one the seller named at listing.
CREATE OR REPLACE FUNCTION assert_branch_in_options() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM listing_branch_option
    WHERE listing_id = NEW.listing_id AND branch_id = NEW.branch_id
  ) THEN
    RAISE EXCEPTION 'branch % is not among the listing''s named options', NEW.branch_id;
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
  reason       TEXT,
  changed_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Deadline extensions (admin-granted), audited.
CREATE TABLE order_deadline_extension (
  extension_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  which        TEXT NOT NULL CHECK (which IN ('reach_branch','balance','collect')),
  old_deadline TIMESTAMPTZ NOT NULL,
  new_deadline TIMESTAMPTZ NOT NULL,
  granted_by   UUID NOT NULL REFERENCES staff(staff_id),
  reason       TEXT,
  granted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT extension_moves_forward CHECK (new_deadline > old_deadline)
);

-- Seller cancellation record (counts toward suspension threshold).
CREATE TABLE seller_cancellation (
  cancellation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  seller_id    UUID NOT NULL REFERENCES customer(customer_id),
  cancelled_at TIMESTAMPTZ NOT NULL DEFAULT now()
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

-- Append-only: results are immutable; corrections supersede.
CREATE TRIGGER inspection_no_update BEFORE UPDATE OR DELETE ON inspection_result
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

-- Buyer's decision when a weight adjustment (above tolerance) or a stone
-- regrade needs approval. Refund-in-full if declined.
CREATE TABLE settlement_decision (
  decision_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  inspection_id UUID NOT NULL REFERENCES inspection_result(inspection_id),
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
  code_hash    TEXT NOT NULL,                   -- collection code, hashed
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
  code_hash    TEXT NOT NULL,                   -- seller collection code, hashed
  -- The deadline for the seller to collect the returned piece (working
  -- hours resolved from deadline.seller_return_weeks at return time).
  return_deadline TIMESTAMPTZ NOT NULL,
  collected_at TIMESTAMPTZ,
  handover_by  UUID REFERENCES staff(staff_id), -- igi_branch confirms
  -- The compensation transaction (50% of the deposit) paid to the seller.
  compensation_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_seller_return_deadline ON seller_return(return_deadline)
  WHERE collected_at IS NULL;

-- ---------------------------------------------------------------------
-- 12. Payout accounts and withdrawals
--     Money leaves only to an account in the customer's own name. A
--     payout-account change cancels any in-flight withdrawal and pauses
--     new withdrawals for the setting window (48h). Every withdrawal is
--     reviewed by a person before release.
-- ---------------------------------------------------------------------
CREATE TABLE payout_account (
  payout_account_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  account_name TEXT NOT NULL,                   -- must match the ID
  bank_name    TEXT NOT NULL,
  account_number_or_iban TEXT NOT NULL,
  state        payout_account_state NOT NULL DEFAULT 'pending_review',
  name_checked_by UUID REFERENCES staff(staff_id),
  name_checked_at TIMESTAMPTZ,
  -- When this account was activated/changed; drives the withdrawal pause.
  activated_at TIMESTAMPTZ,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_payout_customer ON payout_account(customer_id);

-- A per-customer pause window opened by a payout-account change. The
-- withdrawal service refuses new releases while now() < pause_until.
CREATE TABLE withdrawal_pause (
  pause_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  opened_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  pause_until  TIMESTAMPTZ NOT NULL,            -- opened_at + setting hours
  triggered_by_account UUID REFERENCES payout_account(payout_account_id),
  CONSTRAINT pause_window_valid CHECK (pause_until > opened_at)
);

CREATE INDEX idx_pause_customer_until ON withdrawal_pause(customer_id, pause_until);

CREATE TABLE withdrawal (
  withdrawal_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  payout_account_id UUID NOT NULL REFERENCES payout_account(payout_account_id),
  amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
  state        withdrawal_state NOT NULL DEFAULT 'requested',
  -- The ledger transaction that moved available -> (out to bank) on release.
  release_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
  reviewed_by  UUID REFERENCES staff(staff_id),   -- finance/ceo
  requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  released_at  TIMESTAMPTZ,
  settled_at   TIMESTAMPTZ
);

CREATE INDEX idx_withdrawal_customer_state ON withdrawal(customer_id, state);

-- Now wire the ledger's business-object FKs (declared in Part 2).
ALTER TABLE ledger_transaction
  ADD CONSTRAINT lt_listing_fk    FOREIGN KEY (listing_id)     REFERENCES listing(listing_id),
  ADD CONSTRAINT lt_order_fk      FOREIGN KEY (order_id)       REFERENCES "order"(order_id),
  ADD CONSTRAINT lt_request_fk    FOREIGN KEY (buy_request_id) REFERENCES buy_request(buy_request_id),
  ADD CONSTRAINT lt_withdrawal_fk FOREIGN KEY (withdrawal_id)  REFERENCES withdrawal(withdrawal_id);

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
