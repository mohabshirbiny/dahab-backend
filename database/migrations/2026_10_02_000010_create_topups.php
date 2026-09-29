<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wallet top-ups (spec 009). Mirrors the "TOP-UPS" section of
 * docs/Database schema/03_schema_ledger.sql (receiving accounts, transfer
 * notices and hand credits, their CHECKs and guard triggers) and the
 * top-up block of 05_schema_security.sql (forced row-level security).
 *
 * A credit posts through the money service; topup.ledger_txn_id is UNIQUE,
 * so a notice is credited at most once. Final states and identity columns
 * are frozen by trg_topup_guard (SQLSTATE DH003).
 *
 * Rollback drops the top-up records. The ledger entries that credits
 * produced stay (the ledger is append-only), so a rollback on a database
 * with credited top-ups loses only the notice metadata, never money.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // migrate:fresh drops tables but not types (same guard as the ledger migration).
        DB::unprepared(<<<'SQL'
            DROP TYPE IF EXISTS topup_reject_reason CASCADE;
            DROP TYPE IF EXISTS topup_status CASCADE;
            DROP TYPE IF EXISTS topup_origin CASCADE;
            DROP TYPE IF EXISTS topup_method CASCADE;
        SQL);

        DB::unprepared(<<<'SQL'
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
              CONSTRAINT topup_receipt_pair CHECK ((receipt_ref IS NULL) = (receipt_mime IS NULL))
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
                 OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.topup_no IS DISTINCT FROM OLD.topup_no THEN
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
        SQL);

        DB::unprepared(<<<'SQL'
            -- Top-ups (added by spec 009) ---------------------------------------------
            -- A customer sees and changes only their own notices (submit, cancel);
            -- staff act in the elevated 'staff' scope. receiving_account is reference
            -- data (no customer column) and has no RLS.
            ALTER TABLE topup              ENABLE ROW LEVEL SECURITY;
            ALTER TABLE topup              FORCE  ROW LEVEL SECURITY;
            CREATE POLICY topup_isolation ON topup FOR ALL
              USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
              WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS topup_isolation ON topup;
            DROP TABLE IF EXISTS topup;
            DROP FUNCTION IF EXISTS topup_guard();
            DROP TABLE IF EXISTS receiving_account;
            DROP FUNCTION IF EXISTS receiving_account_no_delete();
            DROP TYPE IF EXISTS topup_reject_reason;
            DROP TYPE IF EXISTS topup_status;
            DROP TYPE IF EXISTS topup_origin;
            DROP TYPE IF EXISTS topup_method;
        SQL);
    }
};
