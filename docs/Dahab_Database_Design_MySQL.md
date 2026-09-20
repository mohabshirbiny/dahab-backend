# Dahab — Comprehensive Database Design (MySQL 8)

**Version:** 1.0 · **Target:** MySQL 8.0.16+ (InnoDB) · **Charset:** `utf8mb4` / `utf8mb4_0900_ai_ci`
**Status:** authoritative schema design. Supersedes `Dahab_Backend_Database_Design.md` and ports
`Database schema/00_schema_full.sql` (PostgreSQL) to MySQL without losing a single business rule.

---

## 0. Why this document exists

Two designs were on the table:

| | `Dahab_Backend_Database_Design.md` | `Database schema/00_schema_full.sql` |
|---|---|---|
| Model | Generic buyer→seller "buy request" with wallet + settlement | Escrow-and-inspection gold marketplace |
| Money | Mutable `wallets.balance` column with a log beside it | Double-entry ledger; balances are **derived**, never stored |
| Queue | Not modelled | FIFO `buy_request` queue, per-buyer price lock, per-buyer deposit |
| Pricing | One `seller_price` / `buyer_price` pair | Two-sided rate (buy side / sell side), spread, making charge, purity |
| Inspection | Generic `inspections` table, editable | Immutable results, karat rule enforced structurally, weight tolerance |
| Branches | Absent | Branches + working hours + holiday closures drive every deadline |
| Deadlines | `price_lock_expires_at` only | Reach-branch (working hours), pay-balance, collect, seller-return |
| Afterlife paths | Absent | `uncollected_expired`, `awaiting_seller_return`, `seller_unclaimed` |
| Tax / VAT | Absent | VAT on commission only, ETA tax invoices |
| Governance | `audit_logs` | Audit log, document-view log, founder device approval, account freeze, daily close, RLS |

**Verdict: `00_schema_full.sql` is the design that implements the Dahab business.** The other file
describes a conventional marketplace and is missing the parts that *are* the business — the queue,
the ledger, the two-sided price, the branch-bound deadlines, and the two afterlife paths. Its
contribution is kept where it genuinely suits MySQL/Laravel: integer surrogate keys, Laravel-native
auth and notification tables, and an explicit indexing catalogue.

This document is the merge, rewritten for MySQL.

---

## 1. Conventions

| Concern | Rule |
|---|---|
| Engine | `InnoDB`, `ROW_FORMAT=DYNAMIC` |
| Charset | `utf8mb4`, collation `utf8mb4_0900_ai_ci` (case-insensitive — replaces PostgreSQL `CITEXT`) |
| Primary key | `id BIGINT UNSIGNED AUTO_INCREMENT` (reference tables: `SMALLINT UNSIGNED`) |
| Public identifier | `ulid CHAR(26)` `UNIQUE` on every entity exposed through the API. Never expose `id`. |
| Foreign keys | Same type as the referenced key. `ON DELETE RESTRICT` everywhere on financial or historical data. |
| Money | `DECIMAL(18,4)`, EGP. Never `FLOAT` / `DOUBLE`. |
| Weight | `DECIMAL(10,3)` grams |
| Purity | `DECIMAL(6,5)` · Percent / rate: `DECIMAL(8,4)` |
| Time | `DATETIME(6)`, **always UTC**. Not `TIMESTAMP` (2038 limit, implicit TZ conversion). |
| Dates | `DATE` for business dates (`occurred_on`, `close_date`, `closure_date`) |
| Free-form structures | `JSON` (replaces `JSONB`) |
| IP address | `VARCHAR(45)` (replaces `INET`) |
| Bilingual text | paired `*_en` / `*_ar` columns; Arabic is Egyptian, no shadda |
| Deletes | No soft deletes on money or history. Visibility is a state, not a `deleted_at`. |
| Naming | tables singular except reserved words (`orders`), snake_case; `fk_`, `idx_`, `uq_`, `chk_` prefixes |

### 1.1 Business rules the schema must carry

1. No balance is ever stored and mutated as truth. Every balance derives from an append-only,
   double-entry ledger. The cache table exists only as a locking and guard surface.
2. Rates, deadlines, karats, piece types and thresholds are **data** (reference tables + `setting`),
   never hard-coded.
3. Deadlines are counted in **working hours at a specific branch**, honouring that branch's hours
   and holiday closures.
4. Nothing that moves money, changes a price or closes an account happens without a **named actor**.
5. A submitted inspection result is **immutable**. Corrections are new rows; both are kept.
6. Every legal-document version a customer agreed to is kept **forever**.
7. Commission is **never** taken from the value of the customer's gold.

---

## 2. PostgreSQL to MySQL translation decisions

The PostgreSQL schema leaned on features MySQL does not have. Each one is replaced by a mechanism
that is equally enforced, not by a comment in the code.

| PostgreSQL feature | MySQL replacement | Enforced by |
|---|---|---|
| `CREATE TYPE ... AS ENUM` | Inline `ENUM(...)` columns with identical value lists | DB |
| `UUID` PK + `gen_random_uuid()` | `BIGINT UNSIGNED AUTO_INCREMENT` + `ulid CHAR(26)` public id | DB. Random UUID PKs fragment the InnoDB clustered index. |
| `SERIAL` / `BIGSERIAL` | `AUTO_INCREMENT` | DB |
| `CITEXT` | `utf8mb4_0900_ai_ci` collation | DB |
| `INET`, `JSONB`, `TIMESTAMPTZ` | `VARCHAR(45)`, `JSON`, `DATETIME(6)` UTC | DB |
| Partial unique index (`WHERE ...`) | `STORED` generated column that is `NULL` outside the predicate, plus a plain `UNIQUE` (MySQL ignores NULLs in unique indexes) | DB |
| Partial index for sweeps | Composite index `(state, deadline)` — the sweep filters on `state` first | DB |
| `DEFERRABLE` trigger for ledger balance | **Single-writer stored procedure** `sp_ledger_post()`. The application DB user has no `INSERT` on `ledger_posting`, only `EXECUTE`. | DB grants |
| `DEFERRABLE` non-negative wallet trigger | `account_balance` cache row updated inside that procedure under `SELECT ... FOR UPDATE`, with `CHECK (kind NOT IN (customer kinds) OR balance >= 0)` | DB |
| Append-only triggers | `BEFORE UPDATE` / `BEFORE DELETE` triggers raising `SIGNAL SQLSTATE '45000'` | DB |
| `CHECK` containing a subquery (branch subset) | `BEFORE INSERT/UPDATE` trigger with `SIGNAL` | DB |
| Row-Level Security + policies | (a) separate MySQL accounts granted only on views, (b) Laravel global scopes and Policies, (c) `v_public_listing` for anonymous browsing | App + grants |
| Views with `FILTER (WHERE ...)` | `SUM(CASE WHEN ... THEN ... END)` | DB |
| `tsvector` search | `FULLTEXT` index with the `ngram` parser (Arabic) | DB |
| Monthly partitioning | `PARTITION BY RANGE (TO_DAYS(created_at))` — deferred, see §22 | Later |

> **Deadlock safety.** `sp_ledger_post()` locks `account_balance` rows in ascending `account_id`
> order. Every money path goes through that one procedure, so two concurrent money transactions can
> never acquire account locks in opposite order.

---

## 3. Module map

```
reference        karat · piece_type · branch · branch_hours · branch_closure
settings         setting · setting_history
identity         staff · founder_device_approval · account_freeze
                 customer · customer_suspension · identity_document · session_device
auth             personal_access_token · otp_challenge · login_attempt
legal            legal_document · agreement_acceptance
ledger           account · account_balance · ledger_transaction · ledger_posting
pricing          gold_rate_snapshot · manual_gold_price · rapaport_matrix
marketplace      listing · listing_media · listing_branch_option
                 listing_ownership_declaration · listing_review
queue            buy_request · listing_queue_seq · notify_when_free
orders           orders · order_branch_change · order_deadline_extension · seller_cancellation
inspection       inspection_result · settlement_decision
handover         collection · seller_return
payouts          payout_account · withdrawal_pause · withdrawal
finance          first_sale_advance · tax_invoice · bank_movement · daily_close
programme        promo_code · promo_code_use · market_maker_approval
governance       category_control · dispute · dispute_message · case_file
audit            audit_log · document_view_log
ops              idempotency_key · notification_template · notification_send
                 notification_preference · job_run_log
state machines   listing_transition · order_transition · buy_request_transition
                 withdrawal_transition
```

### 3.1 Commercial spine

```
customer ──< listing ──< buy_request ──1:1── orders ──1:1── inspection_result
   │            │                              │  │
   │            ├─ listing_media               │  ├─ collection
   │            ├─ listing_branch_option ── branch ├─ seller_return
   │            ├─ listing_ownership_declaration   ├─ tax_invoice
   │            └─ listing_review                  ├─ settlement_decision
   │                                               ├─ first_sale_advance
   ├─ identity_document                            └─ dispute
   ├─ agreement_acceptance ── legal_document
   ├─ payout_account ──< withdrawal
   └─ account ──< ledger_posting >── ledger_transaction
```

### 3.2 Money

```
ledger_transaction 1 ──< N ledger_posting >── 1 account ── 1:1 account_balance
        │                                           │
        │ event_kind                                └─ kind: cust_available | cust_held
        ├─ listing_id / order_id / buy_request_id      cust_pending_withdrawal | escrow
        ├─ withdrawal_id                               dahab_commission | dahab_spread
        ├─ customer_id | staff_id  (at least one)      dahab_forfeit_income | vat_payable
        └─ reverses_txn_id                             bank | external_equity

           SUM(postings within one transaction) = 0   ← sp_ledger_post()
           SUM(all postings, all accounts)      = 0   ← v_ledger_global_zero
```

---

## 4. Database bootstrap

```sql
CREATE DATABASE IF NOT EXISTS dahab
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE dahab;

SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO';
SET SESSION time_zone = '+00:00';
SET SESSION innodb_strict_mode = ON;
```

`sql_mode` must contain `STRICT_ALL_TABLES` in every environment. Without it MySQL silently
truncates a `DECIMAL(18,4)` overflow into a wrong number, which on this schema means wrong money.
Set it in `my.cnf`, not only per session.

---

## 5. Reference data — operator-editable, the "data not code" rule

Every table here is editable from the admin panel. Foreign keys point at them so the application
never contains a literal karat, branch or piece type.

```sql
-- Karats offered. Turning 20 or 22 on/off is a row toggle, not a release.
CREATE TABLE karat (
  karat_code    SMALLINT UNSIGNED NOT NULL,          -- 18, 20, 21, 22, 24
  purity_ratio  DECIMAL(6,5)      NOT NULL,          -- 0.75000 .. 0.99900
  is_enabled    BOOLEAN           NOT NULL DEFAULT TRUE,
  sort_order    SMALLINT UNSIGNED NOT NULL,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (karat_code),
  CONSTRAINT chk_karat_purity CHECK (purity_ratio > 0 AND purity_ratio <= 1)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE piece_type (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category      ENUM('gold','diamond','gold_with_diamond') NOT NULL,
  name_en       VARCHAR(120) NOT NULL,
  name_ar       VARCHAR(120) NOT NULL,
  typical_min_g DECIMAL(10,3) NULL,
  typical_max_g DECIMAL(10,3) NULL,
  is_enabled    BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_piece_type_cat_name (category, name_en),
  CONSTRAINT chk_piece_type_range CHECK (
    typical_min_g IS NULL OR typical_max_g IS NULL OR typical_max_g >= typical_min_g)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Inspection branches. Working hours + closures drive every working-hours deadline.
CREATE TABLE branch (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name_en     VARCHAR(150) NOT NULL,
  name_ar     VARCHAR(150) NOT NULL,
  address_en  VARCHAR(500) NOT NULL,
  address_ar  VARCHAR(500) NOT NULL,
  phone       VARCHAR(30)  NULL,
  timezone    VARCHAR(64)  NOT NULL DEFAULT 'Africa/Cairo',
  is_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch_code (code)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Weekly opening hours. dow: 0 = Sunday .. 6 = Saturday (Egypt's Sun-Thu week,
-- stored explicitly so no ISO/locale ambiguity can creep in).
-- Multiple rows per day are allowed (a lunch split); typically one.
CREATE TABLE branch_hours (
  branch_id  SMALLINT UNSIGNED NOT NULL,
  dow        TINYINT UNSIGNED  NOT NULL,
  opens_at   TIME NOT NULL,
  closes_at  TIME NOT NULL,
  PRIMARY KEY (branch_id, dow, opens_at),
  CONSTRAINT fk_branch_hours_branch FOREIGN KEY (branch_id) REFERENCES branch(id),
  CONSTRAINT chk_branch_hours_dow   CHECK (dow BETWEEN 0 AND 6),
  CONSTRAINT chk_branch_hours_order CHECK (closes_at > opens_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Full-day closures. branch_id NULL = all branches (a national holiday).
CREATE TABLE branch_closure (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id    SMALLINT UNSIGNED NULL,
  closure_date DATE NOT NULL,
  reason_en    VARCHAR(255) NULL,
  reason_ar    VARCHAR(255) NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch_closure (branch_id, closure_date),
  KEY idx_branch_closure_date (closure_date),
  CONSTRAINT fk_branch_closure_branch FOREIGN KEY (branch_id) REFERENCES branch(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

> `uq_branch_closure (branch_id, closure_date)` does not prevent two all-branch rows for the same
> date, because MySQL treats each `NULL` as distinct. All-branch closures are inserted through
> `sp_upsert_closure()`, which checks for an existing NULL-branch row first.

### 5.1 Working-hours arithmetic

A working-hours deadline is resolved to a wall-clock instant **once**, when the counter starts, and
stored as a `DATETIME(6)`. It is never recomputed on read — that would let a later holiday edit
silently move a live deadline.

```sql
-- 0 = Sunday .. 6 = Saturday, matching branch_hours.dow.
DELIMITER $$
CREATE FUNCTION fn_dow_sun0(p_day DATE) RETURNS TINYINT UNSIGNED
DETERMINISTIC NO SQL
BEGIN
  RETURN DAYOFWEEK(p_day) - 1;      -- DAYOFWEEK: 1 = Sunday
END$$

-- The UTC instant that is p_working_hours of open branch time after p_from.
CREATE FUNCTION fn_add_working_hours(
  p_branch_id     SMALLINT UNSIGNED,
  p_from          DATETIME(6),
  p_working_hours DECIMAL(8,2)
) RETURNS DATETIME(6)
DETERMINISTIC READS SQL DATA
BEGIN
  DECLARE v_remaining DECIMAL(14,4) DEFAULT p_working_hours * 3600;   -- seconds
  DECLARE v_cursor    DATETIME(6)   DEFAULT p_from;
  DECLARE v_day       DATE          DEFAULT DATE(p_from);
  DECLARE v_guard     INT           DEFAULT 0;
  DECLARE v_open      DATETIME(6);
  DECLARE v_close     DATETIME(6);
  DECLARE v_seg       DECIMAL(14,4);
  DECLARE v_done      TINYINT DEFAULT 0;
  DECLARE cur CURSOR FOR
    SELECT TIMESTAMP(v_day, opens_at), TIMESTAMP(v_day, closes_at)
      FROM branch_hours
     WHERE branch_id = p_branch_id AND dow = fn_dow_sun0(v_day)
     ORDER BY opens_at;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  WHILE v_remaining > 0 AND v_guard < 400 DO            -- guard: ~13 months
    SET v_guard = v_guard + 1;
    IF NOT EXISTS (SELECT 1 FROM branch_closure
                    WHERE closure_date = v_day
                      AND (branch_id = p_branch_id OR branch_id IS NULL)) THEN
      SET v_done = 0;
      OPEN cur;
      read_loop: LOOP
        FETCH cur INTO v_open, v_close;
        IF v_done = 1 THEN LEAVE read_loop; END IF;
        IF v_close > v_cursor THEN
          SET v_open = GREATEST(v_open, v_cursor);
          SET v_seg  = TIMESTAMPDIFF(SECOND, v_open, v_close);
          IF v_seg >= v_remaining THEN
            CLOSE cur;
            RETURN DATE_ADD(v_open, INTERVAL v_remaining SECOND);
          END IF;
          SET v_remaining = v_remaining - v_seg;
        END IF;
      END LOOP;
      CLOSE cur;
    END IF;
    SET v_day    = DATE_ADD(v_day, INTERVAL 1 DAY);
    SET v_cursor = TIMESTAMP(v_day, '00:00:00');
  END WHILE;

  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'fn_add_working_hours: no open branch time within the horizon';
END$$
DELIMITER ;
```

> Adding a `branch_closure` for a date that already has open orders does **not** move their stored
> deadlines. Moving one is an explicit operator action, recorded in `order_deadline_extension`.

---

## 6. Settings — the single source of every tunable number

Typed key/value with full history. The application reads the current value; UI text such as
"48 hours" is rendered from here, never hard-coded.

```sql
CREATE TABLE setting (
  setting_key   VARCHAR(120) NOT NULL,
  value_numeric DECIMAL(18,4) NULL,
  value_text    VARCHAR(500)  NULL,
  value_bool    BOOLEAN       NULL,
  unit          ENUM('hours','working_hours','days','weeks','percent','egp','count','text','bool')
                NOT NULL,
  description   VARCHAR(500) NOT NULL,
  updated_by    BIGINT UNSIGNED NULL,               -- FK to staff, wired in §7
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (setting_key),
  CONSTRAINT chk_setting_one_value CHECK (
    (value_numeric IS NOT NULL) + (value_text IS NOT NULL) + (value_bool IS NOT NULL) = 1)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE setting_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(120) NOT NULL,
  old_numeric DECIMAL(18,4) NULL,
  new_numeric DECIMAL(18,4) NULL,
  old_text    VARCHAR(500)  NULL,
  new_text    VARCHAR(500)  NULL,
  old_bool    BOOLEAN NULL,
  new_bool    BOOLEAN NULL,
  reason      VARCHAR(500) NULL,
  changed_by  BIGINT UNSIGNED NOT NULL,             -- FK to staff, wired in §7
  changed_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_setting_history_key (setting_key, changed_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

A trigger writes the history row, so no code path can change a setting silently:

```sql
DELIMITER $$
CREATE TRIGGER trg_setting_history AFTER UPDATE ON setting FOR EACH ROW
BEGIN
  IF NOT (NEW.value_numeric <=> OLD.value_numeric)
     OR NOT (NEW.value_text <=> OLD.value_text)
     OR NOT (NEW.value_bool <=> OLD.value_bool) THEN
    IF NEW.updated_by IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'setting change requires updated_by';
    END IF;
    INSERT INTO setting_history (setting_key, old_numeric, new_numeric, old_text, new_text,
                                 old_bool, new_bool, changed_by)
    VALUES (NEW.setting_key, OLD.value_numeric, NEW.value_numeric, OLD.value_text, NEW.value_text,
            OLD.value_bool, NEW.value_bool, NEW.updated_by);
  END IF;
END$$
DELIMITER ;
```

### 6.1 Seed

```sql
INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
 ('commission.gold_pct',                  20,     'percent',       'Commission on the making charge recovered on gold'),
 ('commission.stone_pct',                 5,      'percent',       'Commission on value added above gold on stones'),
 ('commission.minimum_egp',               200,    'egp',           'Minimum commission, never broken'),
 ('price_correction.buy_side',            -15,    'egp',           'Per-gram correction to the gold rate for the price the SELLER receives'),
 ('price_correction.sell_side',           15,     'egp',           'Per-gram correction to the gold rate for the price the BUYER pays'),
 ('vat.pct',                              14,     'percent',       'VAT, applied to commission only'),
 ('deposit.buyer_pct',                    20,     'percent',       'Buyer deposit held on a buy request'),
 ('deposit.seller_forfeit_share_pct',     50,     'percent',       'Share of a forfeited deposit paid to the seller'),
 ('deadline.seller_reply_hours',          48,     'hours',         'Seller must reply to a request within N clock hours'),
 ('deadline.reach_branch_working_hours',  12,     'working_hours', 'Seller must reach the branch within N working hours'),
 ('deadline.buyer_pay_days',              10,     'days',          'Buyer pays the balance within N days of inspection'),
 ('deadline.collect_weeks',               3,      'weeks',         'Piece waits at the branch N weeks after payment'),
 ('deadline.seller_return_weeks',         3,      'weeks',         'Returned piece waits N weeks for the seller to collect'),
 ('deadline.free_relist_working_hours',   12,     'working_hours', 'Free 0% relist window after collecting'),
 ('withdrawal.account_change_pause_hours',48,     'hours',         'Withdrawals pause N hours after a payout-account change'),
 ('inspection.weight_tolerance_pct',      1.5,    'percent',       'Weight difference auto-adjusted; above this needs buyer approval'),
 ('marketmaker.min_list_age_days',        7,      'days',          'A piece must be listed N days before a market-maker code can buy'),
 ('payout.first_sale_cap_egp',            100000, 'egp',           'Cap on the first-sale advance to a first-time gold seller'),
 ('suspension.cancellations_threshold',   2,      'count',         'Seller cancellations before listing is suspended'),
 ('flag.pattern_txn_threshold',           5,      'count',         'Transactions before a pattern is flagged for review'),
 ('compensation.cap_per_payment_egp',     2000,   'egp',           'Compensation cap per payment (Finance)'),
 ('compensation.cap_per_day_egp',         5000,   'egp',           'Compensation cap per day (Finance)'),
 ('manualprice.confirm_deviation_pct',    10,     'percent',       'Manual gold price above this deviation needs a second confirmation');
```

> **Karat tolerance is deliberately absent.** Any karat mismatch cancels the sale. That rule is
> structural (§13, `chk_karat_mismatch_cancels`), not a tunable threshold — as a setting, someone
> could set it non-zero and break the core promise of the platform.

---

## 7. Identity — staff

Every privileged action references a staff row. Founders (`ceo`, `coo`) are unrestricted and are
distinguished **only** by the audit log. Wallet access is limited to `ceo` + `finance` through
grants (§21), not through a column here.

```sql
CREATE TABLE staff (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid        CHAR(26) NOT NULL,
  role        ENUM('ceo','coo','finance','operations','verification','igi_branch','system') NOT NULL,
  full_name   VARCHAR(150) NOT NULL,
  email       VARCHAR(255) NOT NULL,
  phone       VARCHAR(30) NULL,
  password_hash VARCHAR(255) NULL,          -- NULL for the 'system' actor: it cannot sign in
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  -- The IGI branch account is a single shared login by agreement; it is tied to
  -- a branch so the log records the branch, not an individual.
  branch_id   SMALLINT UNSIGNED NULL,
  last_login_at DATETIME(6) NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_ulid  (ulid),
  UNIQUE KEY uq_staff_email (email),
  KEY idx_staff_role (role, is_active),
  CONSTRAINT fk_staff_branch  FOREIGN KEY (branch_id) REFERENCES branch(id),
  CONSTRAINT fk_staff_creator FOREIGN KEY (created_by) REFERENCES staff(id),
  -- An igi_branch account has a branch; nobody else does.
  CONSTRAINT chk_staff_igi_branch CHECK ((role = 'igi_branch') = (branch_id IS NOT NULL)),
  -- The system actor can never hold a password.
  CONSTRAINT chk_staff_system_no_login CHECK (role <> 'system' OR password_hash IS NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

> **Decision taken (blueprint §8.2, MODIFY item 1):** the sweep jobs act as a dedicated
> `role = 'system'` staff row, seeded once, unable to sign in. Sweep-generated ledger transactions
> and audit rows therefore satisfy "every action has a named actor" without borrowing a real
> operator's identity.

Now wire the settings audit keys deferred in §6:

```sql
ALTER TABLE setting
  ADD CONSTRAINT fk_setting_updated_by FOREIGN KEY (updated_by) REFERENCES staff(id);
ALTER TABLE setting_history
  ADD CONSTRAINT fk_setting_hist_changed_by FOREIGN KEY (changed_by) REFERENCES staff(id);
```

### 7.1 Founder security sublayer

A sign-in from a new device is held until the other founder confirms; either founder can freeze
the other instantly; unfreezing needs both.

```sql
CREATE TABLE founder_device_approval (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_id           BIGINT UNSIGNED NOT NULL,
  device_fingerprint_hash CHAR(64) NOT NULL,        -- SHA-256, never the raw fingerprint
  requested_ip       VARCHAR(45) NULL,
  requested_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  approved_by        BIGINT UNSIGNED NULL,
  approved_at        DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_fda_staff (staff_id, approved_at),
  CONSTRAINT fk_fda_staff    FOREIGN KEY (staff_id)    REFERENCES staff(id),
  CONSTRAINT fk_fda_approver FOREIGN KEY (approved_by) REFERENCES staff(id),
  CONSTRAINT chk_fda_not_self CHECK (approved_by IS NULL OR approved_by <> staff_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE account_freeze (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  frozen_staff_id  BIGINT UNSIGNED NOT NULL,
  frozen_by        BIGINT UNSIGNED NOT NULL,
  reason           VARCHAR(500) NOT NULL,
  frozen_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- Unfreeze needs BOTH founders: two distinct confirmations.
  unfreeze_confirm_1 BIGINT UNSIGNED NULL,
  unfreeze_confirm_2 BIGINT UNSIGNED NULL,
  unfrozen_at        DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_freeze_staff (frozen_staff_id, unfrozen_at),
  CONSTRAINT fk_freeze_target FOREIGN KEY (frozen_staff_id)    REFERENCES staff(id),
  CONSTRAINT fk_freeze_actor  FOREIGN KEY (frozen_by)          REFERENCES staff(id),
  CONSTRAINT fk_freeze_c1     FOREIGN KEY (unfreeze_confirm_1) REFERENCES staff(id),
  CONSTRAINT fk_freeze_c2     FOREIGN KEY (unfreeze_confirm_2) REFERENCES staff(id),
  CONSTRAINT chk_freeze_not_self CHECK (frozen_by <> frozen_staff_id),
  CONSTRAINT chk_freeze_two_confirms CHECK (
    unfrozen_at IS NULL
    OR (unfreeze_confirm_1 IS NOT NULL
        AND unfreeze_confirm_2 IS NOT NULL
        AND unfreeze_confirm_1 <> unfreeze_confirm_2)
  )
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

---

## 8. Identity — customers

Browsing needs no account. Verification is required before the first sale or purchase. A foreign
phone or passport is acceptable.

```sql
CREATE TABLE customer (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid            CHAR(26) NOT NULL,
  display_ref     VARCHAR(12) NOT NULL,             -- e.g. "4417", shown in the UI
  phone           VARCHAR(30) NOT NULL,             -- sign-in identity
  email           VARCHAR(255) NULL,
  password_hash   VARCHAR(255) NULL,                -- OTP-first; password optional
  full_name       VARCHAR(150) NULL,                -- as on the ID, once verified
  preferred_lang  ENUM('ar','en') NOT NULL DEFAULT 'ar',
  is_verified     BOOLEAN NOT NULL DEFAULT FALSE,
  verified_at     DATETIME(6) NULL,
  phone_verified_at DATETIME(6) NULL,
  email_verified_at DATETIME(6) NULL,               -- required for the withdrawal second-check
  is_suspended    BOOLEAN NOT NULL DEFAULT FALSE,
  suspended_reason VARCHAR(500) NULL,
  suspended_by    BIGINT UNSIGNED NULL,
  suspended_at    DATETIME(6) NULL,
  closed_at       DATETIME(6) NULL,                 -- account closure; history survives
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_ulid   (ulid),
  UNIQUE KEY uq_customer_ref    (display_ref),
  UNIQUE KEY uq_customer_phone  (phone),
  UNIQUE KEY uq_customer_email  (email),
  KEY idx_customer_verified (is_verified, is_suspended),
  CONSTRAINT fk_customer_suspender FOREIGN KEY (suspended_by) REFERENCES staff(id),
  CONSTRAINT chk_customer_suspend_actor CHECK (
    is_suspended = FALSE OR (suspended_by IS NOT NULL AND suspended_reason IS NOT NULL)),
  CONSTRAINT chk_customer_verified_named CHECK (is_verified = FALSE OR full_name IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

> `customer.email_verified_at` is the resolution of blueprint §8.2 MODIFY item 2: the withdrawal
> second-check requires a **verified** email, not merely a present one. The withdrawal use case
> reads this column, and `chk_withdrawal_requires_verified_email` cannot be a DB `CHECK` (it spans
> tables), so it is asserted in `sp_request_withdrawal()` (§17).

One row per suspension episode, so a repeat offender's history is legible:

```sql
CREATE TABLE customer_suspension (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  BIGINT UNSIGNED NOT NULL,
  reason_code  ENUM('cancellations','fraud_suspicion','karat_mismatch','kyc_failure',
                    'abuse','manual','other') NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  imposed_by   BIGINT UNSIGNED NOT NULL,
  imposed_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  lifted_by    BIGINT UNSIGNED NULL,
  lifted_at    DATETIME(6) NULL,
  lift_reason  VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_cust_susp (customer_id, lifted_at),
  CONSTRAINT fk_cs_customer FOREIGN KEY (customer_id) REFERENCES customer(id),
  CONSTRAINT fk_cs_imposer  FOREIGN KEY (imposed_by)  REFERENCES staff(id),
  CONSTRAINT fk_cs_lifter   FOREIGN KEY (lifted_by)   REFERENCES staff(id),
  CONSTRAINT chk_cs_lift CHECK (lifted_at IS NULL OR (lifted_by IS NOT NULL AND lift_reason IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 8.1 Identity documents

Images are encrypted at rest with application-side envelope encryption (KMS-held key). This table
holds the object reference and the verification metadata, never the image bytes. **Every view is
logged** (§20).

```sql
CREATE TABLE identity_document (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL,
  customer_id   BIGINT UNSIGNED NOT NULL,
  doc_kind      ENUM('egyptian_id','passport') NOT NULL,
  storage_ref   VARCHAR(500) NOT NULL,             -- encrypted object key
  key_version   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reject_reason VARCHAR(500) NULL,
  reviewed_by   BIGINT UNSIGNED NULL,
  reviewed_at   DATETIME(6) NULL,
  -- After account closure the image is deleted, but the record that we checked
  -- it, and when, survives for the legally required period.
  image_deleted_at DATETIME(6) NULL,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_iddoc_ulid (ulid),
  KEY idx_iddoc_customer (customer_id, status),
  KEY idx_iddoc_pending  (status, created_at),
  CONSTRAINT fk_iddoc_customer FOREIGN KEY (customer_id) REFERENCES customer(id),
  CONSTRAINT fk_iddoc_reviewer FOREIGN KEY (reviewed_by) REFERENCES staff(id),
  CONSTRAINT chk_iddoc_reviewed CHECK (
    status = 'pending' OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
  CONSTRAINT chk_iddoc_reject_reason CHECK (status <> 'rejected' OR reject_reason IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

---

## 9. Authentication

Laravel Sanctum for both mobile and admin, plus an OTP challenge table and a trusted-device table.

```sql
-- Sanctum's table, with the standard shape (morph columns kept as Laravel writes them).
CREATE TABLE personal_access_token (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tokenable_type VARCHAR(255) NOT NULL,             -- App\Models\Customer | App\Models\Staff
  tokenable_id   BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(255) NOT NULL,
  token          CHAR(64) NOT NULL,                 -- SHA-256 of the plaintext token
  abilities      TEXT NULL,
  device_id      BIGINT UNSIGNED NULL,
  last_used_at   DATETIME(6) NULL,
  expires_at     DATETIME(6) NULL,
  revoked_at     DATETIME(6) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pat_token (token),
  KEY idx_pat_tokenable (tokenable_type, tokenable_id),
  KEY idx_pat_expiry (expires_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Trusted devices. Backs the "known device" logic; fingerprints are hashed.
CREATE TABLE session_device (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  owner_type     ENUM('customer','staff') NOT NULL,
  owner_id       BIGINT UNSIGNED NOT NULL,
  fingerprint_hash CHAR(64) NOT NULL,
  platform       ENUM('ios','android','web') NOT NULL,
  app_version    VARCHAR(30) NULL,
  push_token     VARCHAR(255) NULL,                 -- FCM / APNs
  is_trusted     BOOLEAN NOT NULL DEFAULT FALSE,
  trusted_at     DATETIME(6) NULL,
  last_seen_at   DATETIME(6) NULL,
  last_seen_ip   VARCHAR(45) NULL,
  revoked_at     DATETIME(6) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_device_ulid (ulid),
  UNIQUE KEY uq_device_owner_fp (owner_type, owner_id, fingerprint_hash),
  KEY idx_device_push (push_token)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- OTP challenges. The code is NEVER stored in plaintext.
CREATE TABLE otp_challenge (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  owner_type   ENUM('customer','staff') NOT NULL,
  owner_id     BIGINT UNSIGNED NULL,                -- NULL during first registration
  channel      ENUM('sms','email','whatsapp') NOT NULL,
  destination  VARCHAR(255) NOT NULL,
  code_hash    VARCHAR(255) NOT NULL,               -- bcrypt/argon2 of the OTP
  purpose      ENUM('registration','login','new_device','phone_verification',
                    'email_verification','password_reset','withdrawal_confirm',
                    'payout_account_change') NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  expires_at   DATETIME(6) NOT NULL,
  verified_at  DATETIME(6) NULL,
  consumed_at  DATETIME(6) NULL,                    -- single use, even after verify
  request_ip   VARCHAR(45) NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_otp_ulid (ulid),
  KEY idx_otp_lookup (destination, purpose, expires_at),
  KEY idx_otp_owner (owner_type, owner_id, created_at),
  CONSTRAINT chk_otp_attempts CHECK (attempts <= max_attempts)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Lockout is DB-backed so it survives a Redis flush.
CREATE TABLE login_attempt (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier   VARCHAR(255) NOT NULL,               -- phone or email attempted
  owner_type   ENUM('customer','staff') NOT NULL,
  succeeded    BOOLEAN NOT NULL,
  failure_code VARCHAR(50) NULL,
  ip_address   VARCHAR(45) NULL,
  fingerprint_hash CHAR(64) NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_login_attempt (identifier, created_at),
  KEY idx_login_attempt_ip (ip_address, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

---

## 10. Legal documents and acceptances

Every version a customer agreed to is kept forever; a customer stays bound to the version they
accepted, not to the current one.

```sql
CREATE TABLE legal_document (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         ENUM('terms','privacy','collection_auth','ownership_declaration',
                    'first_sale_offer','market_maker_terms','payout_account_terms') NOT NULL,
  version      INT UNSIGNED NOT NULL,
  body_en      MEDIUMTEXT NOT NULL,
  body_ar      MEDIUMTEXT NOT NULL,
  is_material  BOOLEAN NOT NULL DEFAULT FALSE,      -- a material change forces re-acceptance
  published_by BIGINT UNSIGNED NOT NULL,
  published_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  retired_at   DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legal_code_version (code, version),
  CONSTRAINT fk_legal_publisher FOREIGN KEY (published_by) REFERENCES staff(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Immutable record of every tick. This is the evidence trail.
CREATE TABLE agreement_acceptance (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  customer_id    BIGINT UNSIGNED NOT NULL,
  legal_doc_id   SMALLINT UNSIGNED NOT NULL,
  context        ENUM('signup','list_piece','buy_request','payout_account',
                      'collection_proxy','first_sale_offer','market_maker',
                      're_accept') NOT NULL,
  -- What the tick was about, when it is piece-specific.
  listing_id     BIGINT UNSIGNED NULL,              -- FK wired in §14
  order_id       BIGINT UNSIGNED NULL,              -- FK wired in §15
  accepted_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ip_address     VARCHAR(45) NULL,
  fingerprint_hash CHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acceptance_ulid (ulid),
  KEY idx_acceptance_customer (customer_id, accepted_at),
  KEY idx_acceptance_doc (legal_doc_id),
  CONSTRAINT fk_acc_customer FOREIGN KEY (customer_id)  REFERENCES customer(id),
  CONSTRAINT fk_acc_legal    FOREIGN KEY (legal_doc_id) REFERENCES legal_document(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

Acceptances are append-only:

```sql
DELIMITER $$
CREATE TRIGGER trg_acceptance_no_update BEFORE UPDATE ON agreement_acceptance FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: agreement_acceptance'; END$$
CREATE TRIGGER trg_acceptance_no_delete BEFORE DELETE ON agreement_acceptance FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: agreement_acceptance'; END$$
DELIMITER ;
```

---

## 11. The money ledger (double entry)

### 11.1 Why double entry

Every movement of money has two sides that must be equal: where it came from and where it went. No
wallet balance is ever stored and mutated as truth. A balance is the **sum of that account's
postings**. This makes it impossible for money to appear or vanish without a trace, and it makes
"bank balance minus what is owed to customers" — the most important number in the business — exact.

### 11.2 Model

| Object | Meaning |
|---|---|
| `account` | A bucket money can sit in: a customer's available wallet, their held wallet, escrow, commission, VAT payable, the bank mirror, equity |
| `ledger_transaction` | One business event: a top-up, a settlement, a forfeiture |
| `ledger_posting` | One signed line inside a transaction. Positive increases the account, negative decreases it |
| `account_balance` | Cache + lock target + non-negative guard. Never the source of truth; `v_account_balance` recomputes from postings |

**The invariant:** `SUM(amount)` over the postings of one `ledger_transaction` is exactly `0`.
Consequently `SUM(amount)` over *all* postings is also `0`, which makes the whole system
self-checking (`v_ledger_global_zero`).

### 11.3 Account taxonomy

```
Customer-owned (a liability of Dahab to the customer):
  cust_available            spendable and withdrawable
  cust_held                 reserved against one specific open order
  cust_pending_withdrawal   requested, awaiting review/release

Dahab-internal:
  escrow                    buyer balance in transit at settlement
  dahab_commission          commission income
  dahab_spread              buy/sell gold-rate margin (gold category only)
  dahab_forfeit_income      Dahab's half of a forfeited deposit
  vat_payable               VAT collected on commission, owed to the tax authority
  bank                      the real bank account, mirrored and reconciled daily
  external_equity           capital in, profit out, rent, bank charges, advances fronted
```

> `cust_pending_withdrawal` is new relative to the PostgreSQL file, resolving blueprint §8.2 MODIFY
> item 3. `cust_held` means "reserved against a specific open order"; piggy-backing a withdrawal
> onto it would conflate two different promises to the customer.
>
> `dahab_forfeit_income` is likewise split out of `dahab_commission`, so that forfeiture income —
> which is not commission and is not VAT-bearing — never contaminates the commission figure the
> tax invoices are built from.

### 11.4 Tables

```sql
CREATE TABLE account (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid        CHAR(26) NOT NULL,
  kind        ENUM('cust_available','cust_held','cust_pending_withdrawal',
                   'escrow','dahab_commission','dahab_spread','dahab_forfeit_income',
                   'vat_payable','bank','external_equity') NOT NULL,
  customer_id BIGINT UNSIGNED NULL,                 -- internal accounts have no owner
  currency    CHAR(3) NOT NULL DEFAULT 'EGP',
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- Replaces the PostgreSQL partial unique index
  --   CREATE UNIQUE INDEX ... ON account(kind) WHERE customer_id IS NULL.
  -- NULL for customer accounts, so MySQL's unique index ignores them.
  singleton_kind VARCHAR(32)
    GENERATED ALWAYS AS (IF(customer_id IS NULL, CAST(kind AS CHAR), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_ulid (ulid),
  UNIQUE KEY uq_account_customer_kind (customer_id, kind),   -- one available, one held, per customer
  UNIQUE KEY uq_account_singleton (singleton_kind),          -- exactly one escrow, one bank, ...
  CONSTRAINT fk_account_customer FOREIGN KEY (customer_id) REFERENCES customer(id),
  CONSTRAINT chk_account_owner CHECK (
    (kind IN ('cust_available','cust_held','cust_pending_withdrawal'))
    = (customer_id IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Cache, lock target, and the non-negative guard. Written ONLY by sp_ledger_post().
CREATE TABLE account_balance (
  account_id     BIGINT UNSIGNED NOT NULL,
  kind           VARCHAR(32)   NOT NULL,            -- denormalised so CHECK can see it
  balance        DECIMAL(18,4) NOT NULL DEFAULT 0,
  postings_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_txn_id    BIGINT UNSIGNED NULL,
  updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (account_id),
  CONSTRAINT fk_ab_account FOREIGN KEY (account_id) REFERENCES account(id),
  -- A customer account may never go negative. Internal accounts may be any sign.
  CONSTRAINT chk_ab_customer_nonneg CHECK (
    kind NOT IN ('cust_available','cust_held','cust_pending_withdrawal') OR balance >= 0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Every account gets its balance row automatically.
DELIMITER $$
CREATE TRIGGER trg_account_balance_seed AFTER INSERT ON account FOR EACH ROW
BEGIN
  INSERT INTO account_balance (account_id, kind, balance) VALUES (NEW.id, NEW.kind, 0);
END$$
DELIMITER ;

CREATE TABLE ledger_transaction (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL,
  event_kind    ENUM('topup','deposit_hold','deposit_release','deposit_forfeit',
                     'settlement_seller','first_sale_payout','commission','spread','vat',
                     'balance_payment','withdrawal_request','withdrawal_release',
                     'withdrawal_return','compensation','external_bank_movement',
                     'weight_adjustment','reversal') NOT NULL,
  -- What the event is about. Nullable: a top-up has no order.
  listing_id     BIGINT UNSIGNED NULL,
  order_id       BIGINT UNSIGNED NULL,
  buy_request_id BIGINT UNSIGNED NULL,
  withdrawal_id  BIGINT UNSIGNED NULL,
  -- Named actor. At least one must be present.
  customer_id    BIGINT UNSIGNED NULL,
  staff_id       BIGINT UNSIGNED NULL,
  -- Corrections are new rows referencing what they reverse. Nothing is edited.
  reverses_txn_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(190) NULL,
  memo           VARCHAR(500) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ltxn_ulid (ulid),
  UNIQUE KEY uq_ltxn_idem (idempotency_key),
  KEY idx_ltxn_order    (order_id),
  KEY idx_ltxn_listing  (listing_id),
  KEY idx_ltxn_request  (buy_request_id),
  KEY idx_ltxn_withdraw (withdrawal_id),
  KEY idx_ltxn_customer (customer_id, created_at),
  KEY idx_ltxn_kind     (event_kind, created_at),
  CONSTRAINT fk_ltxn_customer FOREIGN KEY (customer_id)     REFERENCES customer(id),
  CONSTRAINT fk_ltxn_staff    FOREIGN KEY (staff_id)        REFERENCES staff(id),
  CONSTRAINT fk_ltxn_reverses FOREIGN KEY (reverses_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_ltxn_actor CHECK (customer_id IS NOT NULL OR staff_id IS NOT NULL),
  CONSTRAINT chk_ltxn_reversal CHECK (event_kind <> 'reversal' OR reverses_txn_id IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE ledger_posting (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ledger_txn_id BIGINT UNSIGNED NOT NULL,
  account_id    BIGINT UNSIGNED NOT NULL,
  amount        DECIMAL(18,4) NOT NULL,             -- signed: + increases, - decreases
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_posting_account (account_id, id),
  KEY idx_posting_txn (ledger_txn_id),
  KEY idx_posting_created (created_at),
  CONSTRAINT fk_posting_txn     FOREIGN KEY (ledger_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT fk_posting_account FOREIGN KEY (account_id)    REFERENCES account(id),
  CONSTRAINT chk_posting_nonzero CHECK (amount <> 0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

The business-object foreign keys on `ledger_transaction` are wired after §14–§17 create their
targets:

```sql
ALTER TABLE ledger_transaction
  ADD CONSTRAINT fk_ltxn_listing    FOREIGN KEY (listing_id)     REFERENCES listing(id),
  ADD CONSTRAINT fk_ltxn_order      FOREIGN KEY (order_id)       REFERENCES orders(id),
  ADD CONSTRAINT fk_ltxn_request    FOREIGN KEY (buy_request_id) REFERENCES buy_request(id),
  ADD CONSTRAINT fk_ltxn_withdrawal FOREIGN KEY (withdrawal_id)  REFERENCES withdrawal(id);
```

### 11.5 Append-only enforcement

The ledger is immutable. A correction is a reversal transaction, never an edit.

```sql
DELIMITER $$
CREATE TRIGGER trg_posting_no_update BEFORE UPDATE ON ledger_posting FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: ledger_posting cannot be updated'; END$$
CREATE TRIGGER trg_posting_no_delete BEFORE DELETE ON ledger_posting FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: ledger_posting cannot be deleted'; END$$
CREATE TRIGGER trg_ltxn_no_update BEFORE UPDATE ON ledger_transaction FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: ledger_transaction cannot be updated'; END$$
CREATE TRIGGER trg_ltxn_no_delete BEFORE DELETE ON ledger_transaction FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: ledger_transaction cannot be deleted'; END$$
DELIMITER ;
```

### 11.6 The single writer: `sp_ledger_post()`

MySQL has no deferred constraint triggers, so the "postings sum to zero" invariant cannot be checked
per row. It is enforced instead by making this procedure the **only** way to write the ledger: the
application user is granted `EXECUTE` on it and has no `INSERT` on `ledger_posting` or
`ledger_transaction` (§21).

```sql
DELIMITER $$
CREATE PROCEDURE sp_ledger_post(
  IN  p_event_kind      VARCHAR(40),
  IN  p_listing_id      BIGINT UNSIGNED,
  IN  p_order_id        BIGINT UNSIGNED,
  IN  p_buy_request_id  BIGINT UNSIGNED,
  IN  p_withdrawal_id   BIGINT UNSIGNED,
  IN  p_customer_id     BIGINT UNSIGNED,
  IN  p_staff_id        BIGINT UNSIGNED,
  IN  p_reverses_txn_id BIGINT UNSIGNED,
  IN  p_idempotency_key VARCHAR(190),
  IN  p_memo            VARCHAR(500),
  IN  p_ulid            CHAR(26),
  -- [{"account_id":12,"amount":"-1050.0000"}, {"account_id":3,"amount":"1050.0000"}]
  IN  p_legs            JSON,
  OUT p_txn_id          BIGINT UNSIGNED
)
MODIFIES SQL DATA
BEGIN
  DECLARE v_sum   DECIMAL(18,4);
  DECLARE v_legs  INT;
  DECLARE v_acc   BIGINT UNSIGNED;
  DECLARE v_delta DECIMAL(18,4);
  DECLARE v_lock  DECIMAL(18,4);
  DECLARE v_kind  VARCHAR(32);
  DECLARE v_done  TINYINT DEFAULT 0;
  DECLARE cur CURSOR FOR
    SELECT account_id, delta FROM tmp_ledger_legs ORDER BY account_id;   -- deterministic lock order
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  IF p_customer_id IS NULL AND p_staff_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transaction requires a named actor';
  END IF;

  SET v_legs = JSON_LENGTH(p_legs);
  IF v_legs IS NULL OR v_legs < 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a ledger transaction needs at least two legs';
  END IF;

  DROP TEMPORARY TABLE IF EXISTS tmp_ledger_legs;
  CREATE TEMPORARY TABLE tmp_ledger_legs (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    delta      DECIMAL(18,4)   NOT NULL
  ) ENGINE=MEMORY;

  INSERT INTO tmp_ledger_legs (account_id, delta)
  SELECT jt.account_id, SUM(jt.amount)
    FROM JSON_TABLE(p_legs, '$[*]' COLUMNS (
           account_id BIGINT UNSIGNED PATH '$.account_id',
           amount     DECIMAL(18,4)   PATH '$.amount'
         )) AS jt
   GROUP BY jt.account_id;

  SELECT COALESCE(SUM(delta), 0) INTO v_sum FROM tmp_ledger_legs;
  IF v_sum <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'unbalanced ledger transaction: postings do not sum to zero';
  END IF;

  -- PASS 1: lock every affected balance row in ascending account_id order and
  -- verify the projected balances BEFORE writing anything. See 11.6.1 for why
  -- the order matters.
  OPEN cur;
  check_loop: LOOP
    FETCH cur INTO v_acc, v_delta;
    IF v_done = 1 THEN LEAVE check_loop; END IF;
    SELECT balance, kind INTO v_lock, v_kind
      FROM account_balance WHERE account_id = v_acc FOR UPDATE;
    IF v_lock IS NULL THEN
      CLOSE cur;
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'unknown account in ledger legs';
    END IF;
    IF v_kind IN ('cust_available','cust_held','cust_pending_withdrawal')
       AND v_lock + v_delta < 0 THEN
      CLOSE cur;
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a customer account would go negative';
    END IF;
  END LOOP;
  CLOSE cur;
  SET v_done = 0;

  -- PASS 2: validated and locked; now write.
  INSERT INTO ledger_transaction
    (ulid, event_kind, listing_id, order_id, buy_request_id, withdrawal_id,
     customer_id, staff_id, reverses_txn_id, idempotency_key, memo)
  VALUES
    (p_ulid, p_event_kind, p_listing_id, p_order_id, p_buy_request_id, p_withdrawal_id,
     p_customer_id, p_staff_id, p_reverses_txn_id, p_idempotency_key, p_memo);
  SET p_txn_id = LAST_INSERT_ID();

  INSERT INTO ledger_posting (ledger_txn_id, account_id, amount)
  SELECT p_txn_id, jt.account_id, jt.amount
    FROM JSON_TABLE(p_legs, '$[*]' COLUMNS (
           account_id BIGINT UNSIGNED PATH '$.account_id',
           amount     DECIMAL(18,4)   PATH '$.amount'
         )) AS jt;

  -- Apply to the balance cache. The rows are already locked by pass 1 and the
  -- projected balances are already known good; chk_ab_customer_nonneg remains
  -- as the belt-and-braces backstop.
  OPEN cur;
  apply_loop: LOOP
    FETCH cur INTO v_acc, v_delta;
    IF v_done = 1 THEN LEAVE apply_loop; END IF;
    UPDATE account_balance
       SET balance        = balance + v_delta,
           postings_count = postings_count + 1,
           last_txn_id    = p_txn_id
     WHERE account_id = v_acc;
  END LOOP;
  CLOSE cur;

  DROP TEMPORARY TABLE tmp_ledger_legs;
END$$
DELIMITER ;
```

**Contract.** The caller opens the transaction (`DB::transaction(...)` in Laravel) and calls this
procedure once per business event. The procedure never commits or rolls back — it signals, and the
caller's transaction unwinds. Calling it outside a transaction is a bug that CI catches (§23).

#### 11.6.1 Why validation comes before the first write

The two-pass structure is not stylistic. An earlier single-pass draft inserted the postings and
*then* let `chk_ab_customer_nonneg` reject the balance update. That is correct only while the caller
honours the transaction contract. A caller that forgets leaves MySQL in autocommit, where the
postings are already committed by the time the balance update is refused — the transaction fails,
the money moves anyway, and `v_ledger_global_zero` goes non-zero.

This was not theoretical: it was caught by running the build and firing a deliberately overdrawing
call, which left a wallet at −997,225 with the call reported as failed. Validating first means a
rejected call writes no rows at all, whether or not the caller honoured the contract. The database
no longer depends on the application getting transaction scoping right in order to stay solvent.

The `CHECK` constraint stays as a backstop: pass 1 is the gate, the constraint is the proof.

### 11.7 Balance views — the real truth

```sql
-- Recomputed from postings. Disagreement with account_balance means corruption.
CREATE VIEW v_account_balance AS
SELECT a.id AS account_id, a.kind, a.customer_id,
       COALESCE(SUM(p.amount), 0) AS balance
  FROM account a
  LEFT JOIN ledger_posting p ON p.account_id = a.id
 GROUP BY a.id, a.kind, a.customer_id;

-- The two figures the app shows a customer, plus the withdrawal-pending bucket.
CREATE VIEW v_customer_wallet AS
SELECT c.id AS customer_id,
       COALESCE(SUM(CASE WHEN a.kind = 'cust_available'          THEN p.amount END), 0) AS available,
       COALESCE(SUM(CASE WHEN a.kind = 'cust_held'               THEN p.amount END), 0) AS held,
       COALESCE(SUM(CASE WHEN a.kind = 'cust_pending_withdrawal' THEN p.amount END), 0) AS pending_withdrawal
  FROM customer c
  LEFT JOIN account a        ON a.customer_id = c.id
  LEFT JOIN ledger_posting p ON p.account_id  = a.id
 GROUP BY c.id;

-- The headline safety figure: bank balance minus what is owed to customers.
-- If headroom is ever negative, customer money is short.
CREATE VIEW v_solvency_check AS
SELECT
  COALESCE(SUM(CASE WHEN a.kind = 'bank' THEN p.amount END), 0) AS bank_balance,
  COALESCE(SUM(CASE WHEN a.kind IN ('cust_available','cust_held','cust_pending_withdrawal')
                    THEN p.amount END), 0) AS owed_to_customers,
  COALESCE(SUM(CASE WHEN a.kind = 'bank' THEN p.amount END), 0)
  - COALESCE(SUM(CASE WHEN a.kind IN ('cust_available','cust_held','cust_pending_withdrawal')
                      THEN p.amount END), 0) AS headroom
  FROM ledger_posting p
  JOIN account a ON a.id = p.account_id;

-- Whole-system integrity. This MUST always return 0.
CREATE VIEW v_ledger_global_zero AS
SELECT COALESCE(SUM(amount), 0) AS must_be_zero FROM ledger_posting;

-- Cache-vs-truth drift detector. This MUST always return zero rows.
CREATE VIEW v_balance_drift AS
SELECT ab.account_id, ab.balance AS cached, vb.balance AS derived,
       ab.balance - vb.balance AS drift
  FROM account_balance ab
  JOIN v_account_balance vb ON vb.account_id = ab.account_id
 WHERE ab.balance <> vb.balance;
```

`v_ledger_global_zero` and `v_balance_drift` are monitored continuously, not just at daily close.
An alert on either is a stop-the-line event.

### 11.8 Rounding rule

- Round half-up to 4 decimal places.
- The residue posts to `dahab_spread` on gold sales, `dahab_commission` otherwise.
- **No customer leg ever carries a rounding artefact.** The customer legs are computed first; the
  Dahab leg absorbs whatever makes the set sum to zero.
- Every worked example in the test suite asserts the residue posting explicitly.

---

## 12. Pricing feeds and reproducibility

Every price ever shown to a customer must be reproducible years later. That needs the rate, the
corrections applied at that instant, and the formula version — all snapshotted, never re-derived
from today's settings.

```sql
-- Every rate the platform used, from Evolve or entered by hand.
CREATE TABLE gold_rate_snapshot (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  source         ENUM('evolve','manual') NOT NULL,
  karat_code     SMALLINT UNSIGNED NOT NULL,
  rate_per_gram  DECIMAL(18,4) NOT NULL,            -- R, before corrections
  -- The corrections in force at this instant, copied in so a later settings
  -- change can never alter a historical price.
  correction_buy_side  DECIMAL(18,4) NOT NULL,
  correction_sell_side DECIMAL(18,4) NOT NULL,
  rate_buy_side  DECIMAL(18,4) GENERATED ALWAYS AS (rate_per_gram + correction_buy_side)  STORED,
  rate_sell_side DECIMAL(18,4) GENERATED ALWAYS AS (rate_per_gram + correction_sell_side) STORED,
  formula_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  taken_by       BIGINT UNSIGNED NULL,              -- staff, for source = 'manual'
  taken_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_ulid (ulid),
  KEY idx_rate_karat_time (karat_code, taken_at),
  CONSTRAINT fk_rate_karat FOREIGN KEY (karat_code) REFERENCES karat(karat_code),
  CONSTRAINT fk_rate_staff FOREIGN KEY (taken_by)   REFERENCES staff(id),
  CONSTRAINT chk_rate_positive CHECK (rate_per_gram > 0),
  CONSTRAINT chk_rate_manual_actor CHECK (source <> 'manual' OR taken_by IS NOT NULL),
  CONSTRAINT chk_rate_sides CHECK (correction_sell_side >= correction_buy_side)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

DELIMITER $$
CREATE TRIGGER trg_rate_no_update BEFORE UPDATE ON gold_rate_snapshot FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: gold_rate_snapshot'; END$$
DELIMITER ;
```

`chk_rate_sides` is the structural form of "the seller is paid at the buy side, the buyer pays the
sell side". If the corrections were ever inverted, Dahab would pay out more than it takes in on
every gold sale; the constraint makes that unsavable.

```sql
-- The manual-price fallback when the Evolve feed is down. CEO or Finance only.
-- A deviation above manualprice.confirm_deviation_pct needs a second confirmation
-- by a DIFFERENT person before it goes live.
CREATE TABLE manual_gold_price (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_snapshot_id BIGINT UNSIGNED NOT NULL,
  karat_code     SMALLINT UNSIGNED NOT NULL,
  rate_per_gram  DECIMAL(18,4) NOT NULL,
  last_feed_rate DECIMAL(18,4) NULL,                -- what Evolve last said
  deviation_pct  DECIMAL(8,4) NULL,
  reason         VARCHAR(500) NOT NULL,
  entered_by     BIGINT UNSIGNED NOT NULL,
  confirmed_by   BIGINT UNSIGNED NULL,
  confirmed_at   DATETIME(6) NULL,
  effective_from DATETIME(6) NOT NULL,
  effective_until DATETIME(6) NULL,                 -- set when Evolve recovers
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_manual_price_window (karat_code, effective_from, effective_until),
  CONSTRAINT fk_mgp_snapshot  FOREIGN KEY (rate_snapshot_id) REFERENCES gold_rate_snapshot(id),
  CONSTRAINT fk_mgp_karat     FOREIGN KEY (karat_code)  REFERENCES karat(karat_code),
  CONSTRAINT fk_mgp_entered   FOREIGN KEY (entered_by)  REFERENCES staff(id),
  CONSTRAINT fk_mgp_confirmed FOREIGN KEY (confirmed_by) REFERENCES staff(id),
  CONSTRAINT chk_mgp_second_person CHECK (confirmed_by IS NULL OR confirmed_by <> entered_by),
  CONSTRAINT chk_mgp_window CHECK (effective_until IS NULL OR effective_until > effective_from)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Rapaport weekly matrix. GUIDANCE ONLY — never a valuation, never a price shown
-- to a customer. Used by the market-maker approval screen.
CREATE TABLE rapaport_matrix (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  published_on  DATE NOT NULL,
  shape         VARCHAR(40) NOT NULL,
  clarity       VARCHAR(20) NOT NULL,
  colour        VARCHAR(20) NOT NULL,
  carat_from    DECIMAL(8,3) NOT NULL,
  carat_to      DECIMAL(8,3) NOT NULL,
  price_per_carat_usd DECIMAL(18,4) NOT NULL,
  uploaded_by   BIGINT UNSIGNED NOT NULL,
  uploaded_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rap (published_on, shape, clarity, colour, carat_from, carat_to),
  KEY idx_rap_lookup (shape, clarity, colour, carat_from, carat_to),
  CONSTRAINT fk_rap_staff FOREIGN KEY (uploaded_by) REFERENCES staff(id),
  CONSTRAINT chk_rap_carat_range CHECK (carat_to >= carat_from)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 12.1 The pricing formulas

Snapshotted at two moments: **join queue** (price lock) and **pay balance** (settlement on the
IGI-confirmed weight).

**Gold**

```
R_sell = R + price_correction.sell_side
R_buy  = R + price_correction.buy_side

buyer_total     = R_sell * W * p + making_charge_per_g * W
seller_gross    = R_buy  * W * p + making_charge_per_g * W
spread          = (R_sell - R_buy) * W * p
commission      = max(commission.gold_pct * (making_charge_per_g * W), commission.minimum_egp)
vat             = vat.pct * commission
seller_proceeds = seller_gross - commission - vat
```

**Diamond / gold with diamond** — a fixed price the seller sets; it does not move with the market,
so there is **no spread**. Dahab's margin is the stone commission only.

```
buyer_total     = asking_price
commission      = max(commission.stone_pct * value_above_gold, commission.minimum_egp)
vat             = vat.pct * commission
seller_proceeds = asking_price - commission - vat
```

`W` is the stated weight at lock and the **IGI-measured** weight at settlement; `p` is
`karat.purity_ratio`. Commission is never taken from the gold value — only from the making charge
(gold) or the value above gold (stones).

---

## 13. Marketplace — listings

A piece put up for sale. The seller names the branch or branches she is willing to deliver to **at
listing time**; the final branch is chosen at acceptance from that set.

```sql
CREATE TABLE listing (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  listing_ref    VARCHAR(20) NOT NULL,              -- e.g. DH-L-004417, shown to customers
  seller_id      BIGINT UNSIGNED NOT NULL,
  category       ENUM('gold','diamond','gold_with_diamond') NOT NULL,
  piece_type_id  SMALLINT UNSIGNED NOT NULL,
  karat_code     SMALLINT UNSIGNED NULL,            -- NULL for a pure diamond piece
  stated_weight_g DECIMAL(10,3) NULL,               -- NULL for a pure diamond piece
  making_charge_per_g DECIMAL(18,4) NULL,           -- gold only
  asking_price   DECIMAL(18,4) NULL,                -- stones / whole-piece ask
  title          VARCHAR(200) NULL,
  description    TEXT NULL,
  state          ENUM('draft','in_review','changes_requested','live','reserved','accepted',
                      'at_inspection','settling','sold','withdrawn','suspended_hold',
                      'uncollected_expired','awaiting_seller_return','seller_unclaimed')
                 NOT NULL DEFAULT 'draft',
  -- Denormalised queue depth for fast display. Source of truth is buy_request;
  -- kept in step by trg_sync_listing_queue.
  active_queue_count INT UNSIGNED NOT NULL DEFAULT 0,
  -- A free 0% relist window after collecting a returned piece.
  free_relist_until DATETIME(6) NULL,
  relisted_from_id BIGINT UNSIGNED NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  listed_at      DATETIME(6) NULL,                  -- when it first went live
  updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_listing_ulid (ulid),
  UNIQUE KEY uq_listing_ref  (listing_ref),
  KEY idx_listing_state  (state, listed_at),
  KEY idx_listing_seller (seller_id, state),
  KEY idx_listing_browse (state, category, piece_type_id),
  KEY idx_listing_age    (listed_at),
  FULLTEXT KEY ftx_listing_text (title, description) WITH PARSER ngram,
  CONSTRAINT fk_listing_seller   FOREIGN KEY (seller_id)     REFERENCES customer(id),
  CONSTRAINT fk_listing_type     FOREIGN KEY (piece_type_id) REFERENCES piece_type(id),
  CONSTRAINT fk_listing_karat    FOREIGN KEY (karat_code)    REFERENCES karat(karat_code),
  CONSTRAINT fk_listing_relisted FOREIGN KEY (relisted_from_id) REFERENCES listing(id),
  -- Gold and gold-with-diamond must carry karat and weight.
  CONSTRAINT chk_listing_gold_fields CHECK (
    category = 'diamond' OR (karat_code IS NOT NULL AND stated_weight_g IS NOT NULL)),
  -- Gold is priced from the rate + making charge; stones carry an asking price.
  CONSTRAINT chk_listing_price_fields CHECK (
    (category = 'gold'  AND making_charge_per_g IS NOT NULL)
    OR (category <> 'gold' AND asking_price IS NOT NULL)),
  CONSTRAINT chk_listing_queue_nonneg CHECK (active_queue_count >= 0),
  CONSTRAINT chk_listing_weight_positive CHECK (stated_weight_g IS NULL OR stated_weight_g > 0),
  CONSTRAINT chk_listing_live_has_date CHECK (
    state IN ('draft','in_review','changes_requested') OR listed_at IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 13.1 Listing media, branch options, declarations, review

```sql
-- Photos, video, the uploaded original invoice, the stone certificate.
CREATE TABLE listing_media (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid        CHAR(26) NOT NULL,
  listing_id  BIGINT UNSIGNED NOT NULL,
  kind        ENUM('photo','video','invoice','stone_certificate') NOT NULL,
  storage_ref VARCHAR(500) NOT NULL,
  mime_type   VARCHAR(100) NULL,
  byte_size   INT UNSIGNED NULL,
  -- The invoice stays private until the sale completes.
  is_private  BOOLEAN NOT NULL DEFAULT FALSE,
  is_primary  BOOLEAN NOT NULL DEFAULT FALSE,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- At most one primary photo per listing (NULL elsewhere, so MySQL ignores it).
  primary_key_guard BIGINT UNSIGNED
    GENERATED ALWAYS AS (IF(is_primary, listing_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_ulid (ulid),
  UNIQUE KEY uq_media_one_primary (primary_key_guard),
  KEY idx_media_listing (listing_id, kind, sort_order),
  CONSTRAINT fk_media_listing FOREIGN KEY (listing_id) REFERENCES listing(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- The branches the seller named at listing. The final branch MUST be one of these.
CREATE TABLE listing_branch_option (
  listing_id BIGINT UNSIGNED NOT NULL,
  branch_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (listing_id, branch_id),
  KEY idx_lbo_branch (branch_id),
  CONSTRAINT fk_lbo_listing FOREIGN KEY (listing_id) REFERENCES listing(id),
  CONSTRAINT fk_lbo_branch  FOREIGN KEY (branch_id)  REFERENCES branch(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- The ownership declaration accepted at listing, tied to this specific piece.
CREATE TABLE listing_ownership_declaration (
  listing_id   BIGINT UNSIGNED NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  legal_doc_id SMALLINT UNSIGNED NOT NULL,
  acceptance_id BIGINT UNSIGNED NOT NULL,
  accepted_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (listing_id),
  CONSTRAINT fk_lod_listing  FOREIGN KEY (listing_id)   REFERENCES listing(id),
  CONSTRAINT fk_lod_customer FOREIGN KEY (customer_id)  REFERENCES customer(id),
  CONSTRAINT fk_lod_legal    FOREIGN KEY (legal_doc_id) REFERENCES legal_document(id),
  CONSTRAINT fk_lod_accept   FOREIGN KEY (acceptance_id) REFERENCES agreement_acceptance(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Step 2 of the canonical flow: Dahab reviews the listing before it goes live.
-- One row per review round; a rejected listing can be resubmitted.
CREATE TABLE listing_review (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id   BIGINT UNSIGNED NOT NULL,
  round        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  outcome      ENUM('approved','changes_requested','rejected') NOT NULL,
  reviewer_id  BIGINT UNSIGNED NOT NULL,
  notes_en     VARCHAR(1000) NULL,
  notes_ar     VARCHAR(1000) NULL,
  requested_changes JSON NULL,                      -- ["better_photo","confirm_weight"]
  reviewed_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_round (listing_id, round),
  KEY idx_review_reviewer (reviewer_id, reviewed_at),
  CONSTRAINT fk_review_listing  FOREIGN KEY (listing_id)  REFERENCES listing(id),
  CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES staff(id),
  CONSTRAINT chk_review_reason CHECK (outcome = 'approved' OR notes_en IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

The public marketplace read is served by a view that exposes no seller identity — this is the MySQL
stand-in for the PostgreSQL "browsing must not leak the seller" policy note:

```sql
CREATE VIEW v_public_listing AS
SELECT l.ulid, l.listing_ref, l.category, l.piece_type_id, l.karat_code,
       l.stated_weight_g, l.making_charge_per_g, l.asking_price,
       l.title, l.description, l.state, l.active_queue_count, l.listed_at,
       (SELECT m.storage_ref FROM listing_media m
         WHERE m.listing_id = l.id AND m.is_primary AND NOT m.is_private LIMIT 1) AS primary_photo
  FROM listing l
 WHERE l.state IN ('live','reserved');
```

---

## 14. The queue — buy requests

Several buyers may want one listing. They queue in arrival order. **Each holds their own deposit
and their own locked price.** On acceptance the seller takes the first active in line; every other
active request is released and refunded in the same transaction.

```sql
CREATE TABLE buy_request (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  listing_id     BIGINT UNSIGNED NOT NULL,
  buyer_id       BIGINT UNSIGNED NOT NULL,
  state          ENUM('queued','accepted','released_not_chosen','released_declined',
                      'released_expired','withdrawn_by_buyer') NOT NULL DEFAULT 'queued',
  -- Arrival order within the listing. Lower = earlier = ahead in line.
  queue_position INT UNSIGNED NOT NULL,
  -- The price locked for THIS buyer at request time.
  rate_snapshot_id   BIGINT UNSIGNED NULL,          -- NULL for stones (fixed ask)
  locked_unit_rate   DECIMAL(18,4) NOT NULL,        -- the exact R used
  locked_total_price DECIMAL(18,4) NOT NULL,
  deposit_amount     DECIMAL(18,4) NOT NULL,        -- deposit.buyer_pct of the locked total
  deposit_hold_txn_id BIGINT UNSIGNED NULL,         -- the available -> held transaction
  deposit_release_txn_id BIGINT UNSIGNED NULL,      -- the refund, on any terminal state
  requested_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- Seller reply deadline, in clock hours, computed from setting at insert.
  seller_reply_deadline DATETIME(6) NOT NULL,
  resolved_at    DATETIME(6) NULL,                  -- when it left 'queued'
  resolved_by_staff_id BIGINT UNSIGNED NULL,        -- set when a sweep or admin resolved it
  notify_when_free BOOLEAN NOT NULL DEFAULT FALSE,
  -- Replaces the PostgreSQL partial unique index:
  --   UNIQUE (listing_id, buyer_id) WHERE state IN ('queued','accepted').
  active_guard   VARCHAR(64)
    GENERATED ALWAYS AS (IF(state IN ('queued','accepted'),
                            CONCAT(listing_id, ':', buyer_id), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_br_ulid (ulid),
  UNIQUE KEY uq_br_position (listing_id, queue_position),
  UNIQUE KEY uq_br_one_active (active_guard),
  KEY idx_br_listing_state (listing_id, state, queue_position),
  KEY idx_br_buyer (buyer_id, state),
  -- Feeds the seller-reply sweep. Replaces a PostgreSQL partial index.
  KEY idx_br_reply_sweep (state, seller_reply_deadline),
  CONSTRAINT fk_br_listing  FOREIGN KEY (listing_id) REFERENCES listing(id),
  CONSTRAINT fk_br_buyer    FOREIGN KEY (buyer_id)   REFERENCES customer(id),
  CONSTRAINT fk_br_rate     FOREIGN KEY (rate_snapshot_id) REFERENCES gold_rate_snapshot(id),
  CONSTRAINT fk_br_hold     FOREIGN KEY (deposit_hold_txn_id)    REFERENCES ledger_transaction(id),
  CONSTRAINT fk_br_release  FOREIGN KEY (deposit_release_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT fk_br_resolver FOREIGN KEY (resolved_by_staff_id)   REFERENCES staff(id),
  CONSTRAINT chk_br_deposit_positive CHECK (deposit_amount > 0),
  CONSTRAINT chk_br_deposit_le_total CHECK (deposit_amount <= locked_total_price),
  CONSTRAINT chk_br_resolved CHECK ((state = 'queued') = (resolved_at IS NULL)),
  -- Every terminal state except 'accepted' must carry its refund transaction.
  CONSTRAINT chk_br_refunded CHECK (
    state IN ('queued','accepted') OR deposit_release_txn_id IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

`chk_br_refunded` is the constraint that makes "a released buyer always gets their money back" a
property of the database rather than a property of the code that happened to run.

### 14.1 Queue position allocation

A dedicated counter table avoids the gaps-versus-reuse ambiguity and the race that a
`MAX(queue_position) + 1` would have under concurrency.

```sql
CREATE TABLE listing_queue_seq (
  listing_id BIGINT UNSIGNED NOT NULL,
  next_pos   INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (listing_id),
  CONSTRAINT fk_lqs_listing FOREIGN KEY (listing_id) REFERENCES listing(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

DELIMITER $$
CREATE PROCEDURE sp_next_queue_position(IN p_listing_id BIGINT UNSIGNED, OUT p_pos INT UNSIGNED)
MODIFIES SQL DATA
BEGIN
  INSERT INTO listing_queue_seq (listing_id, next_pos) VALUES (p_listing_id, 1)
    ON DUPLICATE KEY UPDATE next_pos = next_pos;          -- no-op, just ensures the row
  SELECT next_pos INTO p_pos FROM listing_queue_seq
   WHERE listing_id = p_listing_id FOR UPDATE;            -- serialises joins on this listing
  UPDATE listing_queue_seq SET next_pos = next_pos + 1 WHERE listing_id = p_listing_id;
END$$
DELIMITER ;
```

Two buyers joining the same listing at the same instant serialise on this row lock, so two requests
can never receive the same position and `uq_br_position` can never fire in normal operation.

### 14.2 Keeping the listing in step with its queue

```sql
DELIMITER $$
CREATE PROCEDURE sp_sync_listing_queue(IN p_listing_id BIGINT UNSIGNED)
MODIFIES SQL DATA
BEGIN
  DECLARE v_count    INT UNSIGNED;
  DECLARE v_accepted INT UNSIGNED;

  SELECT COUNT(*) INTO v_count
    FROM buy_request WHERE listing_id = p_listing_id AND state = 'queued';

  -- An accepted request means the seller has chosen a buyer and the use case is
  -- about to move the listing to 'accepted'. Without this, the queue emptying at
  -- the moment of acceptance would flip the listing back to 'live' and the
  -- application's reserved -> accepted move would be rejected as illegal.
  SELECT COUNT(*) INTO v_accepted
    FROM buy_request WHERE listing_id = p_listing_id AND state = 'accepted';

  UPDATE listing
     SET active_queue_count = v_count,
         state = CASE
                   WHEN state IN ('live','reserved') AND v_accepted = 0
                     THEN IF(v_count > 0, 'reserved', 'live')
                   ELSE state                       -- never disturb a later lifecycle state
                 END
   WHERE id = p_listing_id;
END$$

CREATE TRIGGER trg_sync_queue_ins AFTER INSERT ON buy_request FOR EACH ROW
BEGIN CALL sp_sync_listing_queue(NEW.listing_id); END$$

CREATE TRIGGER trg_sync_queue_upd AFTER UPDATE ON buy_request FOR EACH ROW
BEGIN
  IF NOT (NEW.state <=> OLD.state) THEN CALL sp_sync_listing_queue(NEW.listing_id); END IF;
END$$
DELIMITER ;
```

### 14.3 Notify-when-free

A buyer who withdrew can ask to be told when the piece is free again. The notification fires only
when the listing returns to `live` with an empty queue.

```sql
CREATE TABLE notify_when_free (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id  BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  notified_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nwf (listing_id, customer_id),
  KEY idx_nwf_pending (listing_id, notified_at),
  CONSTRAINT fk_nwf_listing  FOREIGN KEY (listing_id)  REFERENCES listing(id),
  CONSTRAINT fk_nwf_customer FOREIGN KEY (customer_id) REFERENCES customer(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 14.4 Queue state machine

```
queued → accepted               seller took the head of the queue
queued → released_not_chosen    seller accepted someone else (atomic with that accept)
queued → released_declined      seller declined this request
queued → released_expired       seller reply deadline passed (sweep)
queued → withdrawn_by_buyer     buyer left the queue
```

All terminal states are one hop from `queued`. There are no back-edges. Every terminal transition
releases the deposit in the same database transaction.

---

## 15. Orders

An order is one accepted buyer's purchase. It is created **at seller acceptance** — only then are
the buyer chosen, the branch selected, and the reach-branch deadline computable. The price lock and
the deposit live on the `buy_request`, which is why they exist before any order does.

```sql
CREATE TABLE orders (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  order_ref      VARCHAR(20) NOT NULL,              -- e.g. DH-2026-004417
  listing_id     BIGINT UNSIGNED NOT NULL,
  buy_request_id BIGINT UNSIGNED NOT NULL,
  seller_id      BIGINT UNSIGNED NOT NULL,
  buyer_id       BIGINT UNSIGNED NOT NULL,
  state          ENUM('awaiting_delivery','at_inspection','inspection_passed',
                      'weight_adjust_pending','awaiting_balance','ready_to_collect',
                      'completed','cancelled_seller','cancelled_buyer_nopay',
                      'cancelled_inspection','disputed') NOT NULL DEFAULT 'awaiting_delivery',
  -- The final chosen branch. Must be one of the listing's named options —
  -- enforced by trg_order_branch_subset, since a CHECK cannot hold a subquery.
  branch_id      SMALLINT UNSIGNED NOT NULL,
  accepted_by    BIGINT UNSIGNED NOT NULL,          -- the seller
  accepted_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- Deadline to reach the branch, in WORKING hours, resolved to a wall-clock
  -- instant with fn_add_working_hours() at acceptance.
  reach_branch_deadline DATETIME(6) NOT NULL,
  -- The locked commercial figures copied from the accepted buy_request.
  -- NOTE: this locks the per-gram rate and the formula, NOT the final total.
  -- The total trues up on the IGI-confirmed weight at settlement.
  locked_unit_rate   DECIMAL(18,4) NOT NULL,
  locked_total_price DECIMAL(18,4) NOT NULL,
  deposit_amount     DECIMAL(18,4) NOT NULL,
  -- Final settled figures, written once at pay-balance.
  final_total_price  DECIMAL(18,4) NULL,
  seller_proceeds    DECIMAL(18,4) NULL,
  commission_amount  DECIMAL(18,4) NULL,
  vat_amount         DECIMAL(18,4) NULL,
  spread_amount      DECIMAL(18,4) NULL,
  settlement_txn_id  BIGINT UNSIGNED NULL,
  delivered_at       DATETIME(6) NULL,              -- piece reached the branch
  balance_due_deadline DATETIME(6) NULL,            -- set after inspection pass
  balance_paid_at    DATETIME(6) NULL,
  collect_deadline   DATETIME(6) NULL,              -- set after the balance is paid
  completed_at       DATETIME(6) NULL,
  cancelled_at       DATETIME(6) NULL,
  cancel_reason      VARCHAR(500) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_ulid    (ulid),
  UNIQUE KEY uq_order_ref     (order_ref),
  UNIQUE KEY uq_order_request (buy_request_id),      -- one order per accepted request
  KEY idx_order_state  (state, created_at),
  KEY idx_order_seller (seller_id, state),
  KEY idx_order_buyer  (buyer_id, state),
  KEY idx_order_branch (branch_id, state),
  -- Sweep indexes. Each replaces a PostgreSQL partial index; the sweep query
  -- filters on state first, so the composite is used end to end.
  KEY idx_order_sweep_reach   (state, reach_branch_deadline),
  KEY idx_order_sweep_balance (state, balance_due_deadline),
  KEY idx_order_sweep_collect (state, collect_deadline),
  CONSTRAINT fk_order_listing  FOREIGN KEY (listing_id)     REFERENCES listing(id),
  CONSTRAINT fk_order_request  FOREIGN KEY (buy_request_id) REFERENCES buy_request(id),
  CONSTRAINT fk_order_seller   FOREIGN KEY (seller_id)      REFERENCES customer(id),
  CONSTRAINT fk_order_buyer    FOREIGN KEY (buyer_id)       REFERENCES customer(id),
  CONSTRAINT fk_order_branch   FOREIGN KEY (branch_id)      REFERENCES branch(id),
  CONSTRAINT fk_order_acceptor FOREIGN KEY (accepted_by)    REFERENCES customer(id),
  CONSTRAINT fk_order_settle   FOREIGN KEY (settlement_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_order_parties CHECK (buyer_id <> seller_id),
  CONSTRAINT chk_order_acceptor_is_seller CHECK (accepted_by = seller_id),
  -- Settlement figures arrive together or not at all, and must reconcile.
  CONSTRAINT chk_order_settlement_complete CHECK (
    settlement_txn_id IS NULL
    OR (final_total_price IS NOT NULL AND seller_proceeds IS NOT NULL
        AND commission_amount IS NOT NULL AND vat_amount IS NOT NULL)),
  CONSTRAINT chk_order_settlement_balances CHECK (
    settlement_txn_id IS NULL
    OR final_total_price = seller_proceeds + commission_amount + vat_amount
                           + COALESCE(spread_amount, 0)),
  CONSTRAINT chk_order_cancel_reason CHECK (
    state NOT IN ('cancelled_seller','cancelled_buyer_nopay','cancelled_inspection')
    OR cancel_reason IS NOT NULL),
  CONSTRAINT chk_order_completed CHECK ((state = 'completed') = (completed_at IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

`chk_order_settlement_balances` is the order-level echo of the ledger invariant: the buyer's total
is exactly the seller's proceeds plus commission plus VAT plus spread. Two independent mechanisms
now have to agree before money can move.

### 15.1 Branch must be one the seller named

```sql
DELIMITER $$
CREATE PROCEDURE sp_assert_branch_option(IN p_listing_id BIGINT UNSIGNED,
                                         IN p_branch_id SMALLINT UNSIGNED)
READS SQL DATA
BEGIN
  IF NOT EXISTS (SELECT 1 FROM listing_branch_option
                  WHERE listing_id = p_listing_id AND branch_id = p_branch_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'branch is not among the branches the seller named at listing';
  END IF;
END$$

CREATE TRIGGER trg_order_branch_subset_ins BEFORE INSERT ON orders FOR EACH ROW
BEGIN CALL sp_assert_branch_option(NEW.listing_id, NEW.branch_id); END$$

CREATE TRIGGER trg_order_branch_subset_upd BEFORE UPDATE ON orders FOR EACH ROW
BEGIN
  IF NOT (NEW.branch_id <=> OLD.branch_id) THEN
    CALL sp_assert_branch_option(NEW.listing_id, NEW.branch_id);
  END IF;
END$$
DELIMITER ;
```

### 15.2 Branch changes, deadline extensions, seller cancellations

```sql
-- Admin-made branch changes on an open order. The reach-branch counter keeps
-- running; an extension, if granted, is a separate audited row.
CREATE TABLE order_branch_change (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id    BIGINT UNSIGNED NOT NULL,
  from_branch SMALLINT UNSIGNED NOT NULL,
  to_branch   SMALLINT UNSIGNED NOT NULL,
  changed_by  BIGINT UNSIGNED NOT NULL,             -- admin only
  extended_to DATETIME(6) NULL,                     -- if the admin also extended
  reason      VARCHAR(500) NOT NULL,
  changed_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_obc_order (order_id, changed_at),
  CONSTRAINT fk_obc_order FOREIGN KEY (order_id)    REFERENCES orders(id),
  CONSTRAINT fk_obc_from  FOREIGN KEY (from_branch) REFERENCES branch(id),
  CONSTRAINT fk_obc_to    FOREIGN KEY (to_branch)   REFERENCES branch(id),
  CONSTRAINT fk_obc_staff FOREIGN KEY (changed_by)  REFERENCES staff(id),
  CONSTRAINT chk_obc_actually_changed CHECK (from_branch <> to_branch)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE order_deadline_extension (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT UNSIGNED NOT NULL,
  which        ENUM('reach_branch','balance','collect') NOT NULL,
  old_deadline DATETIME(6) NOT NULL,
  new_deadline DATETIME(6) NOT NULL,
  granted_by   BIGINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  granted_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_ode_order (order_id, which, granted_at),
  CONSTRAINT fk_ode_order FOREIGN KEY (order_id)   REFERENCES orders(id),
  CONSTRAINT fk_ode_staff FOREIGN KEY (granted_by) REFERENCES staff(id),
  -- An extension only ever moves a deadline forward.
  CONSTRAINT chk_ode_forward CHECK (new_deadline > old_deadline)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Counts toward suspension.cancellations_threshold.
CREATE TABLE seller_cancellation (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT UNSIGNED NOT NULL,
  seller_id    BIGINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NULL,
  cancelled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_seller_cancel_order (order_id),
  KEY idx_seller_cancel (seller_id, cancelled_at),
  CONSTRAINT fk_sc_order  FOREIGN KEY (order_id)  REFERENCES orders(id),
  CONSTRAINT fk_sc_seller FOREIGN KEY (seller_id) REFERENCES customer(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

---

## 16. Inspection — immutable results

A submitted result is never edited. A correction is a **new row** that supersedes an earlier one;
both are kept forever. Any karat difference cancels the sale. A weight difference within
`inspection.weight_tolerance_pct` auto-adjusts; above it, the buyer must approve.

```sql
CREATE TABLE inspection_result (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid           CHAR(26) NOT NULL,
  order_id       BIGINT UNSIGNED NOT NULL,
  branch_id      SMALLINT UNSIGNED NOT NULL,
  inspected_by   BIGINT UNSIGNED NOT NULL,          -- an igi_branch staff row
  -- What the listing claimed, copied in for an immutable side-by-side.
  stated_karat    SMALLINT UNSIGNED NULL,
  stated_weight_g DECIMAL(10,3) NULL,
  -- What IGI measured.
  measured_karat    SMALLINT UNSIGNED NULL,
  measured_weight_g DECIMAL(10,3) NULL,
  measured_stone_grade VARCHAR(255) NULL,
  certificate_number   VARCHAR(150) NULL,
  inspector_note  VARCHAR(1000) NULL,
  -- Derived at insert by the settlement service.
  karat_mismatch  BOOLEAN NOT NULL,
  weight_diff_pct DECIMAL(8,4) NULL,
  outcome ENUM('pass','weight_adjust','karat_cancel','stone_regrade','fake_cancel') NOT NULL,
  supersedes_id  BIGINT UNSIGNED NULL,              -- if this row corrects an earlier one
  correction_reason VARCHAR(500) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_insp_ulid (ulid),
  UNIQUE KEY uq_insp_supersedes (supersedes_id),    -- a result is corrected at most once per chain
  KEY idx_insp_order (order_id, created_at),
  KEY idx_insp_branch (branch_id, created_at),
  KEY idx_insp_certificate (certificate_number),
  CONSTRAINT fk_insp_order      FOREIGN KEY (order_id)      REFERENCES orders(id),
  CONSTRAINT fk_insp_branch     FOREIGN KEY (branch_id)     REFERENCES branch(id),
  CONSTRAINT fk_insp_staff      FOREIGN KEY (inspected_by)  REFERENCES staff(id),
  CONSTRAINT fk_insp_supersedes FOREIGN KEY (supersedes_id) REFERENCES inspection_result(id),
  -- The karat flag cannot lie about the numbers beside it.
  CONSTRAINT chk_insp_karat_flag CHECK (
    karat_mismatch = IF(measured_karat <=> stated_karat, FALSE, TRUE)),
  -- And a mismatch has exactly one possible outcome.
  CONSTRAINT chk_insp_karat_cancels CHECK (
    karat_mismatch = FALSE OR outcome = 'karat_cancel'),
  CONSTRAINT chk_insp_correction_reason CHECK (
    supersedes_id IS NULL OR correction_reason IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

DELIMITER $$
CREATE TRIGGER trg_insp_no_update BEFORE UPDATE ON inspection_result FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'inspection results are immutable; insert a superseding row'; END$$
CREATE TRIGGER trg_insp_no_delete BEFORE DELETE ON inspection_result FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'inspection results are immutable; they are never deleted'; END$$
DELIMITER ;
```

`chk_insp_karat_flag` plus `chk_insp_karat_cancels` together make the "no tolerance on karat" rule
**impossible to violate through any code path**, including a direct SQL session. This is the single
most important constraint in the schema after the ledger balance.

> `<=>` is MySQL's NULL-safe equality. It is used deliberately: a NULL stated karat and a NULL
> measured karat is *not* a mismatch, whereas `=` would yield NULL and let the CHECK pass by
> accident. The `IF(...)` wrapper is not decoration — MySQL cannot parse `x = NOT (...)`, because
> `NOT` binds looser than `=`.

### 16.1 Buyer approval of an adjustment

Recorded when an above-tolerance weight adjustment or a stone regrade needs the buyer's decision.
Declining refunds in full.

```sql
CREATE TABLE settlement_decision (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL,
  order_id      BIGINT UNSIGNED NOT NULL,
  inspection_id BIGINT UNSIGNED NOT NULL,
  buyer_accepted BOOLEAN NOT NULL,
  old_price     DECIMAL(18,4) NOT NULL,
  new_price     DECIMAL(18,4) NOT NULL,
  decided_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  decided_ip    VARCHAR(45) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sd_ulid (ulid),
  UNIQUE KEY uq_sd_inspection (inspection_id),      -- one decision per inspection result
  KEY idx_sd_order (order_id),
  CONSTRAINT fk_sd_order FOREIGN KEY (order_id)      REFERENCES orders(id),
  CONSTRAINT fk_sd_insp  FOREIGN KEY (inspection_id) REFERENCES inspection_result(id),
  CONSTRAINT chk_sd_price_changed CHECK (new_price <> old_price)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 16.2 Outcomes

| Outcome | Order goes to | Money |
|---|---|---|
| `pass` | `awaiting_balance` | Deposit stays held; balance due within `deadline.buyer_pay_days` |
| `weight_adjust` (within tolerance) | `awaiting_balance` at the trued-up price | Auto-adjusted, no approval |
| `weight_adjust` (above tolerance) | `weight_adjust_pending` | Waits for `settlement_decision` |
| `stone_regrade` | `weight_adjust_pending` | Waits for `settlement_decision` |
| `karat_cancel` | `cancelled_inspection` | Deposit refunded in full; seller suspended for review |
| `fake_cancel` | `cancelled_inspection` | Deposit refunded in full; case file opened |

---

## 17. Handover, returns, payouts and withdrawals

### 17.1 Money timing — read this before the tables

The seller is settled, and commission + spread + VAT are taken, at **balance payment**
(`awaiting_balance → ready_to_collect`), **not** at handover. Collection is a physical handover
only: it moves no customer money.

Because the seller is already paid, a buyer who pays and never collects does not hold the seller up.
The piece simply waits at the branch (`listing.state = 'uncollected_expired'`).

```sql
CREATE TABLE collection (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  order_id     BIGINT UNSIGNED NOT NULL,
  code_hash    CHAR(64) NOT NULL,                   -- SHA-256 of the collection code
  code_issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- Proxy collection: the buyer authorises another person. Dahab does not verify
  -- the relationship; the authorisation tick lives in agreement_acceptance and
  -- the proxy's uploaded ID reference lives here.
  is_proxy     BOOLEAN NOT NULL DEFAULT FALSE,
  proxy_name   VARCHAR(150) NULL,
  proxy_phone  VARCHAR(30) NULL,
  proxy_id_storage_ref VARCHAR(500) NULL,
  proxy_acceptance_id  BIGINT UNSIGNED NULL,
  collected_at DATETIME(6) NULL,
  handover_by  BIGINT UNSIGNED NULL,                -- igi_branch confirms
  PRIMARY KEY (id),
  UNIQUE KEY uq_collection_ulid  (ulid),
  UNIQUE KEY uq_collection_order (order_id),
  KEY idx_collection_pending (collected_at),
  CONSTRAINT fk_col_order    FOREIGN KEY (order_id)    REFERENCES orders(id),
  CONSTRAINT fk_col_staff    FOREIGN KEY (handover_by) REFERENCES staff(id),
  CONSTRAINT fk_col_accept   FOREIGN KEY (proxy_acceptance_id) REFERENCES agreement_acceptance(id),
  CONSTRAINT chk_col_proxy_details CHECK (
    is_proxy = FALSE
    OR (proxy_name IS NOT NULL AND proxy_id_storage_ref IS NOT NULL
        AND proxy_acceptance_id IS NOT NULL)),
  CONSTRAINT chk_col_handover_named CHECK (
    collected_at IS NULL OR handover_by IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- The buyer failed to pay the balance: the piece goes back to the seller, who
-- collects it and receives deposit.seller_forfeit_share_pct of the deposit as
-- agreed compensation. The compensation posting (event_kind = 'deposit_forfeit')
-- is written when the no-pay is confirmed; this row records the physical return.
CREATE TABLE seller_return (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  order_id     BIGINT UNSIGNED NOT NULL,
  listing_id   BIGINT UNSIGNED NOT NULL,
  seller_id    BIGINT UNSIGNED NOT NULL,
  branch_id    SMALLINT UNSIGNED NOT NULL,
  code_hash    CHAR(64) NOT NULL,
  -- Resolved from deadline.seller_return_weeks at return time.
  return_deadline DATETIME(6) NOT NULL,
  compensation_txn_id BIGINT UNSIGNED NULL,
  compensation_amount DECIMAL(18,4) NULL,
  collected_at DATETIME(6) NULL,
  handover_by  BIGINT UNSIGNED NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sr_ulid  (ulid),
  UNIQUE KEY uq_sr_order (order_id),
  -- Feeds the seller-return sweep (replaces a PostgreSQL partial index).
  KEY idx_sr_deadline (collected_at, return_deadline),
  KEY idx_sr_seller (seller_id, collected_at),
  CONSTRAINT fk_sr_order   FOREIGN KEY (order_id)   REFERENCES orders(id),
  CONSTRAINT fk_sr_listing FOREIGN KEY (listing_id) REFERENCES listing(id),
  CONSTRAINT fk_sr_seller  FOREIGN KEY (seller_id)  REFERENCES customer(id),
  CONSTRAINT fk_sr_branch  FOREIGN KEY (branch_id)  REFERENCES branch(id),
  CONSTRAINT fk_sr_staff   FOREIGN KEY (handover_by) REFERENCES staff(id),
  CONSTRAINT fk_sr_comp    FOREIGN KEY (compensation_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_sr_handover_named CHECK (collected_at IS NULL OR handover_by IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 17.2 Payout accounts

Money leaves only to an account in the customer's own name. A payout-account change cancels any
in-flight withdrawal and pauses new withdrawals for `withdrawal.account_change_pause_hours`.

```sql
CREATE TABLE payout_account (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  account_name VARCHAR(150) NOT NULL,               -- must match the ID
  bank_name    VARCHAR(150) NOT NULL,
  account_number_or_iban VARCHAR(64) NOT NULL,
  state        ENUM('pending_review','active','removing','removed')
               NOT NULL DEFAULT 'pending_review',
  name_checked_by BIGINT UNSIGNED NULL,
  name_checked_at DATETIME(6) NULL,
  reject_reason   VARCHAR(500) NULL,
  activated_at DATETIME(6) NULL,                    -- drives the withdrawal pause
  removed_at   DATETIME(6) NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- At most one ACTIVE payout account per customer.
  active_guard BIGINT UNSIGNED
    GENERATED ALWAYS AS (IF(state = 'active', customer_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payout_ulid (ulid),
  UNIQUE KEY uq_payout_one_active (active_guard),
  KEY idx_payout_customer (customer_id, state),
  CONSTRAINT fk_payout_customer FOREIGN KEY (customer_id)     REFERENCES customer(id),
  CONSTRAINT fk_payout_checker  FOREIGN KEY (name_checked_by) REFERENCES staff(id),
  CONSTRAINT chk_payout_checked CHECK (
    state IN ('pending_review') OR (name_checked_by IS NOT NULL AND name_checked_at IS NOT NULL)),
  CONSTRAINT chk_payout_active_dated CHECK (state <> 'active' OR activated_at IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- A per-customer pause window opened by a payout-account change. The withdrawal
-- service refuses releases while UTC_TIMESTAMP(6) < pause_until.
CREATE TABLE withdrawal_pause (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  opened_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  pause_until DATETIME(6) NOT NULL,
  triggered_by_account BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_pause_customer (customer_id, pause_until),
  CONSTRAINT fk_pause_customer FOREIGN KEY (customer_id) REFERENCES customer(id),
  CONSTRAINT fk_pause_account  FOREIGN KEY (triggered_by_account) REFERENCES payout_account(id),
  CONSTRAINT chk_pause_window CHECK (pause_until > opened_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 17.3 Withdrawals

Every withdrawal is reviewed by a person before release. Authority is **CEO or Finance**
(open item OI-2.3, resolved).

```sql
CREATE TABLE withdrawal (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  withdrawal_ref VARCHAR(20) NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  payout_account_id BIGINT UNSIGNED NOT NULL,
  amount       DECIMAL(18,4) NOT NULL,
  state        ENUM('requested','under_review','on_hold_account_change','released',
                    'settled','rejected','cancelled') NOT NULL DEFAULT 'requested',
  -- available -> cust_pending_withdrawal at request time.
  hold_txn_id    BIGINT UNSIGNED NULL,
  -- cust_pending_withdrawal -> bank on release.
  release_txn_id BIGINT UNSIGNED NULL,
  -- cust_pending_withdrawal -> available on reject or cancel.
  return_txn_id  BIGINT UNSIGNED NULL,
  reviewed_by  BIGINT UNSIGNED NULL,                -- finance or ceo
  reviewed_at  DATETIME(6) NULL,
  reject_reason VARCHAR(500) NULL,
  bank_reference VARCHAR(100) NULL,
  requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  released_at  DATETIME(6) NULL,
  settled_at   DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_withdrawal_ulid (ulid),
  UNIQUE KEY uq_withdrawal_ref  (withdrawal_ref),
  KEY idx_withdrawal_customer (customer_id, state),
  KEY idx_withdrawal_queue (state, requested_at),
  CONSTRAINT fk_wd_customer FOREIGN KEY (customer_id)       REFERENCES customer(id),
  CONSTRAINT fk_wd_account  FOREIGN KEY (payout_account_id) REFERENCES payout_account(id),
  CONSTRAINT fk_wd_reviewer FOREIGN KEY (reviewed_by)       REFERENCES staff(id),
  CONSTRAINT fk_wd_hold     FOREIGN KEY (hold_txn_id)       REFERENCES ledger_transaction(id),
  CONSTRAINT fk_wd_release  FOREIGN KEY (release_txn_id)    REFERENCES ledger_transaction(id),
  CONSTRAINT fk_wd_return   FOREIGN KEY (return_txn_id)     REFERENCES ledger_transaction(id),
  CONSTRAINT chk_wd_amount CHECK (amount > 0),
  CONSTRAINT chk_wd_released_reviewed CHECK (
    state NOT IN ('released','settled')
    OR (reviewed_by IS NOT NULL AND release_txn_id IS NOT NULL)),
  CONSTRAINT chk_wd_reject_reason CHECK (state <> 'rejected' OR reject_reason IS NOT NULL),
  -- Rejected and cancelled withdrawals must have returned the money.
  CONSTRAINT chk_wd_returned CHECK (
    state NOT IN ('rejected','cancelled') OR return_txn_id IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

The pause re-check happens at release, not only at review claim, because a pause opened *after* a
reviewer claimed the withdrawal must still block it:

```sql
DELIMITER $$
CREATE PROCEDURE sp_assert_withdrawal_releasable(IN p_withdrawal_id BIGINT UNSIGNED)
READS SQL DATA
BEGIN
  DECLARE v_customer BIGINT UNSIGNED;
  DECLARE v_verified DATETIME(6);
  SELECT customer_id INTO v_customer FROM withdrawal WHERE id = p_withdrawal_id FOR UPDATE;

  IF EXISTS (SELECT 1 FROM withdrawal_pause
              WHERE customer_id = v_customer AND pause_until > UTC_TIMESTAMP(6)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'withdrawal paused: payout account changed within the pause window';
  END IF;

  SELECT email_verified_at INTO v_verified FROM customer WHERE id = v_customer;
  IF v_verified IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'withdrawal requires a verified email for the second check';
  END IF;
END$$
DELIMITER ;
```

---

## 18. Finance — advances, invoices, reconciliation

```sql
-- The first-sale advance. Dahab fronts a first-time GOLD seller their proceeds
-- before the buyer pays, as a trust incentive. Dahab does NOT buy the piece; it
-- recovers the advance from the buyer's later payment. Promo-code gated and
-- capped by payout.first_sale_cap_egp.
CREATE TABLE first_sale_advance (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  order_id     BIGINT UNSIGNED NOT NULL,
  seller_id    BIGINT UNSIGNED NOT NULL,
  amount       DECIMAL(18,4) NOT NULL,
  cap_applied  DECIMAL(18,4) NOT NULL,              -- the cap in force at the time
  promo_code   VARCHAR(40) NULL,
  enabled_by   BIGINT UNSIGNED NOT NULL,
  advance_txn_id BIGINT UNSIGNED NOT NULL,
  -- Filled at settlement. If proceeds < advance, Dahab absorbs the shortfall:
  -- there is no clawback from the seller.
  recovered_txn_id BIGINT UNSIGNED NULL,
  recovered_amount DECIMAL(18,4) NULL,
  shortfall_absorbed DECIMAL(18,4) NULL,
  advanced_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_fsa_ulid  (ulid),
  UNIQUE KEY uq_fsa_order (order_id),               -- one advance per order
  KEY idx_fsa_seller (seller_id),
  CONSTRAINT fk_fsa_order    FOREIGN KEY (order_id)  REFERENCES orders(id),
  CONSTRAINT fk_fsa_seller   FOREIGN KEY (seller_id) REFERENCES customer(id),
  CONSTRAINT fk_fsa_staff    FOREIGN KEY (enabled_by) REFERENCES staff(id),
  CONSTRAINT fk_fsa_advance  FOREIGN KEY (advance_txn_id)   REFERENCES ledger_transaction(id),
  CONSTRAINT fk_fsa_recovery FOREIGN KEY (recovered_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_fsa_amount CHECK (amount > 0 AND amount <= cap_applied)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Tax invoices, issued automatically at completion and filed with ETA.
-- One to each side of the transaction.
CREATE TABLE tax_invoice (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL,
  invoice_no    VARCHAR(40) NOT NULL,
  order_id      BIGINT UNSIGNED NOT NULL,
  party_role    ENUM('seller','buyer') NOT NULL,
  customer_id   BIGINT UNSIGNED NOT NULL,
  ledger_txn_id BIGINT UNSIGNED NOT NULL,           -- both sides cite the same settlement
  net_amount    DECIMAL(18,4) NOT NULL,
  vat_amount    DECIMAL(18,4) NOT NULL,
  gross_amount  DECIMAL(18,4) NOT NULL,
  eta_reference VARCHAR(100) NULL,                  -- the e-invoicing system id
  eta_status    ENUM('pending','submitted','accepted','rejected') NOT NULL DEFAULT 'pending',
  eta_error     VARCHAR(500) NULL,
  storage_ref   VARCHAR(500) NULL,
  issued_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_ulid (ulid),
  UNIQUE KEY uq_invoice_no   (invoice_no),
  UNIQUE KEY uq_invoice_side (order_id, party_role),
  KEY idx_invoice_eta (eta_status, issued_at),
  CONSTRAINT fk_inv_order    FOREIGN KEY (order_id)      REFERENCES orders(id),
  CONSTRAINT fk_inv_customer FOREIGN KEY (customer_id)   REFERENCES customer(id),
  CONSTRAINT fk_inv_txn      FOREIGN KEY (ledger_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_invoice_sum CHECK (gross_amount = net_amount + vat_amount)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- External bank movements: capital, rent, fees, profit draws. Recorded by hand
-- with proof, by CEO or Finance, and mirrored into the ledger.
CREATE TABLE bank_movement (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL,
  kind          ENUM('capital_in','rent','bank_charge','profit_draw','salary',
                     'vat_remittance','other') NOT NULL,
  amount        DECIMAL(18,4) NOT NULL,             -- signed
  occurred_on   DATE NOT NULL,
  reason        VARCHAR(500) NOT NULL,
  proof_ref     VARCHAR(500) NULL,
  recorded_by   BIGINT UNSIGNED NOT NULL,           -- ceo or finance
  ledger_txn_id BIGINT UNSIGNED NULL,
  recorded_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_bm_ulid (ulid),
  KEY idx_bm_date (occurred_on, kind),
  CONSTRAINT fk_bm_staff FOREIGN KEY (recorded_by)   REFERENCES staff(id),
  CONSTRAINT fk_bm_txn   FOREIGN KEY (ledger_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_bm_nonzero CHECK (amount <> 0)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Daily close. Each day is compared and, when clean, locked forever.
CREATE TABLE daily_close (
  close_date         DATE NOT NULL,
  bank_balance       DECIMAL(18,4) NOT NULL,
  customer_liability DECIMAL(18,4) NOT NULL,
  dahab_wallet       DECIMAL(18,4) NOT NULL,
  difference         DECIMAL(18,4) NOT NULL,
  notes              VARCHAR(1000) NULL,
  is_locked          BOOLEAN NOT NULL DEFAULT FALSE,
  closed_by          BIGINT UNSIGNED NULL,
  closed_at          DATETIME(6) NULL,
  PRIMARY KEY (close_date),
  CONSTRAINT fk_dc_staff FOREIGN KEY (closed_by) REFERENCES staff(id),
  CONSTRAINT chk_dc_locked_named CHECK (
    is_locked = FALSE OR (closed_by IS NOT NULL AND closed_at IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- A locked day is never reopened or altered.
DELIMITER $$
CREATE TRIGGER trg_daily_close_no_reopen BEFORE UPDATE ON daily_close FOR EACH ROW
BEGIN
  IF OLD.is_locked THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a locked daily_close cannot be changed';
  END IF;
END$$
CREATE TRIGGER trg_daily_close_no_delete BEFORE DELETE ON daily_close FOR EACH ROW
BEGIN
  IF OLD.is_locked THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a locked daily_close cannot be deleted';
  END IF;
END$$
DELIMITER ;
```

---

## 19. Programme and governance

### 19.1 Market maker and promo codes

```sql
CREATE TABLE promo_code (
  code         VARCHAR(40) NOT NULL,
  kind         ENUM('first_sale','market_maker') NOT NULL,
  -- A market-maker code is tied to exactly one customer account.
  tied_customer_id BIGINT UNSIGNED NULL,
  commission_waived BOOLEAN NOT NULL DEFAULT FALSE,
  gives_spread BOOLEAN NOT NULL DEFAULT FALSE,
  monthly_cap_egp DECIMAL(18,4) NULL,
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  created_by   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  deactivated_by BIGINT UNSIGNED NULL,
  deactivated_at DATETIME(6) NULL,
  PRIMARY KEY (code),
  KEY idx_promo_customer (tied_customer_id, is_active),
  CONSTRAINT fk_promo_customer FOREIGN KEY (tied_customer_id) REFERENCES customer(id),
  CONSTRAINT fk_promo_creator  FOREIGN KEY (created_by)       REFERENCES staff(id),
  CONSTRAINT fk_promo_deactivator FOREIGN KEY (deactivated_by) REFERENCES staff(id),
  CONSTRAINT chk_promo_mm_tied CHECK (kind <> 'market_maker' OR tied_customer_id IS NOT NULL),
  CONSTRAINT chk_promo_deactivated CHECK (
    deactivated_at IS NULL OR deactivated_by IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Admin approval of one specific piece for a market-maker purchase, recorded
-- with the numbers the approver actually saw. The piece must be older than
-- marketmaker.min_list_age_days; the service checks it and records it here.
CREATE TABLE market_maker_approval (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id   BIGINT UNSIGNED NOT NULL,
  approved_by  BIGINT UNSIGNED NOT NULL,
  asking_price DECIMAL(18,4) NOT NULL,
  gold_value   DECIMAL(18,4) NULL,
  rapaport_guide DECIMAL(18,4) NULL,
  list_age_days INT UNSIGNED NOT NULL,
  approved_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mma_listing (listing_id),
  CONSTRAINT fk_mma_listing FOREIGN KEY (listing_id)  REFERENCES listing(id),
  CONSTRAINT fk_mma_staff   FOREIGN KEY (approved_by) REFERENCES staff(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Every attempted use, allowed or blocked, with the reason.
CREATE TABLE promo_code_use (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(40) NOT NULL,
  order_id     BIGINT UNSIGNED NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  allowed      BOOLEAN NOT NULL,
  block_reason VARCHAR(255) NULL,
  used_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_pcu_code (code, used_at),
  KEY idx_pcu_customer (customer_id, used_at),
  CONSTRAINT fk_pcu_code     FOREIGN KEY (code)        REFERENCES promo_code(code),
  CONSTRAINT fk_pcu_order    FOREIGN KEY (order_id)    REFERENCES orders(id),
  CONSTRAINT fk_pcu_customer FOREIGN KEY (customer_id) REFERENCES customer(id),
  CONSTRAINT chk_pcu_block_reason CHECK (allowed = TRUE OR block_reason IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 19.2 Category controls

Three levels per category. **Anything with a locked price is left alone** — a category stop never
touches a buyer who already has a price.

```sql
CREATE TABLE category_control (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category   ENUM('gold','diamond','gold_with_diamond') NOT NULL,
  level      ENUM('stop_new_listings','pause_category','stop_everything') NOT NULL,
  is_active  BOOLEAN NOT NULL DEFAULT TRUE,
  message_en VARCHAR(500) NULL,
  message_ar VARCHAR(500) NULL,
  set_by     BIGINT UNSIGNED NOT NULL,
  set_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  cleared_by BIGINT UNSIGNED NULL,
  cleared_at DATETIME(6) NULL,
  -- At most one active control per category.
  active_guard VARCHAR(24)
    GENERATED ALWAYS AS (IF(is_active, CAST(category AS CHAR), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catctl_active (active_guard),
  KEY idx_catctl_history (category, set_at),
  CONSTRAINT fk_catctl_setter  FOREIGN KEY (set_by)     REFERENCES staff(id),
  CONSTRAINT fk_catctl_clearer FOREIGN KEY (cleared_by) REFERENCES staff(id),
  CONSTRAINT chk_catctl_cleared CHECK (
    (is_active = TRUE AND cleared_at IS NULL)
    OR (is_active = FALSE AND cleared_at IS NOT NULL AND cleared_by IS NOT NULL))
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 19.3 Disputes

A dispute cannot be closed silently: resolving one needs a written reply and a named resolver.

```sql
CREATE TABLE dispute (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  dispute_ref  VARCHAR(20) NOT NULL,
  order_id     BIGINT UNSIGNED NOT NULL,
  raised_by    BIGINT UNSIGNED NOT NULL,
  reason_code  ENUM('piece_not_as_described','karat_dispute','weight_dispute',
                    'non_delivery','non_collection','payment_issue','conduct','other') NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  detail       TEXT NULL,
  state        ENUM('open','passed_on','resolved') NOT NULL DEFAULT 'open',
  assigned_to  BIGINT UNSIGNED NULL,
  resolution_reply TEXT NULL,
  resolution_txn_id BIGINT UNSIGNED NULL,           -- if money moved to resolve it
  resolved_by  BIGINT UNSIGNED NULL,
  opened_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  resolved_at  DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dispute_ulid (ulid),
  UNIQUE KEY uq_dispute_ref  (dispute_ref),
  KEY idx_dispute_order (order_id),
  KEY idx_dispute_queue (state, opened_at),
  KEY idx_dispute_assignee (assigned_to, state),
  CONSTRAINT fk_dispute_order    FOREIGN KEY (order_id)    REFERENCES orders(id),
  CONSTRAINT fk_dispute_raiser   FOREIGN KEY (raised_by)   REFERENCES customer(id),
  CONSTRAINT fk_dispute_assignee FOREIGN KEY (assigned_to) REFERENCES staff(id),
  CONSTRAINT fk_dispute_resolver FOREIGN KEY (resolved_by) REFERENCES staff(id),
  CONSTRAINT fk_dispute_txn      FOREIGN KEY (resolution_txn_id) REFERENCES ledger_transaction(id),
  CONSTRAINT chk_dispute_resolved CHECK (
    state <> 'resolved'
    OR (resolution_reply IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL)),
  CONSTRAINT chk_dispute_passed_on CHECK (state <> 'passed_on' OR assigned_to IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE dispute_message (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispute_id   BIGINT UNSIGNED NOT NULL,
  author_type  ENUM('customer','staff') NOT NULL,
  author_id    BIGINT UNSIGNED NOT NULL,
  body         TEXT NOT NULL,
  attachment_ref VARCHAR(500) NULL,
  is_internal  BOOLEAN NOT NULL DEFAULT FALSE,      -- staff-only note
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_dispute_msg (dispute_id, created_at),
  CONSTRAINT fk_dm_dispute FOREIGN KEY (dispute_id) REFERENCES dispute(id),
  CONSTRAINT chk_dm_internal_is_staff CHECK (is_internal = FALSE OR author_type = 'staff')
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Case file assembly for law enforcement: built on request, always audited.
CREATE TABLE case_file (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL,
  order_id     BIGINT UNSIGNED NULL,
  customer_id  BIGINT UNSIGNED NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  received_by  VARCHAR(255) NULL,                   -- who it was handed to
  storage_ref  VARCHAR(500) NULL,
  built_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_casefile_ulid (ulid),
  KEY idx_casefile_order (order_id),
  CONSTRAINT fk_cf_order    FOREIGN KEY (order_id)     REFERENCES orders(id),
  CONSTRAINT fk_cf_customer FOREIGN KEY (customer_id)  REFERENCES customer(id),
  CONSTRAINT fk_cf_staff    FOREIGN KEY (requested_by) REFERENCES staff(id),
  CONSTRAINT chk_cf_subject CHECK (order_id IS NOT NULL OR customer_id IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

---

## 20. Audit — the spine of accountability

Every privileged action records who, when, from which device and address, what changed, and a
reason where one is required. **No role, including a founder, can edit or delete an entry.**

```sql
CREATE TABLE audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_staff_id    BIGINT UNSIGNED NULL,
  actor_customer_id BIGINT UNSIGNED NULL,
  action       VARCHAR(100) NOT NULL,               -- e.g. 'withdrawal.release'
  entity_type  VARCHAR(80)  NOT NULL,               -- e.g. 'withdrawal'
  entity_id    BIGINT UNSIGNED NULL,
  before_json  JSON NULL,
  after_json   JSON NULL,
  reason       VARCHAR(500) NULL,
  ip_address   VARCHAR(45) NULL,
  fingerprint_hash CHAR(64) NULL,
  request_id   CHAR(26) NULL,                       -- correlates with application logs
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  KEY idx_audit_staff  (actor_staff_id, created_at),
  KEY idx_audit_customer (actor_customer_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_staff    FOREIGN KEY (actor_staff_id)    REFERENCES staff(id),
  CONSTRAINT fk_audit_customer FOREIGN KEY (actor_customer_id) REFERENCES customer(id),
  CONSTRAINT chk_audit_actor CHECK (
    actor_staff_id IS NOT NULL OR actor_customer_id IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Every view of an identity document is recorded, including views that lead to
-- no decision at all.
CREATE TABLE document_view_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id BIGINT UNSIGNED NOT NULL,
  viewed_by   BIGINT UNSIGNED NOT NULL,
  purpose     VARCHAR(100) NULL,
  ip_address  VARCHAR(45) NULL,
  viewed_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_docview_doc (document_id, viewed_at),
  KEY idx_docview_staff (viewed_by, viewed_at),
  CONSTRAINT fk_dvl_document FOREIGN KEY (document_id) REFERENCES identity_document(id),
  CONSTRAINT fk_dvl_staff    FOREIGN KEY (viewed_by)   REFERENCES staff(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: audit_log'; END$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: audit_log'; END$$
CREATE TRIGGER trg_docview_no_update BEFORE UPDATE ON document_view_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: document_view_log'; END$$
CREATE TRIGGER trg_docview_no_delete BEFORE DELETE ON document_view_log FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only: document_view_log'; END$$
DELIMITER ;
```

Actions that must always produce an audit row:

```
listing.approve            buy_request.accept           inspection.submit
listing.reject             buy_request.decline          inspection.correct
listing.takedown           buy_request.expire_sweep     order.branch_change
customer.verify            order.deadline_extend        order.cancel
customer.suspend           withdrawal.review            withdrawal.release
customer.unsuspend         withdrawal.reject            payout_account.approve
document.view              setting.change               gold_price.manual_set
promo_code.create          category_control.set         compensation.pay
dispute.resolve            case_file.build              daily_close.lock
staff.create               staff.freeze                 staff.unfreeze
```

---

## 21. Operational tables

```sql
-- Server-side idempotency store. Redis is a cache; the database is the source of
-- truth for replay of anything that moves money.
CREATE TABLE idempotency_key (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idem_key     VARCHAR(190) NOT NULL,
  actor_type   ENUM('customer','staff','system') NOT NULL,
  actor_id     BIGINT UNSIGNED NULL,
  endpoint     VARCHAR(190) NOT NULL,
  request_hash CHAR(64) NOT NULL,                   -- SHA-256 of the canonical request body
  response_status SMALLINT UNSIGNED NULL,
  response_json JSON NULL,
  state        ENUM('in_flight','completed','failed') NOT NULL DEFAULT 'in_flight',
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  expires_at   DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idem (idem_key, endpoint),
  KEY idx_idem_expiry (expires_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

A replay with the same key but a **different** `request_hash` is a client bug and returns
`422 idempotency_key_reused`, never the cached response of the other request.

```sql
CREATE TABLE notification_template (
  code        VARCHAR(80) NOT NULL,
  event_family ENUM('kyc','money','listing','order','inspection','dispute','marketing')
               NOT NULL,
  is_critical BOOLEAN NOT NULL DEFAULT FALSE,       -- critical ones cannot be switched off
  title_en    VARCHAR(255) NOT NULL,
  title_ar    VARCHAR(255) NOT NULL,
  body_en     TEXT NOT NULL,
  body_ar     TEXT NOT NULL,
  channels    SET('push','sms','email','database') NOT NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (code),
  CONSTRAINT fk_nt_staff FOREIGN KEY (updated_by) REFERENCES staff(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- An audit trail of what actually went out.
CREATE TABLE notification_send (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL,
  customer_id   BIGINT UNSIGNED NULL,
  staff_id      BIGINT UNSIGNED NULL,
  template_code VARCHAR(80) NOT NULL,
  channel       ENUM('push','sms','email','database') NOT NULL,
  destination   VARCHAR(255) NULL,
  payload_json  JSON NULL,
  status        ENUM('queued','sent','delivered','failed','read') NOT NULL DEFAULT 'queued',
  provider_ref  VARCHAR(190) NULL,
  error         VARCHAR(500) NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at       DATETIME(6) NULL,
  read_at       DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_ulid (ulid),
  KEY idx_notif_customer (customer_id, created_at),
  KEY idx_notif_status (status, created_at),
  KEY idx_notif_template (template_code, created_at),
  CONSTRAINT fk_ns_customer FOREIGN KEY (customer_id)   REFERENCES customer(id),
  CONSTRAINT fk_ns_staff    FOREIGN KEY (staff_id)      REFERENCES staff(id),
  CONSTRAINT fk_ns_template FOREIGN KEY (template_code) REFERENCES notification_template(code),
  CONSTRAINT chk_ns_recipient CHECK (customer_id IS NOT NULL OR staff_id IS NOT NULL)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Customers may switch off non-critical notifications only. The application
-- refuses to write a row disabling a template whose is_critical is TRUE.
CREATE TABLE notification_preference (
  customer_id  BIGINT UNSIGNED NOT NULL,
  channel      ENUM('push','sms','email','database') NOT NULL,
  event_family ENUM('kyc','money','listing','order','inspection','dispute','marketing') NOT NULL,
  enabled      BOOLEAN NOT NULL DEFAULT TRUE,
  updated_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (customer_id, channel, event_family),
  CONSTRAINT fk_np_customer FOREIGN KEY (customer_id) REFERENCES customer(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Sweeps must be observable.
CREATE TABLE job_run_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job         VARCHAR(120) NOT NULL,
  system_actor_staff_id BIGINT UNSIGNED NOT NULL,
  started_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  finished_at DATETIME(6) NULL,
  rows_processed INT UNSIGNED NOT NULL DEFAULT 0,
  rows_failed    INT UNSIGNED NOT NULL DEFAULT 0,
  error       TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_job_run (job, started_at),
  CONSTRAINT fk_jrl_staff FOREIGN KEY (system_actor_staff_id) REFERENCES staff(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
```

### 21.1 The scheduled sweeps

| Job | Query it drives | Index used |
|---|---|---|
| `ExpireBuyRequestsJob` | `buy_request` where `state='queued'` and `seller_reply_deadline < now` | `idx_br_reply_sweep` |
| `ExpireReachBranchJob` | `orders` where `state='awaiting_delivery'` and `reach_branch_deadline < now` | `idx_order_sweep_reach` |
| `ExpireBalanceJob` | `orders` where `state='awaiting_balance'` and `balance_due_deadline < now` | `idx_order_sweep_balance` |
| `ExpireCollectJob` | `orders` where `state='ready_to_collect'` and `collect_deadline < now` | `idx_order_sweep_collect` |
| `ExpireSellerReturnJob` | `seller_return` where `collected_at IS NULL` and `return_deadline < now` | `idx_sr_deadline` |
| `LiftWithdrawalPauseJob` | `withdrawal` where `state='on_hold_account_change'` | `idx_withdrawal_queue` |
| `DailyCloseJob` | `v_solvency_check`, writes `daily_close` | — |
| `LedgerIntegrityJob` | `v_ledger_global_zero`, `v_balance_drift` | — |

Each runs as the `role = 'system'` staff row, writes a `job_run_log` row, and writes an `audit_log`
row per entity it changes.

---

## 22. State machines as data

The legal transitions are rows, not scattered `if` statements. Adding a transition is a reviewed
data change.

```sql
CREATE TABLE order_transition (
  from_state ENUM('awaiting_delivery','at_inspection','inspection_passed','weight_adjust_pending',
                  'awaiting_balance','ready_to_collect','completed','cancelled_seller',
                  'cancelled_buyer_nopay','cancelled_inspection','disputed') NOT NULL,
  to_state   ENUM('awaiting_delivery','at_inspection','inspection_passed','weight_adjust_pending',
                  'awaiting_balance','ready_to_collect','completed','cancelled_seller',
                  'cancelled_buyer_nopay','cancelled_inspection','disputed') NOT NULL,
  note       VARCHAR(255) NULL,
  PRIMARY KEY (from_state, to_state)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO order_transition (from_state, to_state, note) VALUES
 ('awaiting_delivery','at_inspection','seller reached the branch'),
 ('awaiting_delivery','cancelled_seller','seller cancelled after accepting, or missed the reach-branch deadline'),
 ('at_inspection','inspection_passed','karat and weight within tolerance'),
 ('at_inspection','weight_adjust_pending','weight difference above tolerance'),
 ('at_inspection','cancelled_inspection','karat mismatch or fake'),
 ('weight_adjust_pending','awaiting_balance','buyer accepted the new price'),
 ('weight_adjust_pending','cancelled_inspection','buyer declined the new price'),
 ('inspection_passed','awaiting_balance','proceed to balance, or to the first-sale advance'),
 ('awaiting_balance','ready_to_collect','buyer paid the balance; settlement fired'),
 ('awaiting_balance','cancelled_buyer_nopay','pay deadline missed'),
 ('ready_to_collect','completed','collected'),
 ('at_inspection','disputed','dispute opened'),
 ('awaiting_balance','disputed','dispute opened'),
 ('ready_to_collect','disputed','dispute opened'),
 ('disputed','awaiting_balance','dispute resolved, resume'),
 ('disputed','ready_to_collect','dispute resolved, resume'),
 ('disputed','cancelled_inspection','dispute resolved against the sale');

CREATE TABLE listing_transition (
  from_state ENUM('draft','in_review','changes_requested','live','reserved','accepted',
                  'at_inspection','settling','sold','withdrawn','suspended_hold',
                  'uncollected_expired','awaiting_seller_return','seller_unclaimed') NOT NULL,
  to_state   ENUM('draft','in_review','changes_requested','live','reserved','accepted',
                  'at_inspection','settling','sold','withdrawn','suspended_hold',
                  'uncollected_expired','awaiting_seller_return','seller_unclaimed') NOT NULL,
  note       VARCHAR(255) NULL,
  PRIMARY KEY (from_state, to_state)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO listing_transition (from_state, to_state, note) VALUES
 ('draft','in_review','submitted for listing review'),
 ('in_review','changes_requested','reviewer asked for a better photo or detail'),
 ('changes_requested','in_review','resubmitted'),
 ('in_review','live','approved and published'),
 ('live','reserved','first buy request queued'),
 ('reserved','live','queue emptied, all requests released'),
 ('reserved','accepted','seller accepted the head of the queue'),
 ('accepted','at_inspection','piece delivered to the branch'),
 ('at_inspection','settling','inspection recorded'),
 ('settling','sold','completed'),
 ('settling','live','sale fell through; back on the market'),
 ('live','withdrawn','seller or admin took it down'),
 ('reserved','withdrawn','taken down; no locked price affected'),
 ('live','suspended_hold','category paused or account suspended'),
 ('reserved','suspended_hold','category paused'),
 ('suspended_hold','live','category reopened'),
 ('sold','uncollected_expired','buyer paid, never collected, collect window passed'),
 ('uncollected_expired','sold','buyer made contact and collected'),
 ('settling','awaiting_seller_return','buyer did not pay; piece returns to the seller'),
 ('accepted','awaiting_seller_return','buyer did not pay before delivery; piece returns'),
 ('at_inspection','awaiting_seller_return','buyer did not pay; piece returns to the seller'),
 ('awaiting_seller_return','withdrawn','seller collected the returned piece'),
 ('awaiting_seller_return','live','seller chose to relist instead of collecting'),
 ('awaiting_seller_return','seller_unclaimed','seller-return window passed; seller never came'),
 ('seller_unclaimed','withdrawn','seller finally collected, or Dahab handed it over'),
 ('seller_unclaimed','live','relisted after contact');

CREATE TABLE buy_request_transition (
  from_state ENUM('queued','accepted','released_not_chosen','released_declined',
                  'released_expired','withdrawn_by_buyer') NOT NULL,
  to_state   ENUM('queued','accepted','released_not_chosen','released_declined',
                  'released_expired','withdrawn_by_buyer') NOT NULL,
  note       VARCHAR(255) NULL,
  PRIMARY KEY (from_state, to_state)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO buy_request_transition (from_state, to_state, note) VALUES
 ('queued','accepted','seller took the head of the queue'),
 ('queued','released_not_chosen','seller accepted someone ahead; refund'),
 ('queued','released_declined','seller declined this request; refund'),
 ('queued','released_expired','seller reply deadline passed; refund'),
 ('queued','withdrawn_by_buyer','buyer left the queue; refund');

CREATE TABLE withdrawal_transition (
  from_state ENUM('requested','under_review','on_hold_account_change','released',
                  'settled','rejected','cancelled') NOT NULL,
  to_state   ENUM('requested','under_review','on_hold_account_change','released',
                  'settled','rejected','cancelled') NOT NULL,
  note       VARCHAR(255) NULL,
  PRIMARY KEY (from_state, to_state)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO withdrawal_transition (from_state, to_state, note) VALUES
 ('requested','under_review','picked up for review'),
 ('under_review','released','approved and sent to the bank'),
 ('under_review','rejected','reviewer rejected; funds returned'),
 ('requested','on_hold_account_change','payout account changed; paused'),
 ('on_hold_account_change','under_review','pause window elapsed'),
 ('requested','cancelled','holder cancelled'),
 ('under_review','cancelled','holder cancelled'),
 ('released','settled','confirmed arrived at the bank');
```

### 22.1 The guard triggers

```sql
DELIMITER $$
CREATE TRIGGER trg_order_transition_guard BEFORE UPDATE ON orders FOR EACH ROW
BEGIN
  IF NOT (NEW.state <=> OLD.state) THEN
    IF NOT EXISTS (SELECT 1 FROM order_transition
                    WHERE from_state = OLD.state AND to_state = NEW.state) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'illegal order state transition';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_listing_transition_guard BEFORE UPDATE ON listing FOR EACH ROW
BEGIN
  IF NOT (NEW.state <=> OLD.state) THEN
    IF NOT EXISTS (SELECT 1 FROM listing_transition
                    WHERE from_state = OLD.state AND to_state = NEW.state) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'illegal listing state transition';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_br_transition_guard BEFORE UPDATE ON buy_request FOR EACH ROW
BEGIN
  IF NOT (NEW.state <=> OLD.state) THEN
    IF NOT EXISTS (SELECT 1 FROM buy_request_transition
                    WHERE from_state = OLD.state AND to_state = NEW.state) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'illegal buy_request state transition';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_wd_transition_guard BEFORE UPDATE ON withdrawal FOR EACH ROW
BEGIN
  IF NOT (NEW.state <=> OLD.state) THEN
    IF NOT EXISTS (SELECT 1 FROM withdrawal_transition
                    WHERE from_state = OLD.state AND to_state = NEW.state) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'illegal withdrawal state transition';
    END IF;
  END IF;
END$$
DELIMITER ;
```

Combined with `SELECT ... FOR UPDATE` on the row inside the use case, an illegal move surfaces as a
clean error even under a race.

---

## 23. Ledger recipes — every money event, leg by leg

Each block is one `sp_ledger_post()` call inside one `DB::transaction`. Every set sums to zero.

### 23.1 Top-up

```
buyer  cust_available   + amount
bank                    + amount
external_equity         - amount        (the contra: money entered the system)
                                        sum = 0
```

### 23.2 Deposit hold (buyer joins the queue)

```
buyer  cust_available   - deposit
buyer  cust_held        + deposit       event_kind = deposit_hold
```

### 23.3 Deposit release (released, declined, expired, withdrawn)

```
buyer  cust_held        - deposit
buyer  cust_available   + deposit       event_kind = deposit_release
```

### 23.4 Settlement — gold, no advance (fires at pay-balance)

```
buyer  cust_available   - balance
buyer  cust_held        - deposit          (the hold is consumed, not refunded)
escrow                  + buyer_total      (pass-through in)
escrow                  - buyer_total      (pass-through out)
seller cust_available   + seller_proceeds  event_kind = settlement_seller
dahab_commission        + commission
vat_payable             + vat
dahab_spread            + spread           (gold only)
                                           sum = 0
```

Escrow nets to zero in the same transaction. It stays on the path deliberately: it is the clean
source for a post-settlement refund (§23.7).

### 23.5 Settlement with a first-sale advance

The seller already received `advance` at IGI receive (`event_kind = first_sale_payout`,
`seller cust_available + advance` against `external_equity - advance`). At settlement:

```
buyer  cust_available   - balance
buyer  cust_held        - deposit
escrow                  + buyer_total
escrow                  - buyer_total
seller cust_available   + (seller_proceeds - advance)    net of what they already got
external_equity         + advance                        Dahab recovers its front
dahab_commission        + commission
vat_payable             + vat
dahab_spread            + spread                         (gold only)
```

If the IGI weight makes `seller_proceeds < advance`, Dahab absorbs the difference as the realised
cost of the incentive. **There is no clawback from the seller**; the shortfall is recorded in
`first_sale_advance.shortfall_absorbed`.

### 23.6 Buyer no-pay — deposit forfeiture

No settlement fires, because the buyer never paid. The deposit splits by
`deposit.seller_forfeit_share_pct`:

```
buyer  cust_held           - deposit
seller cust_available      + (deposit * share)      event_kind = deposit_forfeit
dahab_forfeit_income       + (deposit * (1-share))
```

The piece returns to the seller: `listing.state → awaiting_seller_return`, and a `seller_return`
row is created with its own deadline. Because settlement never fired, there is no seller settlement
to reverse.

### 23.7 Refund after payment (uncollected-paid disposition, or a dispute)

Constructed as a reversal transaction (`event_kind = 'reversal'`, `reverses_txn_id` set) with a
named actor and a written reason. The source is `escrow`, plus the `dahab_*` accounts if the
resolution requires undoing Dahab's take as well.

```
escrow                  + refund_amount
buyer  cust_available   + refund_amount
dahab_commission        - commission_portion       (only if the take is being undone)
vat_payable             - vat_portion
dahab_spread            - spread_portion
external_equity         - net                      (the balancing contra)
```

### 23.8 Withdrawal

```
Request:  buyer cust_available          - amount
          buyer cust_pending_withdrawal + amount     event_kind = withdrawal_request

Release:  buyer cust_pending_withdrawal - amount
          bank                          - amount     event_kind = withdrawal_release
          external_equity               + amount

Reject:   buyer cust_pending_withdrawal - amount
          buyer cust_available          + amount     event_kind = withdrawal_return
```

### 23.9 Compensation (goodwill or a dispute outcome)

Capped by `compensation.cap_per_payment_egp` and `compensation.cap_per_day_egp`, checked in the
service, always with a staff actor and a reason.

```
customer cust_available  + amount
external_equity          - amount        event_kind = compensation
```

---

## 24. Security model

MySQL has no row-level security, so customer isolation is enforced in three layers instead of one.

### 24.1 Database accounts and grants

```sql
-- The application: may read and write business tables, but may NOT write the
-- ledger directly. The only path to money is the procedure.
CREATE USER 'dahab_app'@'%' IDENTIFIED BY '<from the secret manager>';
GRANT SELECT, INSERT, UPDATE ON dahab.* TO 'dahab_app'@'%';
REVOKE INSERT, UPDATE, DELETE ON dahab.ledger_posting      FROM 'dahab_app'@'%';
REVOKE INSERT, UPDATE, DELETE ON dahab.ledger_transaction  FROM 'dahab_app'@'%';
REVOKE INSERT, UPDATE, DELETE ON dahab.account_balance     FROM 'dahab_app'@'%';
REVOKE UPDATE, DELETE          ON dahab.audit_log          FROM 'dahab_app'@'%';
REVOKE UPDATE, DELETE          ON dahab.inspection_result  FROM 'dahab_app'@'%';
GRANT EXECUTE ON PROCEDURE dahab.sp_ledger_post           TO 'dahab_app'@'%';
GRANT EXECUTE ON PROCEDURE dahab.sp_next_queue_position   TO 'dahab_app'@'%';
GRANT EXECUTE ON FUNCTION  dahab.fn_add_working_hours     TO 'dahab_app'@'%';

-- Reporting and BI: reads views only, never base tables holding personal data.
CREATE USER 'dahab_report'@'%' IDENTIFIED BY '<from the secret manager>';
GRANT SELECT ON dahab.v_solvency_check   TO 'dahab_report'@'%';
GRANT SELECT ON dahab.v_account_balance  TO 'dahab_report'@'%';
GRANT SELECT ON dahab.v_public_listing   TO 'dahab_report'@'%';

-- Migrations only. Never used by the running application.
CREATE USER 'dahab_migrate'@'10.%' IDENTIFIED BY '<from the secret manager>';
GRANT ALL PRIVILEGES ON dahab.* TO 'dahab_migrate'@'10.%';
```

`DELETE` is not granted to the application on any table in this schema. Nothing in this business is
deleted; things change state.

### 24.2 Application layer

- A global Eloquent scope on `Listing`, `BuyRequest`, `Order`, `PayoutAccount`, `Withdrawal` and
  `IdentityDocument` restricts every customer-context query to the authenticated customer's rows.
- Laravel Policies gate each action; the IGI branch account sees no prices, no wallets and no
  contact details beyond what a handover requires.
- Wallet visibility in the admin dashboard is limited to `ceo` and `finance` (open item OI-1.4,
  resolved: commission and spread rate authority is **CEO + Finance**; the COO exclusion stands).
- Anonymous browsing reads `v_public_listing`, which cannot leak seller identity because the column
  is not in the view.

### 24.3 Encryption and retention

| Data | At rest | Retention |
|---|---|---|
| Identity document images | Application-side envelope encryption, KMS-held key, `key_version` tracked | Deleted at account closure; `identity_document` metadata row survives for the legally required period |
| Collection and return codes | SHA-256 hash only (`code_hash`) | Kept with the order |
| Device fingerprints | SHA-256 hash only | Until device revocation |
| OTP codes | Argon2/bcrypt hash only, never plaintext | Purged after `expires_at` |
| `audit_log`, `document_view_log` | Plain, append-only | Everything kept until the legal clinic settles retention (OI-1.5) |
| Ledger | Plain, append-only | Forever |

---

## 25. Index catalogue

Beyond every primary and foreign key, these are the indexes the access patterns require.

| Table | Index | Serves |
|---|---|---|
| `customer` | `uq_customer_phone`, `uq_customer_email` | sign-in |
| `listing` | `idx_listing_browse (state, category, piece_type_id)` | marketplace browse and filter |
| `listing` | `idx_listing_seller (seller_id, state)` | "my listings" |
| `listing` | `ftx_listing_text` | free-text search (ngram, Arabic) |
| `listing` | `idx_listing_age (listed_at)` | market-maker minimum age check |
| `buy_request` | `idx_br_listing_state (listing_id, state, queue_position)` | render the queue, find the head |
| `buy_request` | `idx_br_reply_sweep (state, seller_reply_deadline)` | seller-reply sweep |
| `buy_request` | `uq_br_one_active` | one active request per buyer per listing |
| `orders` | `idx_order_sweep_reach / _balance / _collect` | the three deadline sweeps |
| `orders` | `idx_order_branch (branch_id, state)` | the IGI branch worklist |
| `inspection_result` | `idx_insp_order (order_id, created_at)` | result chain, newest first |
| `ledger_posting` | `idx_posting_account (account_id, id)` | balance recomputation, statements |
| `ledger_transaction` | `idx_ltxn_order`, `idx_ltxn_customer` | order money trail, customer statement |
| `withdrawal` | `idx_withdrawal_queue (state, requested_at)` | the review queue |
| `audit_log` | `idx_audit_entity (entity_type, entity_id, created_at)` | "what happened to this order" |
| `notification_send` | `idx_notif_customer (customer_id, created_at)` | the in-app inbox |
| `idempotency_key` | `uq_idem (idem_key, endpoint)` | replay protection |

### 25.1 Partitioning — deferred, not forgotten

`ledger_posting` and `audit_log` grow monotonically. At roughly 5 million postings, partition by
month:

```sql
ALTER TABLE ledger_posting
  PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p2026_01 VALUES LESS THAN (TO_DAYS('2026-02-01')),
    PARTITION p2026_02 VALUES LESS THAN (TO_DAYS('2026-03-01')),
    PARTITION pmax     VALUES LESS THAN MAXVALUE
  );
```

> **Caveat to settle before doing it.** MySQL requires every unique key — including the primary key
> — to contain the partitioning column. `ledger_posting` would need `PRIMARY KEY (id, created_at)`,
> and its foreign keys would have to be dropped, because InnoDB does not support foreign keys on
> partitioned tables. That is a real loss of enforcement, so partitioning is a deliberate trade to
> be made when the volume justifies it, not day one. The tooling is written now; the switch flips
> later.

---

## 26. Integrity checks that run continuously

These are not tests; they are production monitors. Any non-empty result is a stop-the-line alert.

```sql
-- 1. The whole ledger must sum to zero.
SELECT must_be_zero FROM v_ledger_global_zero;                      -- expect 0

-- 2. The balance cache must agree with the postings.
SELECT * FROM v_balance_drift;                                      -- expect no rows

-- 3. No customer account may be negative.
SELECT * FROM v_account_balance
 WHERE kind IN ('cust_available','cust_held','cust_pending_withdrawal') AND balance < 0;

-- 4. Customer money must be covered by the bank.
SELECT headroom FROM v_solvency_check;                              -- expect >= 0

-- 5. Every released buy request must carry its refund.
SELECT id FROM buy_request
 WHERE state IN ('released_not_chosen','released_declined','released_expired','withdrawn_by_buyer')
   AND deposit_release_txn_id IS NULL;

-- 6. Every settled order's figures must reconcile.
SELECT id FROM orders
 WHERE settlement_txn_id IS NOT NULL
   AND final_total_price <> seller_proceeds + commission_amount + vat_amount
                            + COALESCE(spread_amount, 0);

-- 7. A karat mismatch must never have survived as a sale.
SELECT o.id FROM orders o
  JOIN inspection_result i ON i.order_id = o.id
 WHERE i.karat_mismatch = TRUE AND o.state IN ('completed','ready_to_collect');

-- 8. No held money without a live reason.
SELECT vw.customer_id, vw.held FROM v_customer_wallet vw
 WHERE vw.held > 0
   AND NOT EXISTS (SELECT 1 FROM buy_request br
                    WHERE br.buyer_id = vw.customer_id AND br.state IN ('queued','accepted'))
   AND NOT EXISTS (SELECT 1 FROM orders o
                    WHERE o.buyer_id = vw.customer_id
                      AND o.state IN ('awaiting_delivery','at_inspection','inspection_passed',
                                      'weight_adjust_pending','awaiting_balance','disputed'));

-- 9. Every tax invoice pair must cite the same settlement transaction.
SELECT order_id FROM tax_invoice GROUP BY order_id
HAVING COUNT(DISTINCT ledger_txn_id) > 1;

-- 10. An order in a terminal money state must have exactly one collection or one return.
SELECT o.id FROM orders o
 WHERE o.state = 'completed'
   AND NOT EXISTS (SELECT 1 FROM collection c WHERE c.order_id = o.id AND c.collected_at IS NOT NULL);
```

Checks 1 to 4 run every five minutes. Checks 5 to 10 run at daily close and block the close if they
return rows.

---

## 27. Migration order

Dependencies dictate a strict order. Laravel migrations, one file per numbered group:

```
01  reference        karat, piece_type, branch, branch_hours, branch_closure
02  settings         setting, setting_history          (staff FKs deferred)
03  staff            staff, founder_device_approval, account_freeze
04  settings_fk      ALTER setting / setting_history add staff FKs
05  customers        customer, customer_suspension, identity_document
06  auth             personal_access_token, session_device, otp_challenge, login_attempt
07  legal            legal_document, agreement_acceptance
08  ledger           account, account_balance, ledger_transaction, ledger_posting
                     (business-object FKs deferred)
09  pricing          gold_rate_snapshot, manual_gold_price, rapaport_matrix
10  listings         listing, listing_media, listing_branch_option,
                     listing_ownership_declaration, listing_review
11  queue            buy_request, listing_queue_seq, notify_when_free
12  orders           orders, order_branch_change, order_deadline_extension, seller_cancellation
13  inspection       inspection_result, settlement_decision
14  handover         collection, seller_return
15  payouts          payout_account, withdrawal_pause, withdrawal
16  ledger_fk        ALTER ledger_transaction add listing/order/buy_request/withdrawal FKs
17  finance          first_sale_advance, tax_invoice, bank_movement, daily_close
18  programme        promo_code, market_maker_approval, promo_code_use
19  governance       category_control, dispute, dispute_message, case_file
20  audit            audit_log, document_view_log
21  ops              idempotency_key, notification_template, notification_send,
                     notification_preference, job_run_log
22  transitions      the four transition tables + their seed rows
23  routines         all functions, procedures and triggers
24  views            all v_* views
25  seed             settings, karats, piece types, branches, the system staff row,
                     the singleton internal accounts
```

Group 25 must create exactly one account of each internal kind. `uq_account_singleton` makes a
second attempt fail loudly rather than quietly creating a parallel escrow.

```sql
INSERT INTO account (ulid, kind) VALUES
 ('<ulid>','escrow'), ('<ulid>','dahab_commission'), ('<ulid>','dahab_spread'),
 ('<ulid>','dahab_forfeit_income'), ('<ulid>','vat_payable'),
 ('<ulid>','bank'), ('<ulid>','external_equity');
```

### 27.1 Concurrency contract for the application

| Operation | Lock |
|---|---|
| Join the queue | `sp_next_queue_position()` locks `listing_queue_seq` `FOR UPDATE` |
| Accept a request | `SELECT ... FOR UPDATE` on the listing, then on every queued `buy_request` ordered by `queue_position` |
| Any state change | `SELECT ... FOR UPDATE` on the entity row, then the transition guard trigger validates |
| Any money movement | `sp_ledger_post()` locks `account_balance` in ascending `account_id` order |
| Withdrawal release | Lock the withdrawal row, then re-check `withdrawal_pause` — a pause opened after review-claim must still block |

---

## 28. Open business decisions this schema already answers

| Question | Answer in this schema |
|---|---|
| Can one gold item receive multiple active buy requests? | Yes — that is the queue. `uq_br_one_active` limits one *per buyer*. |
| Is inspection one-time or repeatable? | Repeatable through supersession. `inspection_result.supersedes_id`; nothing is ever edited. |
| Can the seller change the price before the lock? | The listing price moves with the rate; each buyer's lock is snapshotted at request time on `buy_request`. |
| How long does a price lock last? | Until `seller_reply_deadline`. The lock dies with the request. |
| Must the buyer fund the wallet before requesting? | Yes — `deposit_hold` cannot post if `cust_available` would go negative. |
| Can a wallet go negative? | No. `chk_ab_customer_nonneg`. |
| What happens to the hold when inspection fails? | Released in full — `deposit_release` — and `chk_br_refunded` proves it happened. |
| Can a completed settlement be reversed? | Yes, as a reversal transaction from escrow, with a named actor and a reason. Never as an edit. |
| Is commission global or per seller? | Global settings, with per-code waivers through `promo_code`. |
| Multiple currencies? | `account.currency` exists; every current path is EGP. Cross-currency needs an FX module, not a schema change. |
| Can one user be both buyer and seller? | Yes. `customer` has no role column; the role is per transaction. |
| Is IGI external or internal? | Internal shared branch accounts — `staff.role = 'igi_branch'` with a mandatory `branch_id`. |

### 28.1 Still open, and not schema-blocking

- Audit-log retention period (OI-1.5) — until it is settled, keep everything.
- ETA e-invoicing field specifics — `tax_invoice.eta_reference` and `eta_status` are placeholders
  awaiting Part 4 of the spec.
- Rapaport upload cadence and matrix shape — `rapaport_matrix` covers the common case.
- Whether `identity_document` should hold multiple pages per document (front, back) — currently one
  row per image, which already supports it.

---

## 29. What this design will not do

Stated plainly, so nobody looks for it:

- **No cart, no checkout, no shipping.** An order is a single accepted buy request.
- **No delivery to the buyer.** Handover is always at a branch, in person or by an authorised proxy.
- **No standby queue behind an accepted buyer.** Everyone else is released at acceptance; they can
  ask to be notified and rejoin at the back.
- **No automatic disposition after a window passes.** `uncollected_expired` and `seller_unclaimed`
  are states that wait for a human decision on contact. The schema provides the state, the deadline
  and the notification; it does not hand over or refund by itself.
- **No stored wallet balance as truth.** `account_balance` is a cache with a guard. If it ever
  disagrees with the postings, the postings win and `v_balance_drift` raises the alarm.
