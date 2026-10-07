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
  'seller_unclaimed',
  -- spec 010: rejected by the reviewer. Final, like 'withdrawn': neither has
  -- an outgoing move; the piece is sold again only as a new listing.
  'rejected'
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
  'disputed',          -- frozen while a dispute is open
  'cancelled_staff'    -- spec 011: staff cancelled the acceptance (order.cancel); final
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
  'refused',           -- spec 013: the name check failed; final
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
  'reversal',              -- correcting reversal of a prior event
  'credit_note'            -- spec 016: a credit note refunds commission + VAT
                           -- to the seller (dahab_commission -net,
                           -- vat_payable -vat, cust_available +gross)
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
  CONSTRAINT karat_purity_range CHECK (purity_ratio > 0 AND purity_ratio <= 1),
  CONSTRAINT karat_code_range CHECK (karat_code BETWEEN 1 AND 24)   -- spec 004
);

CREATE TABLE piece_type (
  piece_type_id  SMALLSERIAL PRIMARY KEY,
  category       piece_category NOT NULL,
  name_en        TEXT NOT NULL,
  name_ar        TEXT NOT NULL,               -- Egyptian, no shadda
  typical_min_g  NUMERIC(10,3),
  typical_max_g  NUMERIC(10,3),
  is_enabled     BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (category, name_en),
  CONSTRAINT piece_type_weight_order CHECK (                         -- spec 004
    typical_min_g IS NULL OR typical_max_g IS NULL OR typical_min_g <= typical_max_g
  )
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

-- Seed (spec 004): karats in every environment. 20K and 22K start off;
-- turning one on is a row toggle from the Dashboard, not a release.
INSERT INTO karat (karat_code, purity_ratio, is_enabled, sort_order) VALUES
  (24, 0.99900, TRUE,  1),
  (22, 0.91600, FALSE, 2),
  (21, 0.87500, TRUE,  3),
  (20, 0.83300, FALSE, 4),
  (18, 0.75000, TRUE,  5);

-- Seed (spec 004): piece types, from the Customer App's sell flow.
INSERT INTO piece_type (category, name_en, name_ar, typical_min_g, typical_max_g) VALUES
  ('gold', 'Ring', 'خاتم', 3, 5),
  ('gold', 'Earrings', 'حلق', 3, 6),
  ('gold', 'Chain', 'سلسلة', 8, 15),
  ('gold', 'Bangle', 'غويشة', 15, 30),
  ('gold', 'Pendant', 'دلاية', NULL, NULL),
  ('gold', 'Other', 'أخرى', NULL, NULL),
  ('diamond', 'Ring', 'خاتم', NULL, NULL),
  ('diamond', 'Earrings', 'حلق', NULL, NULL),
  ('diamond', 'Pendant', 'دلاية', NULL, NULL),
  ('diamond', 'Bridal set', 'طقم عروسة', NULL, NULL),
  ('diamond', 'Bracelet', 'أسورة', NULL, NULL),
  ('diamond', 'Other', 'أخرى', NULL, NULL),
  ('gold_with_diamond', 'Ring', 'خاتم', NULL, NULL),
  ('gold_with_diamond', 'Earrings', 'حلق', NULL, NULL),
  ('gold_with_diamond', 'Pendant', 'دلاية', NULL, NULL),
  ('gold_with_diamond', 'Bridal set', 'طقم عروسة', NULL, NULL),
  ('gold_with_diamond', 'Bracelet', 'أسورة', NULL, NULL),
  ('gold_with_diamond', 'Other', 'أخرى', NULL, NULL);
-- Branches, their hours and holidays are real operating data entered from
-- the Dashboard (spec 004); no production seed.

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
  updated_by    UUID,                          -- FK to staff in 02_schema_identity.sql (spec 005)
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
  changed_by    UUID NOT NULL,                 -- FK to staff in 02_schema_identity.sql (spec 005)
  reason        TEXT NOT NULL,                 -- (spec 005) why, shown with the change
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
  -- (spec 005) The two price_correction.* settings (-15 / +15 EGP per
  -- gram) became karat_price_adjustment (§3b): per karat and per side,
  -- fixed or percent, seeded with the same values.
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
  ('manualprice.confirm_deviation_pct',10,     'percent',       'Manual gold price above this deviation needs a second confirm'),
  ('manualprice.pending_expiry_hours', 24,     'hours',         'A manual price waiting for confirmation lapses after N hours (spec 005)'),
  ('pricefeed.stale_after_minutes',    5,      'minutes',       'The price feed counts as down after N minutes without a good reading (spec 005)'),
  ('buyrequest.price_tolerance_pct',   0.5,    'percent',       'A buy request locks the fresh price if the confirmed one is within this percent (spec 011)');
INSERT INTO setting (setting_key, value_bool, unit, description) VALUES
  ('manualprice.confirmer_must_differ', TRUE,  'bool',          'The person who confirms a manual price must differ from the one who entered it (spec 005)');
-- NOTE: karat tolerance is intentionally NOT a number. Any karat
-- mismatch cancels the sale; that rule is enforced in code + a CHECK on
-- inspection settlement (Part 3), not a tunable threshold.

-- ---------------------------------------------------------------------
-- 3b. Gold prices and per-karat adjustments (spec 005, 2026-09-27)
--     The price provider returns the 24K BID (what the market pays) and
--     ASK (what it sells for) per gram. Every other karat follows by
--     purity: price x purity / 0.999. Each karat then has a buy-side
--     adjustment (-> what sellers get) and a sell-side adjustment
--     (-> what buyers pay), each a fixed EGP amount per gram or a
--     percentage. These replace the two price_correction.* settings.
--     Staff FKs are added in 02_schema_identity.sql (staff comes later).
-- ---------------------------------------------------------------------

-- A manual price request: entered while the feed is down; above
-- manualprice.confirm_deviation_pct it waits for a confirmation by a
-- holder of the confirm permission (and, when
-- manualprice.confirmer_must_differ is on, by a different person).
-- Only the status moves forward (pending -> effective|superseded|lapsed).
CREATE TABLE manual_gold_price (
  manual_gold_price_id   BIGSERIAL PRIMARY KEY,
  bid_24k                NUMERIC(18,4) NOT NULL,
  ask_24k                NUMERIC(18,4) NOT NULL,
  previous_gold_price_id BIGINT,                       -- FK below
  deviation_pct          NUMERIC(8,4),                 -- NULL when there was no price before
  requires_confirmation  BOOLEAN NOT NULL,
  reason                 TEXT NOT NULL,
  entered_by             UUID NOT NULL,                -- FK staff (02)
  status                 TEXT NOT NULL CHECK (status IN ('pending','effective','superseded','lapsed')),
  confirmed_by           UUID,                         -- FK staff (02)
  confirmed_at           TIMESTAMPTZ,
  expires_at             TIMESTAMPTZ,                  -- pending only
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT manual_gold_price_positive CHECK (bid_24k > 0 AND ask_24k >= bid_24k),
  CONSTRAINT manual_gold_price_confirmed CHECK ((confirmed_by IS NULL) = (confirmed_at IS NULL))
);
-- At most one request waits for confirmation at a time.
CREATE UNIQUE INDEX manual_gold_price_one_pending ON manual_gold_price ((true)) WHERE status = 'pending';

-- Append-only: one row per price that took effect (feed or manual).
-- The current price is the latest by effective_at. Never updated or deleted.
CREATE TABLE gold_price (
  gold_price_id        BIGSERIAL PRIMARY KEY,
  source               TEXT NOT NULL CHECK (source IN ('feed','manual')),
  bid_24k              NUMERIC(18,4) NOT NULL,
  ask_24k              NUMERIC(18,4) NOT NULL,
  manual_gold_price_id BIGINT REFERENCES manual_gold_price(manual_gold_price_id),
  recorded_by          UUID NOT NULL,                  -- FK staff (02): the system actor for the feed
  effective_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT gold_price_positive CHECK (bid_24k > 0 AND ask_24k >= bid_24k),
  CONSTRAINT gold_price_manual_link CHECK (source <> 'manual' OR manual_gold_price_id IS NOT NULL)
);
CREATE INDEX gold_price_current ON gold_price (effective_at DESC, gold_price_id DESC);
ALTER TABLE manual_gold_price
  ADD CONSTRAINT manual_gold_price_previous FOREIGN KEY (previous_gold_price_id) REFERENCES gold_price(gold_price_id);

-- Per karat, per side. side 'buy' = what sellers get (applied to the bid);
-- 'sell' = what buyers pay (applied to the ask). kind 'fixed' = EGP per
-- gram of that karat; 'percent' = x (1 + value/100).
CREATE TABLE karat_price_adjustment (
  karat_code  SMALLINT NOT NULL REFERENCES karat(karat_code),
  side        TEXT NOT NULL CHECK (side IN ('buy','sell')),
  kind        TEXT NOT NULL CHECK (kind IN ('fixed','percent')),
  value       NUMERIC(18,4) NOT NULL,
  updated_by  UUID,                                    -- FK staff (02); NULL for the seed
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (karat_code, side),
  CONSTRAINT karat_price_adjustment_percent CHECK (kind <> 'percent' OR value > -100)
);

-- Append-only history of adjustment changes.
CREATE TABLE karat_price_adjustment_history (
  history_id  BIGSERIAL PRIMARY KEY,
  karat_code  SMALLINT NOT NULL REFERENCES karat(karat_code),
  side        TEXT NOT NULL CHECK (side IN ('buy','sell')),
  old_kind    TEXT NOT NULL,
  old_value   NUMERIC(18,4) NOT NULL,
  new_kind    TEXT NOT NULL,
  new_value   NUMERIC(18,4) NOT NULL,
  changed_by  UUID NOT NULL,                           -- FK staff (02)
  reason      TEXT NOT NULL,
  changed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Seed: the schema's former price corrections, for every karat.
-- A karat added later starts with fixed 0 on both sides.
INSERT INTO karat_price_adjustment (karat_code, side, kind, value)
SELECT karat_code, 'buy',  'fixed', -15 FROM karat
UNION ALL
SELECT karat_code, 'sell', 'fixed',  15 FROM karat;

-- The price feed's health: one row, written every minute. The feed is
-- "down" when not configured or when last_success_at is older than
-- pricefeed.stale_after_minutes; manual prices are accepted only then.
CREATE TABLE price_feed_status (
  provider         TEXT PRIMARY KEY,
  last_success_at  TIMESTAMPTZ,
  last_failure_at  TIMESTAMPTZ,
  last_error       TEXT
);

-- gold_price, setting_history and karat_price_adjustment_history refuse
-- UPDATE and DELETE with a trigger (same pattern as audit_log).


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

-- ---------------------------------------------------------------------
-- Staff foreign keys for the settings and pricing tables of
-- 01_schema_core.sql §3 / §3b (spec 005). Added here because staff is
-- created after the core tables.
-- ---------------------------------------------------------------------
ALTER TABLE setting                        ADD FOREIGN KEY (updated_by)   REFERENCES staff(staff_id);
ALTER TABLE setting_history                ADD FOREIGN KEY (changed_by)   REFERENCES staff(staff_id);
ALTER TABLE manual_gold_price              ADD FOREIGN KEY (entered_by)   REFERENCES staff(staff_id);
ALTER TABLE manual_gold_price              ADD FOREIGN KEY (confirmed_by) REFERENCES staff(staff_id);
ALTER TABLE gold_price                     ADD FOREIGN KEY (recorded_by)  REFERENCES staff(staff_id);
ALTER TABLE karat_price_adjustment         ADD FOREIGN KEY (updated_by)   REFERENCES staff(staff_id);
ALTER TABLE karat_price_adjustment_history ADD FOREIGN KEY (changed_by)   REFERENCES staff(staff_id);
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

-- (spec 007) Suspension details. `status` (pending_verification | active |
-- rejected | suspended) is the lifecycle column added by migration
-- 2026_09_20_000010. A suspension remembers the state it interrupted and
-- reinstating returns to exactly that state; the staff note is never shown
-- to the customer. Reasons are the fixed list of Part 1 §4.3.
ALTER TABLE customer
  ADD COLUMN suspended_note TEXT,
  ADD COLUMN status_before_suspension TEXT
    CHECK (status_before_suspension IN ('pending_verification','active','rejected')),
  ADD CONSTRAINT customer_suspension_state CHECK (
    (status = 'suspended') = (status_before_suspension IS NOT NULL)
  ),
  ADD CONSTRAINT customer_suspended_reason_check CHECK (
    suspended_reason IS NULL OR suspended_reason IN (
      'piece_misrepresented','off_platform_dealing','repeated_disputes',
      'reported_by_users','identity_unconfirmed','customer_request','other',
      'repeated_cancellations')  -- spec 012: set only by the system (cancellation threshold)
  );

-- spec 012: seller cancellations count toward suspension.cancellations_threshold
-- from this moment (set at reinstatement, the database clock).
ALTER TABLE customer ADD COLUMN cancellations_reset_at TIMESTAMPTZ;

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
  -- spec 014: 'collection_proxy' accepts the legal document
  -- 'collection_proxy_authorisation' (v1 seeded by the disputes migration).
  -- The acceptance a proxy was named under is collection.proxy_acceptance_id.
  context       TEXT NOT NULL,
  accepted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address    INET,
  device_fingerprint TEXT
);

-- As built by spec 010 ----------------------------------------------------
-- Both tables are created unchanged. An acceptance is evidence: append-only.
CREATE OR REPLACE FUNCTION agreement_acceptance_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'agreement acceptances are append-only';
END $$;

CREATE TRIGGER trg_agreement_acceptance_immutable
  BEFORE UPDATE OR DELETE ON agreement_acceptance
  FOR EACH ROW EXECUTE FUNCTION agreement_acceptance_immutable();

CREATE INDEX idx_agreement_acceptance_customer ON agreement_acceptance(customer_id, accepted_at);

-- A customer sees only their own acceptances (forced RLS, spec 003 pattern).
ALTER TABLE agreement_acceptance ENABLE ROW LEVEL SECURITY;
ALTER TABLE agreement_acceptance FORCE  ROW LEVEL SECURITY;
CREATE POLICY agreement_acceptance_isolation ON agreement_acceptance FOR ALL
  USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
  WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

-- Seed: the ownership declaration ticked when listing a piece, version 1,
-- published by the system actor (no Dashboard document management yet).
INSERT INTO legal_document (code, version, body_en, body_ar, is_material, published_by)
-- spec 011 also seeds 'deposit_agreement' version 1 (accepted with every buy
-- request, context 'buy_request'; no fixed percentage, draft for the legal clinic).
SELECT 'ownership_declaration', 1,
       'I confirm this piece is mine to sell and the details above are accurate.',
       'أقر أن القطعة دي ملكي ومن حقي أبيعها، وأن البيانات اللي فوق صحيحة.',
       FALSE, staff_id
FROM staff WHERE is_system = TRUE;


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
--
-- SIGN OF THE BANK ACCOUNT (spec 008, research R15)
--   Signed amounts summing to zero are a true double entry: a negative
--   posting is a debit, a positive posting a credit. `bank` is the only
--   asset account, so its ledger balance is the NEGATIVE of the cash it
--   represents. Money arriving: bank -X, customer +X. Money leaving
--   (withdrawal release): customer hold -X, bank +X. Cash in the bank =
--   -SUM(bank postings); every screen shows the cash (positive).
--   Customer, dahab_*, vat_payable and external_equity balances are
--   shown as they are.
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
  order_id      UUID,   -- FK lt_order_fk added by spec 011; spec 012: one balance_payment and one deposit_forfeit per order (unique indexes one_balance_payment_per_order, one_deposit_forfeit_per_order); a deposit_release may follow an accepted request only once its order is cancelled_staff, cancelled_seller or cancelled_inspection (deposit_release_allowed())
  buy_request_id UUID,  -- FK lt_request_fk added by spec 011 (deferred); one deposit_hold and at most one deposit_release per request (unique indexes)
  withdrawal_id UUID,   -- FK lt_withdrawal_fk added by spec 013 (deferred); a withdrawal's entries are a hold (customer available -X, held +X), then one release (held -X, bank +X) or one return (held -X, available +X), all event_kind = withdrawal
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

-- Changed by spec 008: the account index covers amount and txn so balances
-- and histories are index-only reads (research R8).
CREATE INDEX idx_posting_account ON ledger_posting(account_id, posting_id) INCLUDE (amount, ledger_txn_id);
CREATE INDEX idx_posting_txn     ON ledger_posting(ledger_txn_id);
CREATE INDEX idx_ledger_txn_order ON ledger_transaction(order_id);
-- Added by spec 008: statement periods (R8), and an entry is reversed at
-- most once (FR-010).
CREATE INDEX idx_ledger_txn_created ON ledger_transaction(created_at);
CREATE UNIQUE INDEX one_reversal_per_txn ON ledger_transaction(reverses_txn_id)
  WHERE reverses_txn_id IS NOT NULL;

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
-- Changed by spec 008:
--   * The check reads under the transaction-local 'ledger' RLS scope and
--     restores the caller's scope: it fires at COMMIT, when a customer
--     scope would otherwise hide the internal accounts' lines and report
--     a false imbalance (research R3).
--   * Stable SQLSTATE DH002 so the application can recognise it (R6).
CREATE OR REPLACE FUNCTION assert_txn_balanced() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  imbalance NUMERIC(18,4);
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
BEGIN
  PERFORM set_config('app.rls_scope', 'ledger', true);
  SELECT COALESCE(SUM(amount),0) INTO imbalance
  FROM ledger_posting WHERE ledger_txn_id = NEW.ledger_txn_id;
  PERFORM set_config('app.rls_scope', prev_scope, true);

  IF imbalance <> 0 THEN
    RAISE EXCEPTION 'ledger_transaction % is unbalanced by %', NEW.ledger_txn_id, imbalance
      USING ERRCODE = 'DH002';
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
--   Changed by spec 008 (research R15): bank_balance is the CASH in the
--   bank, i.e. the negation of the bank account's ledger balance (see
--   SIGN OF THE BANK ACCOUNT above). The earlier form used the raw sum
--   and reported -cash - owed.
CREATE VIEW solvency_check AS
  SELECT
    -(SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
       JOIN account a ON a.account_id=p.account_id WHERE a.kind='bank') AS bank_balance,
    (SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
       JOIN account a ON a.account_id=p.account_id
       WHERE a.kind IN ('cust_available','cust_held')) AS owed_to_customers,
    -(SELECT COALESCE(SUM(amount),0) FROM ledger_posting p
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
-- Changed by spec 008: reads under the 'ledger' scope like the balance
-- check, and raises SQLSTATE DH001 (mapped to 409 insufficient_funds).
-- Concurrency: this deferred check alone cannot stop two concurrent
-- transactions from each spending the same balance (each sees only its
-- own uncommitted lines). The money service therefore locks the touched
-- customer account rows FOR UPDATE, in account_id order, before posting
-- (research R5). This trigger is the backstop.
CREATE OR REPLACE FUNCTION assert_customer_account_nonneg() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  k account_kind;
  bal NUMERIC(18,4);
  prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
BEGIN
  PERFORM set_config('app.rls_scope', 'ledger', true);
  SELECT kind INTO k FROM account WHERE account_id = NEW.account_id;
  IF k IN ('cust_available','cust_held') THEN
    SELECT COALESCE(SUM(amount),0) INTO bal
    FROM ledger_posting WHERE account_id = NEW.account_id;
  END IF;
  PERFORM set_config('app.rls_scope', prev_scope, true);

  IF k IN ('cust_available','cust_held') AND bal < 0 THEN
    RAISE EXCEPTION 'customer account % would go negative (%)', NEW.account_id, bal
      USING ERRCODE = 'DH001';
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
--   Built by spec 008 as App\Actions\Ledger\PostLedgerEntryAction (and
--   ReverseLedgerEntryAction): validates the set, locks the customer
--   accounts, and writes inside the caller's transaction under the
--   'ledger' RLS scope.
-- ---------------------------------------------------------------------
-- ---------------------------------------------------------------------
-- ACCOUNT PROVISIONING (added by spec 008, research R4)
--   Every customer gets both accounts in the same transaction that
--   creates the customer, whatever creates it (registration, seeders,
--   factories). Existing customers are backfilled by the migration with
--   the same INSERT ... ON CONFLICT DO NOTHING. The internal singletons
--   are seeded once.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION create_customer_accounts() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO account (kind, customer_id)
  VALUES ('cust_available', NEW.customer_id), ('cust_held', NEW.customer_id)
  ON CONFLICT (customer_id, kind) DO NOTHING;
  RETURN NULL;
END $$;

CREATE TRIGGER trg_customer_accounts AFTER INSERT ON customer
  FOR EACH ROW EXECUTE FUNCTION create_customer_accounts();

INSERT INTO account (kind)
SELECT k::account_kind FROM unnest(ARRAY['escrow','dahab_commission','dahab_spread',
  'vat_payable','bank','external_equity']) AS k
ON CONFLICT DO NOTHING;

COMMENT ON TABLE ledger_posting IS
  'Append-only. Postings are written only via the money service in balanced sets; direct UPDATE/DELETE is blocked by trigger.';

-- ---------------------------------------------------------------------
-- TOP-UPS (added by spec 009, specs/009-wallet-topup/data-model.md)
--   Money enters only by a manual transfer to one of Dahab's receiving
--   accounts (bank transfer, InstaPay, Vodafone Cash) — never a gateway.
--   The customer files a notice; staff who see the money in Dahab's own
--   bank or wallet app match it (credit what actually arrived), or put it
--   on hold, or reject it; money with no notice is credited by hand.
--   A credit posts one ledger entry through the money service:
--     event_kind = 'topup', bank -amount, customer cust_available +amount.
--   topup.ledger_txn_id is UNIQUE: a notice is credited at most once.
--   Final states (credited, rejected, cancelled) are frozen by trigger;
--   rows are never deleted.
-- ---------------------------------------------------------------------
CREATE TYPE topup_method        AS ENUM ('bank_transfer', 'instapay', 'vodafone_cash');
CREATE TYPE topup_origin        AS ENUM ('notice', 'by_hand');
CREATE TYPE topup_status        AS ENUM ('pending', 'on_hold', 'credited', 'rejected', 'cancelled');
CREATE TYPE topup_reject_reason AS ENUM ('money_not_received', 'duplicate_notice', 'sender_not_accepted', 'other');

-- Dahab's accounts that customers send money to. Reference data (no RLS),
-- managed from the Dashboard; deactivated, never deleted. daily_limit and
-- provider_fee_percent are display-only: Dahab never checks or computes
-- with them (staff credit what actually arrived).
CREATE TABLE receiving_account (
  receiving_account_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  method               topup_method NOT NULL,
  label                TEXT NOT NULL CHECK (char_length(label) BETWEEN 1 AND 80),
  bank_name            TEXT,
  account_holder       TEXT,
  account_number       TEXT CHECK (account_number ~ '^[0-9 ]{6,34}$'),
  iban                 TEXT CHECK (iban ~ '^EG[0-9]{27}$'),
  instapay_address     TEXT,
  wallet_number        TEXT CHECK (wallet_number ~ '^01[0125][0-9]{8}$'),
  daily_limit          NUMERIC(14,2) CHECK (daily_limit > 0),
  provider_fee_percent NUMERIC(5,3) CHECK (provider_fee_percent BETWEEN 0 AND 100),
  customer_note        TEXT CHECK (char_length(customer_note) <= 300),
  sort_order           SMALLINT NOT NULL DEFAULT 0,
  is_active            BOOLEAN NOT NULL DEFAULT true,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_by           UUID NOT NULL REFERENCES staff(staff_id),
  -- Each method carries exactly its own details.
  CONSTRAINT receiving_account_details CHECK (
    (method = 'bank_transfer' AND bank_name IS NOT NULL AND account_holder IS NOT NULL
       AND account_number IS NOT NULL AND instapay_address IS NULL AND wallet_number IS NULL)
    OR (method = 'instapay' AND instapay_address IS NOT NULL AND bank_name IS NULL
       AND account_number IS NULL AND iban IS NULL AND wallet_number IS NULL)
    OR (method = 'vodafone_cash' AND wallet_number IS NOT NULL AND bank_name IS NULL
       AND account_number IS NULL AND iban IS NULL AND instapay_address IS NULL)
  )
);
CREATE INDEX idx_receiving_account_list ON receiving_account(method, is_active, sort_order);

-- A transfer notice (origin 'notice') or a hand credit (origin 'by_hand').
CREATE TABLE topup (
  topup_id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  topup_no             BIGINT GENERATED BY DEFAULT AS IDENTITY UNIQUE,   -- shown as TOP-{n}; a hand credit reserves it with nextval() to name it in the ledger memo
  customer_id          UUID NOT NULL REFERENCES customer(customer_id),
  origin               topup_origin NOT NULL,
  method               topup_method NOT NULL,
  reference            TEXT NOT NULL,          -- 'DAHAB-' || customer.display_ref at creation
  claimed_amount       NUMERIC(18,4),          -- notice only
  notice_account_id    BIGINT REFERENCES receiving_account(receiving_account_id),
  notice_fee_percent   NUMERIC(5,3) CHECK (notice_fee_percent BETWEEN 0 AND 100),  -- the account's provider_fee_percent when the notice was filed (display-only snapshot, basis of expected_amount)
  receipt_ref          TEXT,                   -- private encrypted storage key
  receipt_mime         TEXT,
  status               topup_status NOT NULL DEFAULT 'pending',
  submitted_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  hold_note            TEXT CHECK (char_length(hold_note) <= 1000),
  held_by              UUID REFERENCES staff(staff_id),
  held_at              TIMESTAMPTZ,
  reject_reason        topup_reject_reason,
  reject_note          TEXT CHECK (char_length(reject_note) <= 1000),
  rejected_by          UUID REFERENCES staff(staff_id),
  rejected_at          TIMESTAMPTZ,
  cancelled_at         TIMESTAMPTZ,
  credited_amount      NUMERIC(18,4),
  receiving_account_id BIGINT REFERENCES receiving_account(receiving_account_id),
  credit_note          TEXT CHECK (char_length(credit_note) <= 1000),
  -- The provider's transaction reference for the arrival. Required by the
  -- application for any credit while the customer is suspended (spec 009
  -- FR-016, FR-018); it depends on the customer's status, so not a CHECK.
  arrival_reference    TEXT CHECK (char_length(arrival_reference) <= 100),
  credited_by          UUID REFERENCES staff(staff_id),
  credited_at          TIMESTAMPTZ,
  ledger_txn_id        UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT topup_amounts CHECK (
    (claimed_amount IS NULL OR (claimed_amount > 0 AND claimed_amount = round(claimed_amount, 2)))
    AND (credited_amount IS NULL OR (credited_amount > 0 AND credited_amount = round(credited_amount, 2)))
  ),
  CONSTRAINT topup_origin_shape CHECK (
    (origin = 'notice' AND claimed_amount IS NOT NULL)
    OR (origin = 'by_hand' AND status = 'credited' AND claimed_amount IS NULL
        AND notice_account_id IS NULL AND receipt_ref IS NULL AND credit_note IS NOT NULL)
  ),
  CONSTRAINT topup_credited_shape CHECK (
    (status = 'credited') = (credited_amount IS NOT NULL AND receiving_account_id IS NOT NULL
      AND credited_by IS NOT NULL AND credited_at IS NOT NULL AND ledger_txn_id IS NOT NULL)
  ),
  CONSTRAINT topup_rejected_shape CHECK (
    (status = 'rejected') = (reject_reason IS NOT NULL AND reject_note IS NOT NULL
      AND rejected_by IS NOT NULL AND rejected_at IS NOT NULL)
  ),
  CONSTRAINT topup_hold_shape CHECK (
    status <> 'on_hold' OR (hold_note IS NOT NULL AND held_by IS NOT NULL AND held_at IS NOT NULL)
  ),
  CONSTRAINT topup_cancelled_shape CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
  -- Crediting a different amount than claimed needs a written reason.
  CONSTRAINT topup_difference_explained CHECK (
    status <> 'credited' OR origin = 'by_hand' OR credited_amount = claimed_amount OR credit_note IS NOT NULL
  ),
  CONSTRAINT topup_receipt_pair CHECK ((receipt_ref IS NULL) = (receipt_mime IS NULL)),
  CONSTRAINT topup_fee_on_notice CHECK (origin = 'notice' OR notice_fee_percent IS NULL)
);
CREATE INDEX idx_topup_status_list   ON topup(status, topup_no DESC);   -- topup_no rises with submission time: list order + keyset
CREATE INDEX idx_topup_customer_list ON topup(customer_id, topup_no DESC);

-- Final states are frozen, identity columns never change, rows are never
-- deleted. SQLSTATE DH003 -> 409 illegal_topup_transition.
CREATE OR REPLACE FUNCTION topup_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'top-up records are never deleted' USING ERRCODE = 'DH003';
  END IF;
  IF OLD.status IN ('credited', 'rejected', 'cancelled') THEN
    RAISE EXCEPTION 'top-up % is % and cannot change', OLD.topup_id, OLD.status USING ERRCODE = 'DH003';
  END IF;
  IF NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.origin IS DISTINCT FROM OLD.origin
     OR NEW.reference IS DISTINCT FROM OLD.reference OR NEW.claimed_amount IS DISTINCT FROM OLD.claimed_amount
     OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.topup_no IS DISTINCT FROM OLD.topup_no
     OR NEW.notice_fee_percent IS DISTINCT FROM OLD.notice_fee_percent THEN
    RAISE EXCEPTION 'top-up % identity columns cannot change', OLD.topup_id USING ERRCODE = 'DH003';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER trg_topup_guard     BEFORE UPDATE ON topup FOR EACH ROW EXECUTE FUNCTION topup_guard();
CREATE TRIGGER trg_topup_no_delete BEFORE DELETE ON topup FOR EACH ROW EXECUTE FUNCTION topup_guard();

CREATE OR REPLACE FUNCTION receiving_account_no_delete() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'receiving accounts are deactivated, never deleted' USING ERRCODE = 'DH003';
END $$;

CREATE TRIGGER trg_receiving_account_no_delete BEFORE DELETE ON receiving_account
  FOR EACH ROW EXECUTE FUNCTION receiving_account_no_delete();

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
-- (spec 006) The audit log viewer lists newest first and pages by
-- (created_at, audit_id); this index serves both.
CREATE INDEX idx_audit_created_id ON audit_log(created_at DESC, audit_id DESC);
-- (spec 007) A customer file's History lists what the customer did, newest first.
CREATE INDEX idx_audit_actor_customer ON audit_log(actor_customer_id, created_at DESC)
  WHERE actor_customer_id IS NOT NULL;

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
-- Changed by spec 014 (specs/014-disputes): a party (buyer or seller) opens a
-- dispute on an order at_inspection / weight_adjust_pending / awaiting_balance /
-- ready_to_collect; the order freezes into 'disputed'. One dispute per party per
-- order, never two unresolved. Resolution: 'resume' (back to frozen_from, every
-- running deadline pushed by the frozen time) or, before payment only,
-- 'against_sale' (cancelled_inspection, deposit refunded, piece returned).
CREATE SEQUENCE dispute_no_seq;

CREATE TABLE dispute (
  dispute_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  dispute_no   BIGINT UNIQUE NOT NULL DEFAULT nextval('dispute_no_seq'),   -- spec 014
  dispute_ref  TEXT UNIQUE NOT NULL,             -- 'DSP-' || dispute_no (set by trigger, spec 014)
  order_id     UUID NOT NULL REFERENCES "order"(order_id),
  raised_by    UUID NOT NULL REFERENCES customer(customer_id),
  raised_as    TEXT NOT NULL CHECK (raised_as IN ('buyer','seller')),       -- spec 014; matches the order (trigger)
  reason       TEXT NOT NULL CHECK (reason IN ('not_as_listed','disagree_inspection','money_wrong',
                 'other_side_unresponsive','not_theirs_to_sell','other')),   -- spec 014
  detail       TEXT NOT NULL CHECK (char_length(detail) BETWEEN 10 AND 2000), -- spec 014: required
  state        TEXT NOT NULL DEFAULT 'open'
                 CHECK (state IN ('open','passed_on','resolved')),
  assigned_to  UUID REFERENCES staff(staff_id),
  passed_on_at TIMESTAMPTZ,                                                  -- spec 014
  frozen_from  order_state NOT NULL CHECK (frozen_from IN
                 ('at_inspection','weight_adjust_pending','awaiting_balance','ready_to_collect')), -- spec 014
  frozen_at    TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),               -- spec 014
  outcome      TEXT CHECK (outcome IN ('resume','against_sale')),            -- spec 014
  -- A dispute cannot be closed silently: resolution needs a reply or a
  -- named colleague it was passed to.
  resolution_reply TEXT CHECK (resolution_reply IS NULL OR char_length(resolution_reply) BETWEEN 10 AND 2000),
  resolved_by  UUID REFERENCES staff(staff_id),
  opened_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  resolved_at  TIMESTAMPTZ,
  release_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),          -- spec 014: deposit refund on against_sale
  CONSTRAINT resolved_needs_reply CHECK (
    state <> 'resolved' OR (resolution_reply IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL)
  ),
  CONSTRAINT dispute_outcome_when_resolved CHECK ((state = 'resolved') = (outcome IS NOT NULL)),       -- spec 014
  CONSTRAINT dispute_against_sale_before_payment CHECK (
    outcome IS DISTINCT FROM 'against_sale' OR frozen_from <> 'ready_to_collect'),                     -- spec 014
  CONSTRAINT dispute_buyer_only_reason CHECK (reason <> 'not_theirs_to_sell' OR raised_as = 'buyer'),  -- spec 014
  CONSTRAINT dispute_one_per_party UNIQUE (order_id, raised_by)                                        -- spec 014
);
CREATE UNIQUE INDEX uq_dispute_one_unresolved ON dispute(order_id) WHERE state <> 'resolved';      -- spec 014
CREATE INDEX idx_dispute_queue    ON dispute(state, opened_at);
CREATE INDEX idx_dispute_assigned ON dispute(assigned_to) WHERE state = 'passed_on';
CREATE INDEX idx_dispute_raiser   ON dispute(raised_by);
ALTER TABLE order_deadline_extension
  ADD CONSTRAINT order_deadline_extension_dispute_fk FOREIGN KEY (dispute_id) REFERENCES dispute(dispute_id);  -- spec 014

-- Spec 014: 0-5 private photos per dispute (upload purpose 'dispute_photo',
-- encrypted on the private disk). Append-only.
CREATE TABLE dispute_photo (
  photo_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  dispute_id   UUID NOT NULL REFERENCES dispute(dispute_id),
  storage_ref  TEXT NOT NULL,
  mime         TEXT NOT NULL,
  position     SMALLINT NOT NULL CHECK (position BETWEEN 1 AND 5),
  UNIQUE (dispute_id, position)
);

-- Spec 014: the dispute's history (opened / passed_on / resolved), exactly one
-- actor per row; pass-on notes are staff-only. Append-only; a deferred check
-- requires a row for every dispute state change in the same transaction.
CREATE TABLE dispute_change (
  change_id    BIGSERIAL PRIMARY KEY,
  dispute_id   UUID NOT NULL REFERENCES dispute(dispute_id),
  kind         TEXT NOT NULL CHECK (kind IN ('opened','passed_on','resolved')),
  actor_customer_id UUID REFERENCES customer(customer_id),
  actor_staff_id    UUID REFERENCES staff(staff_id),
  assigned_to  UUID REFERENCES staff(staff_id),
  note         TEXT CHECK (note IS NULL OR char_length(note) BETWEEN 10 AND 2000),
  at           TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  txid         BIGINT NOT NULL DEFAULT txid_current(),
  CONSTRAINT dispute_change_one_actor CHECK ((actor_customer_id IS NULL) <> (actor_staff_id IS NULL))
);

-- Spec 014: compensation paid within a dispute resolution (ledger kind
-- 'compensation': external_equity -> the customer's available). Caps
-- compensation.cap_per_payment_egp / cap_per_day_egp per staff member per Cairo
-- day unless the payer holds compensation.uncapped. Append-only; a deferred
-- check ties each row to its matching entry.
-- Changed by spec 015: compensation may also be paid outside a dispute (the
-- Compensation page, same permission and caps): dispute, order and party are
-- optional; a dispute payment still names its order and party.
CREATE TABLE compensation (
  compensation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  dispute_id   UUID REFERENCES dispute(dispute_id),
  order_id     UUID REFERENCES "order"(order_id),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  party        TEXT CHECK (party IN ('buyer','seller')),
  amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
  reason       TEXT NOT NULL CHECK (reason IN ('igi_delay','dahab_mistake','wasted_trip','dispute_settlement','goodwill')),
  note         TEXT NOT NULL CHECK (char_length(note) BETWEEN 10 AND 1000),
  paid_by      UUID NOT NULL REFERENCES staff(staff_id),
  ledger_txn_id UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
  paid_at      TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  -- spec 015
  CONSTRAINT compensation_dispute_needs_order CHECK (dispute_id IS NULL OR order_id IS NOT NULL),
  CONSTRAINT compensation_party_with_order CHECK ((order_id IS NULL) = (party IS NULL))
);
CREATE INDEX idx_compensation_payer_day ON compensation(paid_by, paid_at);
CREATE INDEX idx_compensation_paid_at ON compensation(paid_at, compensation_id);  -- spec 015: the list

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
--
--     As built by spec 015 (specs/015-finance-ops):
--     * bank_movement.kind is the Dashboard design's seven; rent is an
--       operating expense. amount is signed (+ into the bank, - out). Each
--       movement is one 'external_bank_movement' entry bank <-> external_equity
--       (money in: bank -X, external_equity +X; out: the reverse — the bank
--       sign rule of 03), except own_transfer (between Dahab's own accounts),
--       which is a record only. Proof is an optional staff upload, encrypted.
--     * daily_close: bank_balance is TYPED from the statements (the closing
--       balance across all of Dahab's accounts); books_bank is the ledger's
--       bank cash at the day's end (midnight Africa/Cairo); difference =
--       bank_balance - books_bank. A day is closed only after it ended. 0
--       locks it; a non-zero difference locks it only with an explanation,
--       otherwise the row is saved unlocked and may be closed again.
--     * wallet_adjustment: the CEO's (wallet.adjust) correction of one
--       customer's available balance — ledger kind 'reversal' with no reversed
--       entry, cust_available +/-X against external_equity -/+X.
-- ---------------------------------------------------------------------
CREATE SEQUENCE bank_movement_no_seq;

CREATE TABLE bank_movement (
  movement_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  movement_no  BIGINT NOT NULL UNIQUE DEFAULT nextval('bank_movement_no_seq'),  -- shown BM-{n}
  kind         TEXT NOT NULL CHECK (kind IN ('capital_in','operating_expense','bank_charge',
                 'profit_draw','own_transfer','supplier_refund','other')),
  amount       NUMERIC(18,4) NOT NULL CHECK (amount <> 0),   -- signed: + in, - out
  occurred_on  DATE NOT NULL,                    -- the date on the bank statement
  reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 500),
  proof_ref    TEXT,                             -- encrypted staff upload
  proof_mime   TEXT,
  recorded_by  UUID NOT NULL REFERENCES staff(staff_id),  -- bank.record
  ledger_txn_id UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
  recorded_at  TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
  CONSTRAINT bank_movement_entry_unless_own_transfer CHECK ((kind = 'own_transfer') = (ledger_txn_id IS NULL)),
  CONSTRAINT bank_movement_proof_pair CHECK ((proof_ref IS NULL) = (proof_mime IS NULL))
);
CREATE INDEX idx_bank_movement_recorded ON bank_movement(recorded_at, movement_id);
CREATE INDEX idx_bank_movement_occurred ON bank_movement(occurred_on);
CREATE TRIGGER trg_bank_movement_immutable BEFORE UPDATE OR DELETE ON bank_movement
  FOR EACH ROW EXECUTE FUNCTION block_mutation();

CREATE TABLE daily_close (
  close_date   DATE PRIMARY KEY,
  bank_balance NUMERIC(18,4) NOT NULL,           -- typed from the statements
  books_bank   NUMERIC(18,4) NOT NULL,           -- ledger bank cash at the cut-off (spec 015)
  customer_available NUMERIC(18,4) NOT NULL,     -- spec 015
  customer_held      NUMERIC(18,4) NOT NULL,     -- spec 015
  customer_liability NUMERIC(18,4) NOT NULL,
  dahab_wallet NUMERIC(18,4) NOT NULL,           -- commission + spread
  escrow       NUMERIC(18,4) NOT NULL,           -- spec 015
  vat_payable  NUMERIC(18,4) NOT NULL,           -- spec 015
  movements_in  NUMERIC(18,4) NOT NULL,          -- hand-recorded, dated that day (spec 015)
  movements_out NUMERIC(18,4) NOT NULL,
  difference   NUMERIC(18,4) NOT NULL,
  explanation  TEXT CHECK (explanation IS NULL OR char_length(explanation) BETWEEN 10 AND 1000),
  is_locked    BOOLEAN NOT NULL DEFAULT FALSE,
  saved_by     UUID NOT NULL REFERENCES staff(staff_id),   -- spec 015
  saved_at     TIMESTAMPTZ NOT NULL,
  closed_by    UUID REFERENCES staff(staff_id),
  closed_at    TIMESTAMPTZ,
  CONSTRAINT daily_close_locked_named CHECK (NOT is_locked OR (closed_by IS NOT NULL AND closed_at IS NOT NULL)),
  CONSTRAINT daily_close_explained CHECK (NOT is_locked OR difference = 0 OR explanation IS NOT NULL),
  CONSTRAINT daily_close_difference CHECK (difference = bank_balance - books_bank),
  CONSTRAINT daily_close_liability CHECK (customer_liability = customer_available + customer_held)
);
CREATE TRIGGER daily_close_no_reopen BEFORE UPDATE OR DELETE ON daily_close
  FOR EACH ROW WHEN (OLD.is_locked) EXECUTE FUNCTION block_mutation();

-- Spec 015: a staff correction of a customer's wallet. Append-only; forced RLS
-- (the customer reads their own rows, writes are elevated).
CREATE TABLE wallet_adjustment (
  adjustment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  direction    TEXT NOT NULL CHECK (direction IN ('credit','debit')),
  amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
  reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),
  customer_status TEXT NOT NULL,                 -- the customer's status at the time
  adjusted_by  UUID NOT NULL REFERENCES staff(staff_id),
  ledger_txn_id UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
  adjusted_at  TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX idx_wallet_adjustment_at ON wallet_adjustment(adjusted_at, adjustment_id);
CREATE INDEX idx_wallet_adjustment_customer ON wallet_adjustment(customer_id, adjusted_at);
CREATE TRIGGER trg_wallet_adjustment_immutable BEFORE UPDATE OR DELETE ON wallet_adjustment
  FOR EACH ROW EXECUTE FUNCTION block_mutation();
-- Deferred checks (SQLSTATE DH011): wallet_adjustment_recorded() — the entry is
-- 'reversal' with no reversed entry, the staff actor is adjusted_by, and it
-- posts +amount (credit) / -amount (debit) to this customer's cust_available;
-- bank_movement_recorded() — the entry is 'external_bank_movement', the staff
-- actor is recorded_by, bank -amount and external_equity +amount.
-- Full bodies: database/migrations/2026_10_08_000010_create_finance_ops.php.

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
  ('awaiting_balance','cancelled_inspection','corrected result: karat mismatch / counterfeit (spec 012)'),
  ('weight_adjust_pending','disputed','dispute opened (spec 014)'),
  ('disputed','weight_adjust_pending','dispute resolved, resume (spec 014)'),
  ('disputed','at_inspection','dispute resolved, resume (spec 014)');

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
  ('requested','rejected','reviewer rejected before taking it; funds returned (spec 013)'),
  ('requested','on_hold_account_change','payout account changed; paused'),
  ('on_hold_account_change','under_review','pause window elapsed'),
  ('requested','cancelled','holder cancelled'),
  ('under_review','cancelled','holder cancelled'),
  ('released','settled','confirmed arrived at bank');

-- Payout accounts (added by spec 013) ---------------------------------
CREATE TABLE payout_account_transition (
  from_state payout_account_state NOT NULL,
  to_state   payout_account_state NOT NULL,
  note       TEXT,
  PRIMARY KEY (from_state, to_state)
);
INSERT INTO payout_account_transition (from_state, to_state, note) VALUES
  ('pending_review','active','name checked against the ID'),
  ('pending_review','refused','name check refused'),
  ('pending_review','removed','customer cancelled the request'),
  ('active','removing','removal waits for an in-flight withdrawal'),
  ('active','removed','customer removed it'),
  ('removing','active','customer kept it after all'),
  ('removing','removed','its last in-flight withdrawal ended');

-- Guards (spec 013), each with its own SQLSTATE:
--   assert_withdrawal_transition()     DH007 -> 409 illegal_withdrawal_transition: the move is in
--     withdrawal_transition; customer, account, amount, number and requested_at never change; the
--     txn ids are set once; never deleted.
--   assert_payout_account_transition() DH008 -> 409 illegal_payout_account_transition: the move is in
--     payout_account_transition; customer, holder, bank, number and created_at never change (a
--     change is a new account); never deleted.
--   withdrawal_money_recorded()        deferred (trg_withdrawal_money, AFTER INSERT OR UPDATE):
--     requested / under_review -> hold_txn_id; released -> release_txn_id and no return;
--     rejected / cancelled -> return_txn_id and no release; every named txn is a 'withdrawal' entry
--     of this withdrawal (read in the 'ledger' scope). DH007.
-- Full bodies: database/migrations/2026_10_06_000010_create_withdrawals.php.

-- Disputes and requests for more time (added by spec 014) ----------------
CREATE TABLE dispute_transition (
  from_state TEXT NOT NULL,
  to_state   TEXT NOT NULL,
  note       TEXT,
  PRIMARY KEY (from_state, to_state)
);
INSERT INTO dispute_transition (from_state, to_state, note) VALUES
  ('open','passed_on','passed to a named colleague'),
  ('passed_on','passed_on','passed on again'),
  ('open','resolved','resolved with a reply'),
  ('passed_on','resolved','resolved with a reply');

CREATE TABLE extension_request_transition (
  from_state TEXT NOT NULL,
  to_state   TEXT NOT NULL,
  note       TEXT,
  PRIMARY KEY (from_state, to_state)
);
INSERT INTO extension_request_transition (from_state, to_state, note) VALUES
  ('waiting','accepted','staff extended the reach-branch deadline'),
  ('waiting','refused','staff refused'),
  ('waiting','lapsed','the order left awaiting_delivery unanswered');

-- Guards (spec 014):
--   dispute_guard()            DH009 -> 409 illegal_dispute_transition: a state change is in
--     dispute_transition; a resolved dispute never changes; order, raiser, raised_as, reason,
--     detail, frozen_from, frozen_at, opened_at never change; ref = 'DSP-' || dispute_no;
--     raised_as matches the order's buyer/seller; never deleted.
--   extension_request_guard()  DH010 -> 409 illegal_extension_request_transition: along
--     extension_request_transition; final states never change; never deleted.
--   dispute_change_recorded()  deferred: each dispute insert / state change has its
--     dispute_change row in the same transaction. DH009.
--   compensation_recorded()    deferred: each compensation row's ledger_txn_id is a
--     'compensation' entry crediting exactly that amount to that customer. DH009.
--     Changed by spec 015: the party check runs only when an order is named, and
--     the entry's order matches the row's (both may be null).
--   dispute_photo, dispute_change, compensation: block_mutation() on UPDATE / DELETE.
-- Full bodies: database/migrations/2026_10_07_000010_create_disputes.php.


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
--   payout_account, payout_account_change, withdrawal_pause, withdrawal and
--   withdrawal_confirmation: built by spec 013 (forced RLS) with
--     USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
--     WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
--   (payout_account_change also requires actor_customer_id = the customer on
--   a customer's own insert). Staff, withdrawals:sweep and the public email
--   confirm (bootstrap elevation) act elevated.
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

-- Disputes, compensation, requests for more time (added by spec 014) ----
-- Readable by the customer each row belongs to, not by the other party of the
-- order (analysis C2): the other party reads the order's state and history.
--   dispute:          USING (elevated OR raised_by = me)
--                     WITH CHECK (elevated OR ('order' scope AND raised_by = me AND my order))
--   dispute_photo:    USING (elevated OR its dispute raised_by = me)
--                     WITH CHECK (elevated OR ('order' scope AND its dispute raised_by = me))
--   dispute_change:   USING (elevated OR its dispute raised_by = me)
--                     WITH CHECK (elevated OR ('order' scope AND actor_customer_id = me AND kind = 'opened'))
--   compensation:     USING (elevated OR customer_id = me)   WITH CHECK (elevated)
--   wallet_adjustment (spec 015): USING (elevated OR customer_id = me)   WITH CHECK (elevated)
--   bank_movement, daily_close (spec 015): no customer data, no RLS; staff by permission.
--   order_extension_request: USING (elevated OR seller_id = me)
--                     WITH CHECK (elevated OR ('order' scope AND seller_id = me AND my order))
--   dispute_transition, extension_request_transition: lookups, no RLS.

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


COMMIT;

-- spec 017: setting saved.max_per_customer = 200 (count, operations, 1..1000) — most pieces one customer may keep in Saved.

-- =====================================================================
-- spec 017 — the customer account (specs/017-customer-account/data-model.md)
-- =====================================================================

-- Closing an account: a status of its own, final; nothing is deleted.
ALTER TABLE customer
  ADD COLUMN closed_at     TIMESTAMPTZ,
  ADD COLUMN closed_reason TEXT CHECK (closed_reason IN (
    'finished','fees_too_high','too_slow_to_sell','data_trust','something_went_wrong','other')),
  ADD COLUMN closed_note   TEXT CHECK (char_length(closed_note) <= 500),
  ADD CONSTRAINT customer_closed_shape CHECK ((closed_at IS NULL) = (closed_reason IS NULL)),
  ADD CONSTRAINT customer_closed_note_other CHECK (closed_note IS NULL OR closed_reason = 'other'),
  ADD CONSTRAINT customer_closed_status CHECK ((status = 'closed') = (closed_at IS NOT NULL));
-- customer_status_check gains 'closed'; customer_status_flags_consistent accepts any flags when closed
-- (closing clears status_before_suspension; the suspension reason and dates stay).

-- Sessions learn their device (spec 017 research R4).
ALTER TABLE personal_access_tokens ADD COLUMN device_fingerprint_hash TEXT, ADD COLUMN device_platform TEXT;
CREATE INDEX idx_pat_tokenable_device ON personal_access_tokens (tokenable_id, device_fingerprint_hash);
ALTER TABLE customer_trusted_device
  ADD COLUMN platform   TEXT CHECK (platform IN ('ios','android','web')),
  ADD COLUMN user_agent TEXT CHECK (char_length(user_agent) <= 255);

-- The in-app inbox: a third channel next to SMS and email. Written only by an
-- elevated scope (the inbox notification channel); only read_at changes (DH014).
CREATE TABLE customer_notification (
  notification_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customer(customer_id),
  type         TEXT NOT NULL CHECK (type ~ '^[a-z_]+\.[a-z_]+$'),
  params       JSONB NOT NULL DEFAULT '{}'::jsonb,
  link_kind    TEXT NOT NULL CHECK (link_kind IN ('order','listing','buy_request','wallet','withdrawal',
                 'payout_account','topup','invoice','credit_note','dispute','account','none')),
  link_id      TEXT,
  title_en     TEXT NOT NULL CHECK (char_length(title_en) <= 200),
  title_ar     TEXT NOT NULL CHECK (char_length(title_ar) <= 200),
  body_en      TEXT NOT NULL CHECK (char_length(body_en) <= 1000),
  body_ar      TEXT NOT NULL CHECK (char_length(body_ar) <= 1000),
  dedupe_key   TEXT NOT NULL,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  read_at      TIMESTAMPTZ,
  CONSTRAINT customer_notification_link CHECK ((link_id IS NULL) = (link_kind IN ('wallet','account','none'))),
  CONSTRAINT customer_notification_dedupe UNIQUE (customer_id, dedupe_key)
);
-- Forced RLS: read/update own (elevated: all), insert elevated only, no delete policy.

-- Saved pieces (summary taken at save time, no media). At most setting saved.max_per_customer (200).
CREATE TABLE saved_listing (
  customer_id UUID NOT NULL REFERENCES customer(customer_id),
  listing_id  UUID NOT NULL REFERENCES listing(listing_id),
  summary     JSONB NOT NULL,
  saved_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (customer_id, listing_id)
);
-- Forced RLS: own rows only.

-- DH013: refuse_closed_customer() BEFORE INSERT on listing, buy_request, withdrawal,
-- withdrawal_confirmation, topup, dispute, payout_account, saved_listing, listing_report, and
-- refuse_closed_customer_posting() on ledger_posting for a closed customer's accounts.

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

-- spec 017: one_time_token purpose gains 'email_change' (the email-change link, 30 minutes, payload {new_email}).
