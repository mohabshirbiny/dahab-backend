<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payout accounts and withdrawals — money out (spec 013). Mirrors docs/Database
 * schema: 04_schema_market.sql §12 (`payout_account`, `withdrawal_pause`,
 * `withdrawal` as built, the new `payout_account_change` and
 * `withdrawal_confirmation`), 01_schema_core.sql (`withdrawal_state`,
 * `payout_account_state` with `refused`), 05_schema_security.sql
 * (`withdrawal_transition` + `requested → rejected`, the new
 * `payout_account_transition`, the guards on SQLSTATE DH007 / DH008, forced
 * row-level security of the five tables) and 03_schema_ledger.sql (the
 * `ledger_transaction.withdrawal_id` foreign key, deferred).
 *
 * `on_hold_account_change` and `settled` exist in the enum and the transition
 * table (schema) but are never reached in this feature (spec Clarifications).
 *
 * ROLLBACK: down() refuses once any withdrawal exists: its rows are tied to
 * append-only ledger entries. Otherwise it drops everything in reverse. The
 * enums are dropped with the tables (nothing else uses them).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // migrate:fresh drops tables, not types (the spec 010/011 pattern).
        DB::unprepared('DROP TYPE IF EXISTS withdrawal_state CASCADE');
        DB::unprepared('DROP TYPE IF EXISTS payout_account_state CASCADE');

        DB::unprepared(<<<'SQL'
            -- 01_schema_core.sql: the two state machines (spec 013 adds 'refused').
            CREATE TYPE withdrawal_state AS ENUM (
              'requested', 'under_review', 'on_hold_account_change', 'released', 'settled', 'rejected', 'cancelled'
            );
            CREATE TYPE payout_account_state AS ENUM (
              'pending_review', 'active', 'refused', 'removing', 'removed'
            );

            -- 04 §12: an account money may be paid out to. Only in the customer's own
            -- name (checked by staff against the verified ID). Several per customer,
            -- exactly one in use (spec 013 Clarifications).
            CREATE TABLE payout_account (
              payout_account_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              account_name TEXT NOT NULL CHECK (char_length(account_name) BETWEEN 3 AND 120),
              bank_name    TEXT NOT NULL CHECK (char_length(bank_name) BETWEEN 2 AND 80),
              account_number_or_iban TEXT NOT NULL CHECK (account_number_or_iban ~ '^(EG[0-9]{27}|[0-9]{8,20})$'),
              state        payout_account_state NOT NULL DEFAULT 'pending_review',
              is_in_use    BOOLEAN NOT NULL DEFAULT FALSE,
              name_checked_by UUID REFERENCES staff(staff_id),
              name_checked_at TIMESTAMPTZ,
              activated_at TIMESTAMPTZ,
              refusal_reason TEXT CHECK (refusal_reason IN ('name_mismatch','name_shortened','not_in_customer_name','details_invalid','other')),
              refusal_note   TEXT CHECK (char_length(refusal_note) <= 1000),
              removal_requested_at TIMESTAMPTZ,
              removed_at   TIMESTAMPTZ,
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT payout_in_use_needs_usable CHECK (NOT is_in_use OR state IN ('active','removing')),
              CONSTRAINT payout_refusal_shape CHECK ((state = 'refused') = (refusal_reason IS NOT NULL)),
              CONSTRAINT payout_checked_shape CHECK ((name_checked_by IS NULL) = (name_checked_at IS NULL)),
              CONSTRAINT payout_active_checked CHECK (state NOT IN ('active','removing','refused') OR name_checked_by IS NOT NULL)
            );
            CREATE INDEX idx_payout_customer ON payout_account(customer_id);
            CREATE UNIQUE INDEX one_payout_in_use_per_customer ON payout_account(customer_id) WHERE is_in_use;
            CREATE INDEX idx_payout_review ON payout_account(created_at, payout_account_id) WHERE state = 'pending_review';

            -- 05: the account machine (spec 013, new).
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

            -- A per-customer pause opened when the account in use changes (04 §12).
            CREATE TABLE withdrawal_pause (
              pause_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              opened_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
              pause_until  TIMESTAMPTZ NOT NULL,
              triggered_by_account UUID REFERENCES payout_account(payout_account_id),
              ended_notified_at TIMESTAMPTZ,
              CONSTRAINT pause_window_valid CHECK (pause_until > opened_at)
            );
            CREATE INDEX idx_pause_customer_until ON withdrawal_pause(customer_id, pause_until);
            CREATE INDEX idx_pause_to_announce ON withdrawal_pause(pause_until) WHERE ended_notified_at IS NULL;

            -- The history behind "Recent changes" (spec 013, new; Principle I).
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

            -- 04 §12 + spec 013: one request to take money out, reviewed by a person.
            CREATE TABLE withdrawal (
              withdrawal_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              withdrawal_no BIGINT GENERATED BY DEFAULT AS IDENTITY UNIQUE,   -- shown as WD-{n}
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              payout_account_id UUID NOT NULL REFERENCES payout_account(payout_account_id),
              amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
              state        withdrawal_state NOT NULL DEFAULT 'requested',
              hold_txn_id    UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
              release_txn_id UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
              return_txn_id  UUID UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
              reviewed_by  UUID REFERENCES staff(staff_id),
              review_started_at TIMESTAMPTZ,
              held_at      TIMESTAMPTZ,
              held_by      UUID REFERENCES staff(staff_id),
              hold_reason  TEXT CHECK (hold_reason IN ('name_mismatch','account_changed_recently','identity_pending','money_in_straight_out','other')),
              hold_message TEXT CHECK (char_length(hold_message) BETWEEN 3 AND 500),
              hold_note    TEXT CHECK (char_length(hold_note) BETWEEN 3 AND 1000),
              rejection_reason TEXT CHECK (rejection_reason IN ('account_not_in_name','money_in_straight_out','identity_unconfirmed','customer_request','other')),
              rejection_note   TEXT CHECK (char_length(rejection_note) BETWEEN 3 AND 1000),
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
              -- The hold record survives a reject or cancel (analysis A1); "on hold" is
              -- under_review with held_at set; a held withdrawal is never released.
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

            -- 05: the withdrawal machine (schema rows + requested -> rejected, spec 013).
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

            -- The email second-check (Part 1 §2.4; spec 013, new): single use, tied to
            -- the customer, the amount and the account. Only the token's HMAC is kept.
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

            -- 03: the ledger's business-object FK (declared in Part 2, "added in Part 3").
            -- Deferred: the hold is posted before the withdrawal row that names it.
            ALTER TABLE ledger_transaction
              ADD CONSTRAINT lt_withdrawal_fk FOREIGN KEY (withdrawal_id) REFERENCES withdrawal(withdrawal_id)
              DEFERRABLE INITIALLY DEFERRED;
            CREATE INDEX idx_ledger_txn_withdrawal ON ledger_transaction(withdrawal_id) WHERE withdrawal_id IS NOT NULL;
            SQL);

        DB::unprepared(<<<'SQL'
            -- Guards (05_schema_security.sql, spec 013). Their own SQLSTATEs:
            -- DH007 -> 409 illegal_withdrawal_transition, DH008 -> 409 illegal_payout_account_transition.
            CREATE OR REPLACE FUNCTION assert_withdrawal_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'withdrawals are never deleted' USING ERRCODE = 'DH007';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state
                 AND NOT EXISTS (SELECT 1 FROM withdrawal_transition t WHERE t.from_state = OLD.state AND t.to_state = NEW.state) THEN
                RAISE EXCEPTION 'illegal withdrawal transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH007';
              END IF;
              IF NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.payout_account_id IS DISTINCT FROM OLD.payout_account_id
                 OR NEW.amount IS DISTINCT FROM OLD.amount OR NEW.withdrawal_no IS DISTINCT FROM OLD.withdrawal_no
                 OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                RAISE EXCEPTION 'withdrawal % identity columns cannot change', OLD.withdrawal_id USING ERRCODE = 'DH007';
              END IF;
              IF NEW.hold_txn_id IS DISTINCT FROM OLD.hold_txn_id
                 OR (OLD.release_txn_id IS NOT NULL AND NEW.release_txn_id IS DISTINCT FROM OLD.release_txn_id)
                 OR (OLD.return_txn_id IS NOT NULL AND NEW.return_txn_id IS DISTINCT FROM OLD.return_txn_id) THEN
                RAISE EXCEPTION 'withdrawal % money links cannot change', OLD.withdrawal_id USING ERRCODE = 'DH007';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_withdrawal_guard BEFORE UPDATE OR DELETE ON withdrawal
              FOR EACH ROW EXECUTE FUNCTION assert_withdrawal_transition();

            CREATE OR REPLACE FUNCTION assert_payout_account_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'payout accounts are never deleted' USING ERRCODE = 'DH008';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state
                 AND NOT EXISTS (SELECT 1 FROM payout_account_transition t WHERE t.from_state = OLD.state AND t.to_state = NEW.state) THEN
                RAISE EXCEPTION 'illegal payout account transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH008';
              END IF;
              IF NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.account_name IS DISTINCT FROM OLD.account_name
                 OR NEW.bank_name IS DISTINCT FROM OLD.bank_name OR NEW.account_number_or_iban IS DISTINCT FROM OLD.account_number_or_iban
                 OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'payout account % details cannot change; add a new account', OLD.payout_account_id USING ERRCODE = 'DH008';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_payout_account_guard BEFORE UPDATE OR DELETE ON payout_account
              FOR EACH ROW EXECUTE FUNCTION assert_payout_account_transition();

            -- Each withdrawal state has its money (spec 013 FR-016): checked at commit,
            -- reading the ledger under its own scope.
            CREATE OR REPLACE FUNCTION withdrawal_money_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_need UUID;
              v_ok BOOLEAN;
            BEGIN
              v_need := CASE
                WHEN NEW.state IN ('requested','under_review','on_hold_account_change') THEN NEW.hold_txn_id
                WHEN NEW.state IN ('released','settled') THEN NEW.release_txn_id
                WHEN NEW.state IN ('rejected','cancelled') THEN NEW.return_txn_id
                END;
              IF v_need IS NULL OR (NEW.state IN ('rejected','cancelled') AND NEW.release_txn_id IS NOT NULL)
                 OR (NEW.state IN ('released','settled') AND NEW.return_txn_id IS NOT NULL) THEN
                RAISE EXCEPTION 'withdrawal % is % without its money', NEW.withdrawal_id, NEW.state USING ERRCODE = 'DH007';
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT bool_and(t.event_kind = 'withdrawal' AND t.withdrawal_id = NEW.withdrawal_id) INTO v_ok
                FROM ledger_transaction t
               WHERE t.ledger_txn_id IN (NEW.hold_txn_id, NEW.release_txn_id, NEW.return_txn_id);
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT COALESCE(v_ok, false) THEN
                RAISE EXCEPTION 'withdrawal % names a ledger entry that is not its own', NEW.withdrawal_id USING ERRCODE = 'DH007';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_withdrawal_money
              AFTER INSERT OR UPDATE ON withdrawal
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION withdrawal_money_recorded();
            SQL);

        DB::unprepared(<<<'SQL'
            -- Row-level security (05_schema_security.sql, spec 013 FR-019): the owner is
            -- the customer; staff, the job and the public confirm act elevated.
            ALTER TABLE payout_account          ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payout_account          FORCE  ROW LEVEL SECURITY;
            ALTER TABLE payout_account_change   ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payout_account_change   FORCE  ROW LEVEL SECURITY;
            ALTER TABLE withdrawal_pause        ENABLE ROW LEVEL SECURITY;
            ALTER TABLE withdrawal_pause        FORCE  ROW LEVEL SECURITY;
            ALTER TABLE withdrawal              ENABLE ROW LEVEL SECURITY;
            ALTER TABLE withdrawal              FORCE  ROW LEVEL SECURITY;
            ALTER TABLE withdrawal_confirmation ENABLE ROW LEVEL SECURITY;
            ALTER TABLE withdrawal_confirmation FORCE  ROW LEVEL SECURITY;

            CREATE POLICY payout_account_isolation ON payout_account FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY payout_account_change_isolation ON payout_account_change FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (customer_id = (SELECT dahab_current_customer_id())
                          AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL));
            CREATE POLICY withdrawal_pause_isolation ON withdrawal_pause FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY withdrawal_isolation ON withdrawal FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY withdrawal_confirmation_isolation ON withdrawal_confirmation FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));

            -- The payout-account declaration (02_schema_identity.sql: acceptance context
            -- 'payout_account'; the text of the terms draft).
            INSERT INTO legal_document (code, version, body_en, body_ar, is_material, published_by)
            SELECT 'payout_account_declaration', 1,
                   'I confirm this account is mine, that the details are correct, and that the name matches my identity document.',
                   'أقر إن الحساب ده بتاعي، وإن البيانات صحيحة، وإن الاسم مطابق لمستند هويتي.',
                   FALSE, staff_id
            FROM staff WHERE is_system = TRUE;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::selectOne('SELECT EXISTS (SELECT 1 FROM withdrawal) AS used')->used) {
            throw new RuntimeException('Withdrawals exist and are tied to append-only ledger entries: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DELETE FROM legal_document WHERE code = 'payout_account_declaration'
              AND NOT EXISTS (SELECT 1 FROM agreement_acceptance a WHERE a.legal_doc_id = legal_document.legal_doc_id);
            ALTER TABLE ledger_transaction DROP CONSTRAINT IF EXISTS lt_withdrawal_fk;
            DROP INDEX IF EXISTS idx_ledger_txn_withdrawal;
            DROP TABLE IF EXISTS withdrawal_confirmation;
            DROP TABLE IF EXISTS withdrawal_transition;
            DROP TABLE IF EXISTS withdrawal;
            DROP TABLE IF EXISTS payout_account_change;
            DROP TABLE IF EXISTS withdrawal_pause;
            DROP TABLE IF EXISTS payout_account_transition;
            DROP TABLE IF EXISTS payout_account;
            DROP FUNCTION IF EXISTS withdrawal_money_recorded();
            DROP FUNCTION IF EXISTS assert_withdrawal_transition();
            DROP FUNCTION IF EXISTS assert_payout_account_transition();
            DROP TYPE IF EXISTS withdrawal_state;
            DROP TYPE IF EXISTS payout_account_state;
            SQL);
    }
};
