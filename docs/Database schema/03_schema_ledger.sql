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
  order_id      UUID,   -- FK lt_order_fk added by spec 011
  buy_request_id UUID,  -- FK lt_request_fk added by spec 011 (deferred); one deposit_hold and at most one deposit_release per request (unique indexes)
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
