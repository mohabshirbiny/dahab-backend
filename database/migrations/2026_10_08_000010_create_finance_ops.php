<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance operations (spec 015). Mirrors docs/Database schema:
 * 05_schema_security.sql §16 (`compensation` payable outside a dispute:
 * dispute, order and party optional, the check function relaxed) and §17
 * (`bank_movement` with the design's seven kinds, `daily_close` with the
 * typed statement balance and the books at the cut-off, the new
 * `wallet_adjustment` under forced row-level security; the deferred checks on
 * SQLSTATE DH011 tying each row to its ledger entry).
 *
 * ROLLBACK: down() refuses once any wallet adjustment, bank movement, daily
 * close or compensation without a dispute exists (each is tied to an
 * append-only ledger entry, or is a locked record). Otherwise it reverses
 * everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- 05 §16: compensation outside a dispute.
            ALTER TABLE compensation
              ALTER COLUMN dispute_id DROP NOT NULL,
              ALTER COLUMN order_id DROP NOT NULL,
              ALTER COLUMN party DROP NOT NULL,
              ADD CONSTRAINT compensation_dispute_needs_order CHECK (dispute_id IS NULL OR order_id IS NOT NULL),
              ADD CONSTRAINT compensation_party_with_order CHECK ((order_id IS NULL) = (party IS NULL));
            CREATE INDEX idx_compensation_paid_at ON compensation(paid_at, compensation_id);

            CREATE OR REPLACE FUNCTION compensation_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
              v_party UUID;
            BEGIN
              IF NEW.order_id IS NOT NULL THEN
                SELECT CASE NEW.party WHEN 'buyer' THEN o.buyer_id ELSE o.seller_id END INTO v_party
                  FROM "order" o WHERE o.order_id = NEW.order_id;
                IF v_party IS DISTINCT FROM NEW.customer_id THEN
                  RAISE EXCEPTION 'compensation % is not paid to the order''s %', NEW.compensation_id, NEW.party USING ERRCODE = 'DH009';
                END IF;
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (
                SELECT 1 FROM ledger_transaction t
                  JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
                  JOIN account a ON a.account_id = p.account_id
                 WHERE t.ledger_txn_id = NEW.ledger_txn_id AND t.event_kind = 'compensation'
                   AND t.order_id IS NOT DISTINCT FROM NEW.order_id
                   AND a.customer_id = NEW.customer_id AND a.kind = 'cust_available' AND p.amount = NEW.amount) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'compensation % has no matching ledger entry', NEW.compensation_id USING ERRCODE = 'DH009';
              END IF;
              RETURN NULL;
            END $$;

            -- 05 §17: money moved outside the app.
            DROP SEQUENCE IF EXISTS bank_movement_no_seq;
            CREATE SEQUENCE bank_movement_no_seq;

            CREATE TABLE bank_movement (
              movement_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              movement_no  BIGINT NOT NULL UNIQUE DEFAULT nextval('bank_movement_no_seq'),
              kind         TEXT NOT NULL CHECK (kind IN ('capital_in','operating_expense','bank_charge',
                             'profit_draw','own_transfer','supplier_refund','other')),
              amount       NUMERIC(18,4) NOT NULL CHECK (amount <> 0),
              occurred_on  DATE NOT NULL,
              reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 500),
              proof_ref    TEXT,
              proof_mime   TEXT,
              recorded_by  UUID NOT NULL REFERENCES staff(staff_id),
              ledger_txn_id UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
              recorded_at  TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              CONSTRAINT bank_movement_entry_unless_own_transfer CHECK ((kind = 'own_transfer') = (ledger_txn_id IS NULL)),
              CONSTRAINT bank_movement_proof_pair CHECK ((proof_ref IS NULL) = (proof_mime IS NULL))
            );
            ALTER SEQUENCE bank_movement_no_seq OWNED BY bank_movement.movement_no;
            CREATE INDEX idx_bank_movement_recorded ON bank_movement(recorded_at, movement_id);
            CREATE INDEX idx_bank_movement_occurred ON bank_movement(occurred_on);
            CREATE TRIGGER trg_bank_movement_immutable BEFORE UPDATE OR DELETE ON bank_movement
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            CREATE TABLE daily_close (
              close_date   DATE PRIMARY KEY,
              bank_balance NUMERIC(18,4) NOT NULL,
              books_bank   NUMERIC(18,4) NOT NULL,
              customer_available NUMERIC(18,4) NOT NULL,
              customer_held      NUMERIC(18,4) NOT NULL,
              customer_liability NUMERIC(18,4) NOT NULL,
              dahab_wallet NUMERIC(18,4) NOT NULL,
              escrow       NUMERIC(18,4) NOT NULL,
              vat_payable  NUMERIC(18,4) NOT NULL,
              movements_in  NUMERIC(18,4) NOT NULL,
              movements_out NUMERIC(18,4) NOT NULL,
              difference   NUMERIC(18,4) NOT NULL,
              explanation  TEXT CHECK (explanation IS NULL OR char_length(explanation) BETWEEN 10 AND 1000),
              is_locked    BOOLEAN NOT NULL DEFAULT FALSE,
              saved_by     UUID NOT NULL REFERENCES staff(staff_id),
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

            CREATE TABLE wallet_adjustment (
              adjustment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              direction    TEXT NOT NULL CHECK (direction IN ('credit','debit')),
              amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
              reason       TEXT NOT NULL CHECK (char_length(reason) BETWEEN 10 AND 1000),
              customer_status TEXT NOT NULL,
              adjusted_by  UUID NOT NULL REFERENCES staff(staff_id),
              ledger_txn_id UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
              adjusted_at  TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
            );
            CREATE INDEX idx_wallet_adjustment_at ON wallet_adjustment(adjusted_at, adjustment_id);
            CREATE INDEX idx_wallet_adjustment_customer ON wallet_adjustment(customer_id, adjusted_at);
            CREATE TRIGGER trg_wallet_adjustment_immutable BEFORE UPDATE OR DELETE ON wallet_adjustment
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            ALTER TABLE wallet_adjustment ENABLE ROW LEVEL SECURITY;
            ALTER TABLE wallet_adjustment FORCE  ROW LEVEL SECURITY;
            CREATE POLICY wallet_adjustment_isolation ON wallet_adjustment FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()));

            -- Each row names its own entry (DH011), checked at commit.
            CREATE OR REPLACE FUNCTION wallet_adjustment_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (
                SELECT 1 FROM ledger_transaction t
                  JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
                  JOIN account a ON a.account_id = p.account_id
                 WHERE t.ledger_txn_id = NEW.ledger_txn_id AND t.event_kind = 'reversal'
                   AND t.reverses_txn_id IS NULL AND t.staff_id = NEW.adjusted_by
                   AND a.customer_id = NEW.customer_id AND a.kind = 'cust_available'
                   AND p.amount = CASE NEW.direction WHEN 'credit' THEN NEW.amount ELSE -NEW.amount END) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'wallet adjustment % has no matching ledger entry', NEW.adjustment_id USING ERRCODE = 'DH011';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_wallet_adjustment_recorded
              AFTER INSERT ON wallet_adjustment
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION wallet_adjustment_recorded();

            CREATE OR REPLACE FUNCTION bank_movement_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF NEW.ledger_txn_id IS NULL THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (
                SELECT 1 FROM ledger_transaction t
                 WHERE t.ledger_txn_id = NEW.ledger_txn_id AND t.event_kind = 'external_bank_movement'
                   AND t.staff_id = NEW.recorded_by
                   AND EXISTS (SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                                WHERE p.ledger_txn_id = t.ledger_txn_id AND a.kind = 'bank' AND p.amount = -NEW.amount)
                   AND EXISTS (SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                                WHERE p.ledger_txn_id = t.ledger_txn_id AND a.kind = 'external_equity' AND p.amount = NEW.amount)) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'bank movement % has no matching ledger entry', NEW.movement_id USING ERRCODE = 'DH011';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_bank_movement_recorded
              AFTER INSERT ON bank_movement
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION bank_movement_recorded();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne('SELECT (EXISTS (SELECT 1 FROM wallet_adjustment) OR EXISTS (SELECT 1 FROM bank_movement)
            OR EXISTS (SELECT 1 FROM daily_close) OR EXISTS (SELECT 1 FROM compensation WHERE dispute_id IS NULL)) AS used')->used;
        if ($used) {
            throw new RuntimeException('Wallet adjustments, bank movements, daily closes or compensation outside a dispute exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS wallet_adjustment;
            DROP TABLE IF EXISTS daily_close;
            DROP TABLE IF EXISTS bank_movement;
            DROP SEQUENCE IF EXISTS bank_movement_no_seq;
            DROP FUNCTION IF EXISTS wallet_adjustment_recorded();
            DROP FUNCTION IF EXISTS bank_movement_recorded();

            DROP INDEX IF EXISTS idx_compensation_paid_at;
            ALTER TABLE compensation
              DROP CONSTRAINT IF EXISTS compensation_party_with_order,
              DROP CONSTRAINT IF EXISTS compensation_dispute_needs_order,
              ALTER COLUMN party SET NOT NULL,
              ALTER COLUMN order_id SET NOT NULL,
              ALTER COLUMN dispute_id SET NOT NULL;

            CREATE OR REPLACE FUNCTION compensation_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
              v_party UUID;
            BEGIN
              SELECT CASE NEW.party WHEN 'buyer' THEN o.buyer_id ELSE o.seller_id END INTO v_party
                FROM "order" o WHERE o.order_id = NEW.order_id;
              IF v_party IS DISTINCT FROM NEW.customer_id THEN
                RAISE EXCEPTION 'compensation % is not paid to the order''s %', NEW.compensation_id, NEW.party USING ERRCODE = 'DH009';
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (
                SELECT 1 FROM ledger_transaction t
                  JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
                  JOIN account a ON a.account_id = p.account_id
                 WHERE t.ledger_txn_id = NEW.ledger_txn_id AND t.event_kind = 'compensation'
                   AND t.order_id = NEW.order_id
                   AND a.customer_id = NEW.customer_id AND a.kind = 'cust_available' AND p.amount = NEW.amount) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'compensation % has no matching ledger entry', NEW.compensation_id USING ERRCODE = 'DH009';
              END IF;
              RETURN NULL;
            END $$;
            SQL);
    }
};
