-- =====================================================================
-- Dahab — gold marketplace, Egypt
-- FULL PostgreSQL schema — single-file build
-- Target: PostgreSQL 15+
--
-- This file is the concatenation, in dependency order, of:
--   01_schema_core.sql      (extensions, schema, enums, reference data, settings)
--   02_schema_identity.sql  (staff, customers, documents, agreements)
--   03_schema_ledger.sql    (double-entry money ledger)
--   04_schema_market.sql    (listings, queue, orders, inspections, withdrawals)
--   05_schema_security.sql  (audit, disputes, market makers, reconciliation, RLS)
--
-- Run:  psql "postgresql://user:pass@host:5432/dbname" -v ON_ERROR_STOP=1 -f 00_schema_full.sql
--
-- Wrapped in one transaction: it either builds completely or rolls back.
-- =====================================================================
-- \set ON_ERROR_STOP on   -- psql-only meta-command; ignored here. Use `psql -v ON_ERROR_STOP=1`
-- when running from the command line. The BEGIN/COMMIT wrapper below already makes this
-- an all-or-nothing build regardless of client.
BEGIN;



-- #####################################################################
-- BEGIN 01_schema_core.sql
-- #####################################################################

-- =====================================================================
-- Dahab — gold marketplace, Egypt
-- PostgreSQL schema  ·  Part 1 of 4: core, reference data, identity
-- Target: PostgreSQL 15+
-- Handoff: senior developer to senior developer.
--
-- Design rules that hold everywhere in this schema:
--   1. Money is never stored as a mutable balance. Every balance is
--      derived from an append-only, double-entry ledger (see Part 2).
--   2. Rates, deadlines, karats, piece types and thresholds are DATA
--      (reference tables + settings), never hard-coded.
--   3. Deadlines are counted in WORKING HOURS at a specific branch,
--      honouring that branch's hours and holiday closures.
--   4. Nothing that moves money, changes a price or closes an account
--      happens without a named actor. Enforced by the audit log (Part 4)
--      and by actor_id columns on state-changing tables.
--   5. A submitted inspection result is immutable. Corrections are new
--      rows; both are kept.
--   6. Every document version a customer agreed to is kept forever.
--   7. Commission is never taken from the value of the customer's gold.
--
-- Money type convention:
--   All monetary amounts are NUMERIC(18,4), in EGP, minor-unit-safe.
--   Never FLOAT/REAL. Weights are NUMERIC(10,3) grams. Karat is an
--   integer code validated against the karat reference table.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. Extensions
-- ---------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS pgcrypto;      -- gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS btree_gist;    -- exclusion constraints
CREATE EXTENSION IF NOT EXISTS citext;        -- case-insensitive email

-- Dedicated schema keeps Dahab objects out of public and makes RLS and
-- grants easier to reason about.
CREATE SCHEMA IF NOT EXISTS dahab;
SET search_path = dahab, public;

-- ---------------------------------------------------------------------
-- 1. Enumerated types
--    Enums are used only for sets that are truly fixed by the domain and
--    never edited by an operator. Anything an operator may change lives
--    in a reference table instead (karats, piece types, branches...).
-- ---------------------------------------------------------------------

-- Which side of the marketplace a party is acting as, per transaction.
CREATE TYPE party_role AS ENUM ('seller', 'buyer');

-- Category drives pricing, photos, certificate requirement and payout.
CREATE TYPE piece_category AS ENUM ('gold', 'diamond', 'gold_with_diamond');

-- Listing lifecycle. See state machine in Part 3.
CREATE TYPE listing_state AS ENUM (
  'draft',            -- being created, not submitted
  'in_review',        -- submitted, awaiting Dahab listing review
  'changes_requested',-- sent back to the seller for a better photo/detail
  'live',             -- visible on the market, price follows the rate
  'reserved',         -- has >=1 active buy request in the queue
  'accepted',         -- seller accepted a buyer; heading to inspection
  'at_inspection',    -- piece physically at the branch, being inspected
  'settling',         -- inspection done, price/settlement resolving
  'sold',             -- completed sale
  'withdrawn',        -- taken down by seller/admin while live
  'suspended_hold',   -- frozen by a category stop / account suspension
  -- Buyer paid in full but never collected; piece waits at IGI. After the
  -- collect window a status is shown to the buyer ("window passed, Dahab is
  -- not liable"); disposition (hand over / compensate) is a manual decision
  -- taken when the buyer makes contact. RESOLVED policy (see Part 3 logic).
  'uncollected_expired',
  -- Buyer did NOT pay the balance within the pay window. The piece returns
  -- to the seller, who collects it and receives 50% of the commission as
  -- compensation for their time. Waiting for the seller to come and collect.
  'awaiting_seller_return',
  -- The returned piece sat awaiting the seller past the return window and
  -- the seller never came. Status shown ("window passed, not our liability");
  -- Dahab then hands it over or compensates. Manual, like uncollected_expired.
  'seller_unclaimed'
);

-- Order (a single accepted buyer's purchase) lifecycle.
CREATE TYPE order_state AS ENUM (
  'awaiting_delivery', -- seller accepted, must reach branch within deadline
  'at_inspection',
  'inspection_passed',
  'weight_adjust_pending', -- weight diff > tolerance, buyer must approve
  'awaiting_balance',  -- inspection ok, buyer must pay balance
  'ready_to_collect',  -- balance paid, collection code live
  'completed',         -- collected, commission + spread taken
  'cancelled_seller',  -- seller cancelled after accepting
  'cancelled_buyer_nopay', -- buyer never paid the balance
  'cancelled_inspection',  -- failed inspection / karat mismatch / declined adj.
  'disputed'           -- frozen while a dispute is open
);

-- A single buyer's position on a piece. The queue is the ordered set of
-- active requests for one listing. See state machine in Part 3.
CREATE TYPE buy_request_state AS ENUM (
  'queued',            -- in line, deposit held, price locked
  'accepted',          -- chosen by the seller -> becomes the order's buyer
  'released_not_chosen',-- seller took someone else; deposit refunded
  'released_declined', -- seller declined this request; deposit refunded
  'released_expired',  -- seller reply deadline passed; deposit refunded
  'withdrawn_by_buyer' -- buyer left the queue; deposit refunded
);

CREATE TYPE withdrawal_state AS ENUM (
  'requested',         -- created, funds moved available -> pending hold
  'under_review',      -- a person is reviewing before release
  'on_hold_account_change', -- paused by a payout-account change window
  'released',          -- sent to bank
  'settled',           -- confirmed arrived
  'rejected',          -- reviewer rejected; funds returned to available
  'cancelled'          -- buyer/holder cancelled before release
);

CREATE TYPE payout_account_state AS ENUM (
  'pending_review',    -- name being checked against ID
  'active',
  'removing',          -- scheduled removal after an in-flight withdrawal
  'removed'
);

-- Direction/kind of a ledger posting is expressed by account, not enum;
-- see Part 2. This enum only tags the business event that produced a set
-- of postings, for reporting.
CREATE TYPE ledger_event_kind AS ENUM (
  'topup',                 -- customer added funds
  'deposit_hold',          -- buy request: available -> held
  'deposit_release',       -- refund a held deposit -> available
  'deposit_forfeit',       -- buyer no-pay: split seller comp / dahab
  'settlement_seller',     -- pay the seller (gold value + making charge)
  'first_sale_payout',     -- ADVANCE to a first-time seller: Dahab fronts
                           -- the sale proceeds to the seller early (before
                           -- the buyer pays) as a trust incentive on the
                           -- seller's first use of the platform. Dahab is
                           -- still only an intermediary — it does NOT buy the
                           -- piece; the piece follows its normal path to the
                           -- buyer, and Dahab recovers the advance from the
                           -- buyer's later payment. Gold category only,
                           -- promo-code-gated, capped by
                           -- payout.first_sale_cap_egp.
  'commission',            -- Dahab commission (from making charge / stone)
  'spread',                -- buy/sell difference to Dahab
  'vat',                   -- VAT on commission
  'balance_payment',       -- buyer pays the balance into escrow
  'withdrawal',            -- customer withdraws to bank
  'compensation',          -- goodwill / dispute compensation to a wallet
  'external_bank_movement',-- capital, rent, fees, profit draw (manual)
  'weight_adjustment',     -- settlement delta after weight correction
  'reversal'               -- correcting reversal of a prior event
);

-- Changed by spec 002 (product-owner decision 2026-09-26): staff roles are
-- Dashboard-managed data (Spatie `roles`), not a Postgres enum. The former
-- `staff_role` enum is removed; the six original roles are only the initial
-- seed. See docs/Technical Spec/dahab-dashboard-authorization.md §3.

-- ---------------------------------------------------------------------
-- 2. Reference data (operator-editable; the "data not code" rule)
--    Every one of these is changeable from the admin panel. Foreign keys
--    point at them so the app never contains a literal karat or branch.
-- ---------------------------------------------------------------------

-- Karats offered. Turning 20/22 on or off is a row toggle, not a release.
CREATE TABLE karat (
  karat_code   SMALLINT PRIMARY KEY,          -- 18, 20, 21, 22, 24
  purity_ratio NUMERIC(6,5) NOT NULL,         -- 0.750, 0.833, 0.875, 0.916, 0.999
  is_enabled   BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order   SMALLINT NOT NULL,
  CONSTRAINT karat_purity_range CHECK (purity_ratio > 0 AND purity_ratio <= 1)
);

CREATE TABLE piece_type (
  piece_type_id  SMALLSERIAL PRIMARY KEY,
  category       piece_category NOT NULL,
  name_en        TEXT NOT NULL,
  name_ar        TEXT NOT NULL,               -- Egyptian, no shadda
  typical_min_g  NUMERIC(10,3),
  typical_max_g  NUMERIC(10,3),
  is_enabled     BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (category, name_en)
);

-- Inspection branches. Working hours + holidays drive every deadline.
CREATE TABLE branch (
  branch_id    SMALLSERIAL PRIMARY KEY,
  name_en      TEXT NOT NULL,
  name_ar      TEXT NOT NULL,
  address_en   TEXT NOT NULL,
  address_ar   TEXT NOT NULL,
  timezone     TEXT NOT NULL DEFAULT 'Africa/Cairo',
  is_enabled   BOOLEAN NOT NULL DEFAULT TRUE
);

-- Regular weekly opening hours per branch. dow: 0=Sunday .. 6=Saturday
-- (ISO day-of-week is available too; we store our own to match Egypt's
-- Sun-Thu working week without ambiguity). Multiple rows per day allowed
-- (e.g. a lunch split) but typically one.
CREATE TABLE branch_hours (
  branch_id   SMALLINT NOT NULL REFERENCES branch(branch_id),
  dow         SMALLINT NOT NULL CHECK (dow BETWEEN 0 AND 6),
  opens_at    TIME NOT NULL,
  closes_at   TIME NOT NULL,
  PRIMARY KEY (branch_id, dow, opens_at),
  CONSTRAINT branch_hours_order CHECK (closes_at > opens_at)
);

-- Full-day closures: public holidays and one-off closures, per branch
-- (branch_id NULL = applies to all branches, e.g. a national holiday).
CREATE TABLE branch_closure (
  closure_id  SERIAL PRIMARY KEY,
  branch_id   SMALLINT REFERENCES branch(branch_id),  -- NULL = all branches
  closure_date DATE NOT NULL,
  reason_en   TEXT,
  reason_ar   TEXT,
  UNIQUE (branch_id, closure_date)
);

-- ---------------------------------------------------------------------
-- 3. Settings (single source of truth for every tunable number)
--    Typed key/value with history. The app reads the current value; the
--    UI text (e.g. "48 hours") is rendered from here, never hard-coded.
--    Changing a setting writes a new row (append-only) and is audited.
-- ---------------------------------------------------------------------
CREATE TABLE setting (
  setting_key   TEXT PRIMARY KEY,             -- e.g. 'withdrawal.account_change_pause_hours'
  value_numeric NUMERIC(18,4),
  value_text    TEXT,
  value_bool    BOOLEAN,
  unit          TEXT,                          -- 'hours','percent','egp','working_hours'
  description   TEXT NOT NULL,
  updated_by    UUID,                          -- FK added in Part 4 (staff)
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE setting_history (
  setting_history_id BIGSERIAL PRIMARY KEY,
  setting_key   TEXT NOT NULL,
  old_numeric   NUMERIC(18,4),
  new_numeric   NUMERIC(18,4),
  old_text      TEXT,
  new_text      TEXT,
  old_bool      BOOLEAN,
  new_bool      BOOLEAN,
  changed_by    UUID NOT NULL,
  changed_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Seed of the settings the blueprint fixes. Values here are defaults;
-- operators change them in the panel. (INSERTs shown for handoff clarity.)
INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
  ('commission.gold_pct',              20,     'percent',       'Commission on the making charge recovered on gold'),
  ('commission.stone_pct',             5,      'percent',       'Commission on value added above gold on stones'),
  ('commission.minimum_egp',           200,    'egp',           'Minimum commission, never broken'),
  -- Gold price corrections: Dahab quotes two prices, like any jeweller.
  -- The seller is paid at the BUY side (lower); the buyer pays the SELL
  -- side (higher). The difference, on the IGI-confirmed gold value, is the
  -- spread (Dahab margin) — this is a pricing model, NOT a commission, and
  -- is distinct from the "commission never taken from gold value" rule,
  -- which concerns the making-charge commission only. Per-gram, EGP.
  -- SCOPE: the spread applies to the 'gold' category ONLY, because gold is
  -- the only category priced off the live, moving gold rate. 'diamond' and
  -- 'gold_with_diamond' are sold at a FIXED full price the seller sets;
  -- that price does not move with the market, so there is NO spread on them
  -- — Dahab's margin on those is the stone commission (commission.stone_pct)
  -- only. These two corrections therefore feed gold pricing exclusively.
  ('price_correction.buy_side',        -15,    'egp',           'Correction per gram applied to the gold rate for the price the SELLER receives (buy side, typically negative)'),
  ('price_correction.sell_side',       15,     'egp',           'Correction per gram applied to the gold rate for the price the BUYER pays (sell side, typically positive)'),
  ('vat.pct',                          14,     'percent',       'VAT, applied to commission only'),
  ('deposit.buyer_pct',                20,     'percent',       'Buyer deposit held on a buy request'),
  ('deposit.seller_forfeit_share_pct', 50,     'percent',       'Share of a forfeited deposit paid to the seller'),
  ('deadline.seller_reply_hours',      48,     'hours',         'Seller must reply to a request within N clock hours'),
  ('deadline.reach_branch_working_hours', 12,  'working_hours', 'Seller must reach the branch within N working hours'),
  ('deadline.buyer_pay_days',          10,     'days',          'Buyer pays the balance within N days of inspection'),
  ('deadline.collect_weeks',           3,      'weeks',         'Piece waits at the branch N weeks after payment (buyer paid, not collected)'),
  ('deadline.seller_return_weeks',     3,      'weeks',         'Returned piece waits at the branch N weeks for the seller to collect (buyer did not pay)'),
  ('deadline.free_relist_working_hours', 12,   'working_hours', 'Free 0% relist window after collecting'),
  ('withdrawal.account_change_pause_hours', 48,'hours',         'Withdrawals pause N hours after a payout-account change'),
  ('inspection.weight_tolerance_pct',  1.5,    'percent',       'Weight difference auto-adjusted; above needs approval'),
  ('marketmaker.min_list_age_days',    7,      'days',          'A piece must be listed N days before a MM code can buy'),
  ('payout.first_sale_cap_egp',        100000, 'egp',           'Cap on paying a first-time gold seller before the buyer pays'),
  ('suspension.cancellations_threshold', 2,    'count',         'Seller cancellations before listing is suspended'),
  ('flag.pattern_txn_threshold',       5,      'count',         'Transactions before a pattern is flagged for review'),
  ('compensation.cap_per_payment_egp', 2000,   'egp',           'Compensation cap per payment (Finance)'),
  ('compensation.cap_per_day_egp',     5000,   'egp',           'Compensation cap per day (Finance)'),
  ('manualprice.confirm_deviation_pct',10,     'percent',       'Manual gold price above this deviation needs a second confirm');
-- NOTE: karat tolerance is intentionally NOT a number. Any karat
-- mismatch cancels the sale; that rule is enforced in code + a CHECK on
-- inspection settlement (Part 3), not a tunable threshold.


-- #####################################################################
-- BEGIN 02_schema_identity.sql
-- #####################################################################

-- =====================================================================
-- Part 1b of 4: identity, staff, customers, documents, agreements
-- search_path assumed = dahab, public
-- =====================================================================
SET search_path = dahab, public;

-- ---------------------------------------------------------------------
-- 4. Staff (internal actors). Every privileged action references one.
--    Roles map to the permission matrix in the admin-roles document.
--    Founders (ceo/coo) are unrestricted and distinguished ONLY by the
--    audit log. Wallet access starts limited to ceo + finance as an
--    application permission (spec 002: no per-staff-role DB grants).
-- ---------------------------------------------------------------------
-- Changed by spec 002: a staff member's roles live in Spatie's
-- model_has_roles; `role` and `igi_has_branch` are removed. Founder status
-- is a flag no endpoint writes; exactly one row is the non-login system
-- actor used by scheduled jobs.
CREATE TABLE staff (
  staff_id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name     TEXT NOT NULL,
  email         CITEXT UNIQUE NOT NULL,
  phone         TEXT,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  -- Optional for anyone. Branch-scoped permissions only authorize records
  -- of this branch (e.g. the shared IGI branch login, which logs the branch).
  branch_id     SMALLINT REFERENCES branch(branch_id),
  -- Founders (seeded: ceo@ and coo@). Never changeable through the API.
  is_founder    BOOLEAN NOT NULL DEFAULT FALSE,
  -- The single "System" actor for scheduled jobs; cannot sign in.
  is_system     BOOLEAN NOT NULL DEFAULT FALSE,
  created_by    UUID REFERENCES staff(staff_id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT staff_system_not_founder CHECK (NOT (is_system AND is_founder))
);
CREATE UNIQUE INDEX one_system_staff ON staff ((true)) WHERE is_system;

-- Now that staff exists, wire the settings audit FKs.
ALTER TABLE setting
  ADD CONSTRAINT setting_updated_by_fk FOREIGN KEY (updated_by) REFERENCES staff(staff_id);
ALTER TABLE setting_history
  ADD CONSTRAINT setting_hist_changed_by_fk FOREIGN KEY (changed_by) REFERENCES staff(staff_id);

-- Founder-account security: a sign-in from a new device is held until the
-- other founder confirms; either founder can freeze the other instantly.
CREATE TABLE founder_device_approval (
  approval_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  staff_id      UUID NOT NULL REFERENCES staff(staff_id),
  device_fingerprint TEXT NOT NULL,
  requested_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_by   UUID REFERENCES staff(staff_id),
  approved_at   TIMESTAMPTZ,
  CONSTRAINT approver_is_not_self CHECK (approved_by IS NULL OR approved_by <> staff_id)
);

CREATE TABLE account_freeze (
  freeze_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  frozen_staff_id UUID NOT NULL REFERENCES staff(staff_id),
  frozen_by     UUID NOT NULL REFERENCES staff(staff_id),
  frozen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Unfreeze needs BOTH founders: two confirmations recorded here.
  unfreeze_confirm_1 UUID REFERENCES staff(staff_id),
  unfreeze_confirm_2 UUID REFERENCES staff(staff_id),
  unfrozen_at   TIMESTAMPTZ,
  CONSTRAINT no_self_freeze CHECK (frozen_by <> frozen_staff_id)
);

-- ---------------------------------------------------------------------
-- 5. Customers. Browsing needs no account; verification is required
--    before the first sale or purchase. A foreign phone/passport is fine.
-- ---------------------------------------------------------------------
CREATE TABLE customer (
  customer_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  display_ref     TEXT UNIQUE NOT NULL,          -- e.g. "4417" shown in UI
  phone           TEXT UNIQUE NOT NULL,          -- sign-in identity
  email           CITEXT UNIQUE,
  full_name       TEXT,                          -- as on ID, once verified
  preferred_lang  TEXT NOT NULL DEFAULT 'ar' CHECK (preferred_lang IN ('ar','en')),
  is_verified     BOOLEAN NOT NULL DEFAULT FALSE,
  is_suspended    BOOLEAN NOT NULL DEFAULT FALSE,
  suspended_reason TEXT,
  suspended_by    UUID REFERENCES staff(staff_id),
  suspended_at    TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT suspended_needs_actor CHECK (
    NOT is_suspended OR (suspended_by IS NOT NULL AND suspended_reason IS NOT NULL)
  )
);

-- Identity documents. Photos are encrypted at rest (application-side or
-- pgcrypto); this table holds references + verification metadata, not raw
-- images in a normal column. Every VIEW of a document is logged (Part 4).
CREATE TABLE identity_document (
  document_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id     UUID NOT NULL REFERENCES customer(customer_id),
  doc_kind        TEXT NOT NULL CHECK (doc_kind IN ('egyptian_id','passport')),
  storage_ref     TEXT NOT NULL,                 -- encrypted object key
  status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','approved','rejected')),
  reviewed_by     UUID REFERENCES staff(staff_id),
  reviewed_at     TIMESTAMPTZ,
  -- After account closure, the image is deleted but a short "we checked
  -- it, and when" record survives for the legally required period.
  image_deleted_at TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 6. Versioned legal documents. Every version a customer agreed to is
--    kept forever; a customer stays bound to the version they accepted.
-- ---------------------------------------------------------------------
CREATE TABLE legal_document (
  legal_doc_id  SMALLSERIAL PRIMARY KEY,
  code          TEXT NOT NULL,                   -- 'terms','privacy','collection_auth'...
  version       INTEGER NOT NULL,
  body_en       TEXT NOT NULL,
  body_ar       TEXT NOT NULL,
  is_material   BOOLEAN NOT NULL DEFAULT FALSE,  -- material change forces re-accept
  published_by  UUID NOT NULL REFERENCES staff(staff_id),
  published_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (code, version)
);

-- Immutable record of every acceptance. This is the evidence trail.
CREATE TABLE agreement_acceptance (
  acceptance_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id   UUID NOT NULL REFERENCES customer(customer_id),
  legal_doc_id  SMALLINT NOT NULL REFERENCES legal_document(legal_doc_id),
  -- Context of the tick: 'signup','list_piece','buy_request','payout_account',
  -- 'collection_proxy','first_sale_offer'... one row per tick.
  context       TEXT NOT NULL,
  accepted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address    INET,
  device_fingerprint TEXT
);


-- #####################################################################
-- BEGIN 03_schema_ledger.sql
-- #####################################################################

-- =====================================================================
-- Part 2 of 4: the money ledger (double-entry)
--
-- WHY DOUBLE ENTRY
--   Every movement of money has two sides that must be equal: where it
--   came from (a debit somewhere) and where it went (a credit somewhere).
--   No wallet balance is ever stored and mutated. A balance is the SUM of
--   that account's postings. This makes it impossible for money to appear
--   or vanish without a trace, and it makes "bank balance minus what is
--   owed to customers" — the blueprint's most important number — exact.
--
-- MODEL
--   * account            : a bucket money can sit in (a customer's
--                          available wallet, their held wallet, Dahab's
--                          commission wallet, the escrow account, the
--                          external bank account, VAT payable, etc.)
--   * ledger_transaction : one business event (a top-up, a settlement...)
--   * ledger_posting     : one debit or credit line inside a transaction.
--                          The postings of a transaction MUST sum to zero.
--
-- SIGN CONVENTION
--   Amount is signed. A positive posting increases the account's balance,
--   a negative posting decreases it. The invariant is simply:
--       SUM(amount) OVER (one ledger_transaction) = 0
--   enforced by a constraint trigger below. This is symmetric and avoids
--   debit/credit column confusion while remaining a true double entry
--   (every value posted into one account is posted out of another).
--
-- ACCOUNT TAXONOMY
--   Customer-owned accounts (liability of Dahab to the customer):
--     - cust_available : spendable / withdrawable
--     - cust_held      : reserved against a specific open order
--   Dahab-internal accounts:
--     - escrow         : buyer balance payments held pending completion
--     - dahab_commission
--     - dahab_spread
--     - vat_payable
--     - bank           : the real bank account (mirror; reconciled daily)
--     - external_equity: capital in / profit out / rent / bank charges
--   The sum of ALL postings across ALL accounts is always zero, so the
--   whole system is self-checking.
-- =====================================================================
SET search_path = dahab, public;

CREATE TYPE account_kind AS ENUM (
  'cust_available', 'cust_held',
  'escrow', 'dahab_commission', 'dahab_spread', 'vat_payable',
  'bank', 'external_equity'
);

CREATE TABLE account (
  account_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  kind         account_kind NOT NULL,
  -- Customer-owned accounts carry the owner; internal accounts do not.
  customer_id  UUID REFERENCES customer(customer_id),
  currency     CHAR(3) NOT NULL DEFAULT 'EGP',
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT customer_accounts_have_owner CHECK (
    (kind IN ('cust_available','cust_held')) = (customer_id IS NOT NULL)
  ),
  -- One available and one held account per customer.
  CONSTRAINT one_account_per_customer_kind UNIQUE (customer_id, kind)
);

-- Exactly one row per internal account kind (singletons). Partial unique
-- index guarantees there is only one escrow, one bank, etc.
CREATE UNIQUE INDEX one_singleton_per_internal_kind
  ON account(kind) WHERE customer_id IS NULL;

CREATE TABLE ledger_transaction (
  ledger_txn_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_kind    ledger_event_kind NOT NULL,
  -- What this event is about, for traceability. Nullable because e.g. a
  -- top-up or external bank movement has no order.
  listing_id    UUID,   -- FK added in Part 3
  order_id      UUID,   -- FK added in Part 3
  buy_request_id UUID,  -- FK added in Part 3
  withdrawal_id UUID,   -- FK added in Part 3
  -- Named actor. Customer-initiated events carry the customer; staff
  -- actions carry the staff member. At least one must be present.
  customer_id   UUID REFERENCES customer(customer_id),
  staff_id      UUID REFERENCES staff(staff_id),
  memo          TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Reversals reference the transaction they reverse (corrections are new
  -- rows; nothing is ever updated or deleted).
  reverses_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
  CONSTRAINT ledger_txn_has_actor CHECK (customer_id IS NOT NULL OR staff_id IS NOT NULL)
);

CREATE TABLE ledger_posting (
  posting_id    BIGSERIAL PRIMARY KEY,
  ledger_txn_id UUID NOT NULL REFERENCES ledger_transaction(ledger_txn_id),
  account_id    UUID NOT NULL REFERENCES account(account_id),
  amount        NUMERIC(18,4) NOT NULL,          -- signed; +increases, -decreases
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT posting_nonzero CHECK (amount <> 0)
);

CREATE INDEX idx_posting_account ON ledger_posting(account_id);
CREATE INDEX idx_posting_txn     ON ledger_posting(ledger_txn_id);
CREATE INDEX idx_ledger_txn_order ON ledger_transaction(order_id);

-- ---------------------------------------------------------------------
-- APPEND-ONLY ENFORCEMENT
--   The ledger is immutable. No UPDATE or DELETE on postings or
--   transactions is ever allowed — corrections are reversals (new rows).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION block_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'append-only table: % on % is not permitted', TG_OP, TG_TABLE_NAME;
END $$;

CREATE TRIGGER ledger_posting_no_update BEFORE UPDATE OR DELETE ON ledger_posting
  FOR EACH ROW EXECUTE FUNCTION block_mutation();
CREATE TRIGGER ledger_txn_no_update BEFORE UPDATE OR DELETE ON ledger_transaction
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

-- ---------------------------------------------------------------------
-- BALANCED-TRANSACTION ENFORCEMENT
--   The postings of one ledger_transaction must sum to exactly zero.
--   Implemented as a DEFERRED constraint trigger so all postings of a
--   transaction can be inserted before the check fires at COMMIT.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION assert_txn_balanced() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  imbalance NUMERIC(18,4);
BEGIN
  SELECT COALESCE(SUM(amount),0) INTO imbalance
  FROM ledger_posting WHERE ledger_txn_id = NEW.ledger_txn_id;

  IF imbalance <> 0 THEN
    RAISE EXCEPTION 'ledger_transaction % is unbalanced by %', NEW.ledger_txn_id, imbalance;
  END IF;
  RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER trg_txn_balanced
  AFTER INSERT ON ledger_posting
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION assert_txn_balanced();

-- ---------------------------------------------------------------------
-- BALANCE VIEWS
--   Balances are derived, never stored.
-- ---------------------------------------------------------------------
CREATE VIEW account_balance AS
  SELECT a.account_id, a.kind, a.customer_id,
         COALESCE(SUM(p.amount),0) AS balance
  FROM account a
  LEFT JOIN ledger_posting p ON p.account_id = a.account_id
  GROUP BY a.account_id, a.kind, a.customer_id;

-- Customer wallet: available + held, the two figures the app shows.
CREATE VIEW customer_wallet AS
  SELECT c.customer_id,
         COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_available'),0) AS available,
         COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'),0)      AS held
  FROM customer c
  LEFT JOIN account a       ON a.customer_id = c.customer_id
  LEFT JOIN ledger_posting p ON p.account_id = a.account_id
  GROUP BY c.customer_id;

-- The blueprint's headline safety figure:
--   bank balance minus what is owed to customers (available + held).
--   If this is ever negative, customer money is short.
CREATE VIEW solvency_check AS
  SELECT
    (SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
       JOIN account a ON a.account_id=p.account_id WHERE a.kind='bank') AS bank_balance,
    (SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
       JOIN account a ON a.account_id=p.account_id
       WHERE a.kind IN ('cust_available','cust_held')) AS owed_to_customers,
    (SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
       JOIN account a ON a.account_id=p.account_id WHERE a.kind='bank')
    -
    (SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
       JOIN account a ON a.account_id=p.account_id
       WHERE a.kind IN ('cust_available','cust_held')) AS headroom;

-- Whole-system integrity: this must ALWAYS return 0.
CREATE VIEW ledger_global_zero AS
  SELECT COALESCE(SUM(amount),0) AS must_be_zero FROM ledger_posting;

-- ---------------------------------------------------------------------
-- NON-NEGATIVE WALLET GUARD
--   A customer's available balance may never go negative (you cannot
--   spend or hold more than you have). Enforced at posting time against
--   the derived balance, inside the same transaction.
--   NOTE: internal accounts (escrow, bank, equity) may be any sign.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION assert_customer_account_nonneg() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  k account_kind;
  bal NUMERIC(18,4);
BEGIN
  SELECT kind INTO k FROM account WHERE account_id = NEW.account_id;
  IF k IN ('cust_available','cust_held') THEN
    SELECT COALESCE(SUM(amount),0) INTO bal
    FROM ledger_posting WHERE account_id = NEW.account_id;
    IF bal < 0 THEN
      RAISE EXCEPTION 'customer account % would go negative (%.4f)', NEW.account_id, bal;
    END IF;
  END IF;
  RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER trg_customer_nonneg
  AFTER INSERT ON ledger_posting
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION assert_customer_account_nonneg();

-- ---------------------------------------------------------------------
-- POSTING HELPER (application calls this; never writes postings ad hoc)
--   Given a set of (account, amount) pairs that sum to zero, create one
--   ledger_transaction and its postings atomically.
--   Illustrative signature; real impl in the service layer or as a
--   SECURITY DEFINER function with tight grants.
-- ---------------------------------------------------------------------
COMMENT ON TABLE ledger_posting IS
  'Append-only. Postings are written only via the money service in balanced sets; direct UPDATE/DELETE is blocked by trigger.';


-- #####################################################################
-- BEGIN 04_schema_market.sql
-- #####################################################################

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


-- #####################################################################
-- BEGIN 05_schema_security.sql
-- #####################################################################

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
  WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

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

-- Pattern for tables added by later modules ---------------------------
-- In the SAME migration that creates the table: ENABLE + FORCE RLS and
--   CREATE POLICY <table>_isolation ON <table> FOR ALL
--     USING      (dahab_rls_elevated() OR <owner predicate>)
--     WITH CHECK (dahab_rls_elevated() OR <owner predicate>);
-- Owner predicates planned:
--   listing         seller_id = dahab_current_customer_id()
--   buy_request     buyer_id  = dahab_current_customer_id()
--   "order"         seller_id = dahab_current_customer_id() OR buyer_id = dahab_current_customer_id()
--   payout_account  customer_id = dahab_current_customer_id()
--   withdrawal      customer_id = dahab_current_customer_id()
-- CustomerTableIsolationTest fails the build for any table with a
-- customer_id / actor_customer_id / buyer_id / seller_id column that lacks
-- forced RLS and a policy.

-- NOTE: a public marketplace read of LIVE listings is served by a
-- dedicated view (or a separate policy) that exposes only non-owner-
-- sensitive columns of listings in state 'live'/'reserved'. Kept out of
-- the owner policy above so browsing does not leak seller identity.


COMMIT;
