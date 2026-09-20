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
