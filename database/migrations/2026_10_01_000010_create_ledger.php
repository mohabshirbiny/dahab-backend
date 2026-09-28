<?php

use App\Support\DatabaseActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The double-entry EGP ledger (spec 008). Mirrors
 * docs/Database schema/03_schema_ledger.sql (tables, append-only and
 * balance triggers, views, account provisioning) and the ledger block of
 * 05_schema_security.sql (forced row-level security).
 *
 * Amounts are signed and every entry sums to zero: a true double entry
 * (negative = debit). `bank` is the only asset account, so the cash in the
 * bank is -SUM(bank lines) — solvency_check reports it that way (R15).
 *
 * Rollback drops the ledger and its data; nothing is posted before this
 * feature.
 */
return new class extends Migration
{
    private const INTERNAL_KINDS = ['escrow', 'dahab_commission', 'dahab_spread', 'vat_payable', 'bank', 'external_equity'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // migrate:fresh drops tables but not types (same guard as the reference-data migration).
        DB::unprepared(<<<'SQL'
            DROP TYPE IF EXISTS ledger_event_kind CASCADE;
            DROP TYPE IF EXISTS account_kind CASCADE;

            CREATE TYPE account_kind AS ENUM (
              'cust_available', 'cust_held',
              'escrow', 'dahab_commission', 'dahab_spread', 'vat_payable',
              'bank', 'external_equity'
            );

            CREATE TYPE ledger_event_kind AS ENUM (
              'topup', 'deposit_hold', 'deposit_release', 'deposit_forfeit',
              'settlement_seller', 'first_sale_payout', 'commission', 'spread', 'vat',
              'balance_payment', 'withdrawal', 'compensation', 'external_bank_movement',
              'weight_adjustment', 'reversal'
            );

            CREATE TABLE account (
              account_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              kind         account_kind NOT NULL,
              customer_id  UUID REFERENCES customer(customer_id),
              currency     CHAR(3) NOT NULL DEFAULT 'EGP',
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT customer_accounts_have_owner CHECK (
                (kind IN ('cust_available','cust_held')) = (customer_id IS NOT NULL)
              ),
              CONSTRAINT one_account_per_customer_kind UNIQUE (customer_id, kind)
            );

            CREATE UNIQUE INDEX one_singleton_per_internal_kind
              ON account(kind) WHERE customer_id IS NULL;

            CREATE TABLE ledger_transaction (
              ledger_txn_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              event_kind      ledger_event_kind NOT NULL,
              listing_id      UUID,
              order_id        UUID,
              buy_request_id  UUID,
              withdrawal_id   UUID,
              customer_id     UUID REFERENCES customer(customer_id),
              staff_id        UUID REFERENCES staff(staff_id),
              memo            TEXT,
              created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
              reverses_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
              CONSTRAINT ledger_txn_has_actor CHECK (customer_id IS NOT NULL OR staff_id IS NOT NULL)
            );

            CREATE TABLE ledger_posting (
              posting_id    BIGSERIAL PRIMARY KEY,
              ledger_txn_id UUID NOT NULL REFERENCES ledger_transaction(ledger_txn_id),
              account_id    UUID NOT NULL REFERENCES account(account_id),
              amount        NUMERIC(18,4) NOT NULL,
              created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT posting_nonzero CHECK (amount <> 0)
            );

            CREATE INDEX idx_posting_account ON ledger_posting(account_id, posting_id) INCLUDE (amount, ledger_txn_id);
            CREATE INDEX idx_posting_txn     ON ledger_posting(ledger_txn_id);
            CREATE INDEX idx_ledger_txn_order ON ledger_transaction(order_id);
            CREATE INDEX idx_ledger_txn_created ON ledger_transaction(created_at);
            CREATE UNIQUE INDEX one_reversal_per_txn ON ledger_transaction(reverses_txn_id)
              WHERE reverses_txn_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION block_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'append-only table: % on % is not permitted', TG_OP, TG_TABLE_NAME;
            END $$;

            CREATE TRIGGER ledger_posting_no_update BEFORE UPDATE OR DELETE ON ledger_posting
              FOR EACH ROW EXECUTE FUNCTION block_mutation();
            CREATE TRIGGER ledger_txn_no_update BEFORE UPDATE OR DELETE ON ledger_transaction
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

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

            CREATE VIEW account_balance AS
              SELECT a.account_id, a.kind, a.customer_id,
                     COALESCE(SUM(p.amount),0) AS balance
              FROM account a
              LEFT JOIN ledger_posting p ON p.account_id = a.account_id
              GROUP BY a.account_id, a.kind, a.customer_id;

            CREATE VIEW customer_wallet AS
              SELECT c.customer_id,
                     COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_available'),0) AS available,
                     COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'cust_held'),0)      AS held
              FROM customer c
              LEFT JOIN account a       ON a.customer_id = c.customer_id
              LEFT JOIN ledger_posting p ON p.account_id = a.account_id
              GROUP BY c.customer_id;

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

            CREATE VIEW ledger_global_zero AS
              SELECT COALESCE(SUM(amount),0) AS must_be_zero FROM ledger_posting;

            COMMENT ON TABLE ledger_posting IS
              'Append-only. Postings are written only via the money service in balanced sets; direct UPDATE/DELETE is blocked by trigger.';

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
        SQL);

        $this->provision();
    }

    /**
     * Internal singletons plus both accounts for every existing customer;
     * idempotent. Runs elevated: the tables are under forced RLS, and a
     * migration may run before any scope is bound (e.g. RefreshDatabase).
     */
    public function provision(): void
    {
        DatabaseActor::elevate('maintenance', fn () => $this->insertAccounts());
    }

    private function insertAccounts(): void
    {
        DB::insert(
            'INSERT INTO account (kind) SELECT k::account_kind FROM unnest(?::text[]) AS k ON CONFLICT DO NOTHING',
            ['{'.implode(',', self::INTERNAL_KINDS).'}'],
        );

        DB::statement("
            INSERT INTO account (kind, customer_id)
            SELECT k::account_kind, c.customer_id
            FROM customer c CROSS JOIN unnest(ARRAY['cust_available','cust_held']) AS k
            ON CONFLICT (customer_id, kind) DO NOTHING
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_customer_accounts ON customer;
            DROP FUNCTION IF EXISTS create_customer_accounts();
            -- Policies reference the other ledger tables, so they go first.
            DROP POLICY IF EXISTS ledger_transaction_read ON ledger_transaction;
            DROP POLICY IF EXISTS ledger_transaction_lock ON ledger_transaction;
            DROP POLICY IF EXISTS ledger_posting_read ON ledger_posting;
            DROP VIEW IF EXISTS ledger_global_zero;
            DROP VIEW IF EXISTS solvency_check;
            DROP VIEW IF EXISTS customer_wallet;
            DROP VIEW IF EXISTS account_balance;
            DROP TABLE IF EXISTS ledger_posting;
            DROP TABLE IF EXISTS ledger_transaction;
            DROP TABLE IF EXISTS account;
            DROP FUNCTION IF EXISTS assert_customer_account_nonneg();
            DROP FUNCTION IF EXISTS assert_txn_balanced();
            DROP FUNCTION IF EXISTS block_mutation();
            DROP TYPE IF EXISTS ledger_event_kind;
            DROP TYPE IF EXISTS account_kind;
        SQL);
    }
};
