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

-- Append-only: no updates or deletes, ever.
CREATE TRIGGER audit_no_update BEFORE UPDATE OR DELETE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

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
  ('disputed','ready_to_collect','dispute resolved, resume');

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
  ('live','reserved','first buy request queued'),
  ('reserved','live','queue emptied (all requests released)'),
  ('reserved','accepted','seller accepted the first in the queue'),
  ('accepted','at_inspection','piece delivered to branch'),
  ('at_inspection','settling','inspection recorded'),
  ('settling','sold','completed'),
  ('settling','live','sale fell through; back on the market'),
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
  ('seller_unclaimed','live','relisted after contact');

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

-- ---------------------------------------------------------------------
-- 19. Row-Level Security (customer data isolation)
--     A customer can see only their own rows. Staff access is granted by
--     role at the application's connection role level. Wallet visibility
--     is limited to ceo + finance by granting SELECT on wallet views only
--     to those roles (see grants file). RLS shown for customer tables.
-- ---------------------------------------------------------------------
ALTER TABLE customer            ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing             ENABLE ROW LEVEL SECURITY;
ALTER TABLE buy_request         ENABLE ROW LEVEL SECURITY;
ALTER TABLE "order"             ENABLE ROW LEVEL SECURITY;
ALTER TABLE payout_account      ENABLE ROW LEVEL SECURITY;
ALTER TABLE withdrawal          ENABLE ROW LEVEL SECURITY;
ALTER TABLE identity_document   ENABLE ROW LEVEL SECURITY;

-- The app sets: SET LOCAL app.current_customer_id = '<uuid>' per request
-- for customer-facing connections. Staff connections use a separate role
-- that bypasses these policies (see grants), with their own guards.
CREATE POLICY cust_self_customer ON customer
  USING (customer_id = current_setting('app.current_customer_id', true)::uuid);

CREATE POLICY cust_self_listing ON listing
  USING (seller_id = current_setting('app.current_customer_id', true)::uuid);

CREATE POLICY cust_self_request ON buy_request
  USING (buyer_id = current_setting('app.current_customer_id', true)::uuid);

CREATE POLICY cust_self_order ON "order"
  USING (seller_id = current_setting('app.current_customer_id', true)::uuid
      OR buyer_id  = current_setting('app.current_customer_id', true)::uuid);

CREATE POLICY cust_self_payout ON payout_account
  USING (customer_id = current_setting('app.current_customer_id', true)::uuid);

CREATE POLICY cust_self_withdrawal ON withdrawal
  USING (customer_id = current_setting('app.current_customer_id', true)::uuid);

CREATE POLICY cust_self_document ON identity_document
  USING (customer_id = current_setting('app.current_customer_id', true)::uuid);

-- NOTE: a public marketplace read of LIVE listings is served by a
-- dedicated view (or a separate policy) that exposes only non-owner-
-- sensitive columns of listings in state 'live'/'reserved'. Kept out of
-- the owner policy above so browsing does not leak seller identity.

-- =====================================================================
-- Added by feature 001-auth-customer-staff
-- Dashboard roles/permissions: spatie/laravel-permission's standard tables
-- (guard_name = 'staff'). Replaces the earlier custom staff_permission
-- table. Created by the package migration; the only customisation is that
-- model_id is UUID because staff.staff_id is UUID. Roles mirror staff_role;
-- permissions and the role -> permission map are seeded by
-- DashboardRolesAndPermissionsSeeder (adding one is an enum + seeder change,
-- never a runtime write). See docs/Technical Spec/dahab-dashboard-authorization.md.
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
  id          BIGSERIAL PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  guard_name  VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP(0),
  updated_at  TIMESTAMP(0),
  UNIQUE (name, guard_name)
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
