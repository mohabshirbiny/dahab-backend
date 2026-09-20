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

CREATE TYPE staff_role AS ENUM (
  'ceo', 'coo', 'finance', 'operations', 'verification', 'igi_branch'
);

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
