<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tax invoices and credit notes (spec 016). Mirrors docs/Database schema:
 * 04_schema_market.sql `tax_invoice` as built (number, VAT rate, the lines
 * snapshot, Dahab's details and the party copied once, the document) and the
 * new `credit_note`; 01_schema_core.sql `ledger_event_kind` + `credit_note`.
 * Guards on SQLSTATE DH012: both tables append-only except the one-time
 * snapshot and document columns; every invoice equal to its order's
 * settlement (deferred); a credit note only on a seller invoice and never
 * above what is left of it (row lock); every credit note tied to its exact
 * ledger entry (deferred). Forced row-level security on both.
 *
 * The new enum value is used only inside trigger bodies: PostgreSQL forbids
 * using it in the transaction that adds it.
 *
 * ROLLBACK: down() refuses once any invoice or credit note exists. The enum
 * value cannot be dropped and stays (unused).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("ALTER TYPE ledger_event_kind ADD VALUE IF NOT EXISTS 'credit_note'");

        DB::unprepared(<<<'SQL'
            CREATE TABLE tax_invoice (
              invoice_id    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              invoice_no    TEXT NOT NULL UNIQUE,
              order_id      UUID NOT NULL REFERENCES "order"(order_id),
              party_role    TEXT NOT NULL CHECK (party_role IN ('seller','buyer')),
              customer_id   UUID NOT NULL REFERENCES customer(customer_id),
              net_amount    NUMERIC(18,4) NOT NULL CHECK (net_amount > 0),
              vat_amount    NUMERIC(18,4) NOT NULL CHECK (vat_amount >= 0),
              gross_amount  NUMERIC(18,4) NOT NULL,
              vat_rate      NUMERIC(6,3) NOT NULL CHECK (vat_rate >= 0),
              lines         JSONB NOT NULL,
              issuer        JSONB,
              party         JSONB,
              eta_reference TEXT,
              issued_at     TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              storage_ref   TEXT,
              document_at   TIMESTAMPTZ,
              CONSTRAINT tax_invoice_one_per_party UNIQUE (order_id, party_role),
              CONSTRAINT invoice_gross CHECK (gross_amount = net_amount + vat_amount),
              CONSTRAINT invoice_vat_on_seller_only CHECK (party_role = 'seller' OR (vat_amount = 0 AND vat_rate = 0)),
              CONSTRAINT invoice_no_matches_party CHECK (right(invoice_no, 2) = CASE party_role WHEN 'seller' THEN '-S' ELSE '-B' END),
              CONSTRAINT invoice_storage_pair CHECK ((storage_ref IS NULL) = (document_at IS NULL))
            );
            CREATE INDEX idx_tax_invoice_issued ON tax_invoice(issued_at DESC, invoice_id);
            CREATE INDEX idx_tax_invoice_customer ON tax_invoice(customer_id, issued_at DESC);
            CREATE INDEX idx_tax_invoice_pending ON tax_invoice(issued_at) WHERE storage_ref IS NULL;

            CREATE SEQUENCE credit_note_no_seq;
            CREATE TABLE credit_note (
              credit_note_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              credit_note_no TEXT NOT NULL UNIQUE,
              invoice_id     UUID NOT NULL REFERENCES tax_invoice(invoice_id),
              customer_id    UUID NOT NULL REFERENCES customer(customer_id),
              net_amount     NUMERIC(18,4) NOT NULL CHECK (net_amount > 0),
              vat_amount     NUMERIC(18,4) NOT NULL CHECK (vat_amount >= 0),
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
            ALTER SEQUENCE credit_note_no_seq OWNED BY credit_note.credit_note_no;
            CREATE INDEX idx_credit_note_issued ON credit_note(issued_at DESC, credit_note_id);
            CREATE INDEX idx_credit_note_invoice ON credit_note(invoice_id);
            CREATE INDEX idx_credit_note_customer ON credit_note(customer_id, issued_at DESC);
            CREATE INDEX idx_credit_note_pending ON credit_note(issued_at) WHERE storage_ref IS NULL;

            -- Append-only, except the snapshot and document columns, each set once (NULL → value).
            CREATE OR REPLACE FUNCTION tax_document_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              k TEXT;
              v_old JSONB;
              v_new JSONB;
              once TEXT[] := ARRAY['issuer','party','storage_ref','document_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'DH012';
              END IF;
              v_old := to_jsonb(OLD);
              v_new := to_jsonb(NEW);
              IF (v_new - once) IS DISTINCT FROM (v_old - once) THEN
                RAISE EXCEPTION 'an issued % never changes', TG_TABLE_NAME USING ERRCODE = 'DH012';
              END IF;
              FOREACH k IN ARRAY once LOOP
                IF jsonb_exists(v_old, k) AND jsonb_typeof(v_old -> k) <> 'null' AND (v_new -> k) IS DISTINCT FROM (v_old -> k) THEN
                  RAISE EXCEPTION '%.% is set once', TG_TABLE_NAME, k USING ERRCODE = 'DH012';
                END IF;
              END LOOP;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_tax_invoice_guard BEFORE UPDATE OR DELETE ON tax_invoice
              FOR EACH ROW EXECUTE FUNCTION tax_document_guard();
            CREATE TRIGGER trg_credit_note_guard BEFORE UPDATE OR DELETE ON credit_note
              FOR EACH ROW EXECUTE FUNCTION tax_document_guard();

            -- Every invoice equals the settlement of its order (checked at commit).
            CREATE OR REPLACE FUNCTION tax_invoice_reconciled() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              o RECORD;
              v_ok BOOLEAN;
            BEGIN
              SELECT order_ref, seller_id, buyer_id, settlement_txn_id, commission_amount, vat_amount, final_buyer_total
                INTO o FROM "order" WHERE order_id = NEW.order_id;
              IF NOT FOUND OR o.settlement_txn_id IS NULL
                 OR NEW.invoice_no <> o.order_ref || (CASE NEW.party_role WHEN 'seller' THEN '-S' ELSE '-B' END)
                 OR NEW.customer_id <> (CASE NEW.party_role WHEN 'seller' THEN o.seller_id ELSE o.buyer_id END)
                 OR (NEW.party_role = 'seller' AND (NEW.net_amount <> o.commission_amount OR NEW.vat_amount <> o.vat_amount))
                 OR (NEW.party_role = 'buyer' AND NEW.net_amount <> o.final_buyer_total) THEN
                RAISE EXCEPTION 'invoice % does not match the settlement of its order', NEW.invoice_no USING ERRCODE = 'DH012';
              END IF;
              IF NEW.party_role = 'seller' THEN
                PERFORM set_config('app.rls_scope', 'ledger', true);
                SELECT EXISTS (
                         SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                          WHERE p.ledger_txn_id = o.settlement_txn_id AND a.kind = 'dahab_commission' AND p.amount = NEW.net_amount)
                   AND (NEW.vat_amount = 0 OR EXISTS (
                         SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                          WHERE p.ledger_txn_id = o.settlement_txn_id AND a.kind = 'vat_payable' AND p.amount = NEW.vat_amount))
                  INTO v_ok;
                PERFORM set_config('app.rls_scope', prev_scope, true);
                IF NOT v_ok THEN
                  RAISE EXCEPTION 'invoice % does not match the ledger', NEW.invoice_no USING ERRCODE = 'DH012';
                END IF;
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_tax_invoice_reconciled
              AFTER INSERT ON tax_invoice
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION tax_invoice_reconciled();

            -- A credit note only on a seller invoice, never above what is left (the invoice row locked).
            CREATE OR REPLACE FUNCTION credit_note_cap() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              inv RECORD;
              v_credited NUMERIC(18,4);
            BEGIN
              SELECT party_role, customer_id, gross_amount INTO inv
                FROM tax_invoice WHERE invoice_id = NEW.invoice_id FOR UPDATE;
              IF NOT FOUND OR inv.party_role <> 'seller' OR inv.customer_id <> NEW.customer_id THEN
                RAISE EXCEPTION 'invoice_not_creditable: invoice % cannot be credited', NEW.invoice_id USING ERRCODE = 'DH012';
              END IF;
              SELECT COALESCE(SUM(gross_amount), 0) INTO v_credited FROM credit_note WHERE invoice_id = NEW.invoice_id;
              IF v_credited + NEW.gross_amount > inv.gross_amount THEN
                RAISE EXCEPTION 'credit_exceeds_invoice: % left on invoice %', inv.gross_amount - v_credited, NEW.invoice_id
                  USING ERRCODE = 'DH012';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_credit_note_cap BEFORE INSERT ON credit_note
              FOR EACH ROW EXECUTE FUNCTION credit_note_cap();

            -- Each credit note names its exact refund entry (checked at commit).
            CREATE OR REPLACE FUNCTION credit_note_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_order UUID;
              v_ok BOOLEAN;
            BEGIN
              SELECT order_id INTO v_order FROM tax_invoice WHERE invoice_id = NEW.invoice_id;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (
                       SELECT 1 FROM ledger_transaction t
                        WHERE t.ledger_txn_id = NEW.ledger_txn_id AND t.event_kind = 'credit_note'
                          AND t.staff_id = NEW.issued_by AND t.order_id = v_order)
                 AND (SELECT count(*) FROM ledger_posting p WHERE p.ledger_txn_id = NEW.ledger_txn_id)
                     = (CASE WHEN NEW.vat_amount = 0 THEN 2 ELSE 3 END)
                 AND EXISTS (SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                              WHERE p.ledger_txn_id = NEW.ledger_txn_id AND a.kind = 'dahab_commission' AND p.amount = -NEW.net_amount)
                 AND (NEW.vat_amount = 0 OR EXISTS (SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                              WHERE p.ledger_txn_id = NEW.ledger_txn_id AND a.kind = 'vat_payable' AND p.amount = -NEW.vat_amount))
                 AND EXISTS (SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                              WHERE p.ledger_txn_id = NEW.ledger_txn_id AND a.kind = 'cust_available'
                                AND a.customer_id = NEW.customer_id AND p.amount = NEW.gross_amount)
                INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'credit note % has no matching ledger entry', NEW.credit_note_no USING ERRCODE = 'DH012';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_credit_note_recorded
              AFTER INSERT ON credit_note
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION credit_note_recorded();

            -- Customers read their own; invoices are written only by the payment of the buyer
            -- (the 'order' scope) or an elevated scope; credit notes only by staff.
            ALTER TABLE tax_invoice ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tax_invoice FORCE  ROW LEVEL SECURITY;
            CREATE POLICY tax_invoice_isolation ON tax_invoice FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                (SELECT dahab_rls_scope()) = 'order'
                AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = tax_invoice.order_id
                              AND o.buyer_id = (SELECT dahab_current_customer_id()))));

            ALTER TABLE credit_note ENABLE ROW LEVEL SECURITY;
            ALTER TABLE credit_note FORCE  ROW LEVEL SECURITY;
            CREATE POLICY credit_note_isolation ON credit_note FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne('SELECT (EXISTS (SELECT 1 FROM tax_invoice) OR EXISTS (SELECT 1 FROM credit_note)) AS used')->used;
        if ($used) {
            throw new RuntimeException('Tax invoices or credit notes exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS credit_note;
            DROP TABLE IF EXISTS tax_invoice;
            DROP SEQUENCE IF EXISTS credit_note_no_seq;
            DROP FUNCTION IF EXISTS credit_note_recorded();
            DROP FUNCTION IF EXISTS credit_note_cap();
            DROP FUNCTION IF EXISTS tax_invoice_reconciled();
            DROP FUNCTION IF EXISTS tax_document_guard();
            SQL);
    }
};
