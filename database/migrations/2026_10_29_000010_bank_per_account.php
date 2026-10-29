<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bank of each money movement (owner notes of 2026-10-29,
 * `docs/features/bank-per-account.md`): Dahab's accounts are the receiving
 * accounts (spec 009). A bank movement names its account (an own-account
 * transfer names the account it left — none for cash not yet assigned to an
 * account — and the one it reached); a released withdrawal names the account
 * it was paid from (fixed once set, DH007); the daily close keeps one line per
 * account (the typed statement balance, the books, the difference) plus the
 * books not assigned to any account, and the lines of a locked day never change.
 *
 * Rows recorded before this change name no account: their money is "not
 * assigned" until an own-account transfer moves it to an account.
 *
 * ROLLBACK: down() refuses once a close line exists or any movement or
 * withdrawal names an account (that would lose which bank the money used).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_movement
              ADD COLUMN receiving_account_id    BIGINT REFERENCES receiving_account(receiving_account_id),
              ADD COLUMN to_receiving_account_id BIGINT REFERENCES receiving_account(receiving_account_id),
              ADD CONSTRAINT bank_movement_to_only_own_transfer CHECK (to_receiving_account_id IS NULL OR kind = 'own_transfer'),
              ADD CONSTRAINT bank_movement_transfer_two_accounts
                CHECK (to_receiving_account_id IS NULL OR to_receiving_account_id IS DISTINCT FROM receiving_account_id);
            CREATE INDEX idx_bank_movement_account ON bank_movement(receiving_account_id);

            ALTER TABLE withdrawal
              ADD COLUMN paid_from_account_id BIGINT REFERENCES receiving_account(receiving_account_id);

            CREATE OR REPLACE FUNCTION withdrawal_paid_from_fixed() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.paid_from_account_id IS NOT NULL AND NEW.paid_from_account_id IS DISTINCT FROM OLD.paid_from_account_id THEN
                RAISE EXCEPTION 'withdrawal % paid-from account cannot change', OLD.withdrawal_id USING ERRCODE = 'DH007';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_withdrawal_paid_from_fixed BEFORE UPDATE OF paid_from_account_id ON withdrawal
              FOR EACH ROW EXECUTE FUNCTION withdrawal_paid_from_fixed();

            CREATE TABLE daily_close_bank (
              close_date           DATE NOT NULL REFERENCES daily_close(close_date),
              receiving_account_id BIGINT REFERENCES receiving_account(receiving_account_id),
              statement_balance    NUMERIC(18,4),
              books_balance        NUMERIC(18,4) NOT NULL,
              difference           NUMERIC(18,4) NOT NULL,
              CONSTRAINT daily_close_bank_one UNIQUE NULLS NOT DISTINCT (close_date, receiving_account_id),
              -- The line with no account is the books not assigned to any account: nothing to type for it.
              CONSTRAINT daily_close_bank_typed CHECK ((receiving_account_id IS NULL) = (statement_balance IS NULL)),
              CONSTRAINT daily_close_bank_difference CHECK (difference = COALESCE(statement_balance, 0) - books_balance)
            );

            CREATE OR REPLACE FUNCTION daily_close_bank_locked() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF EXISTS (SELECT 1 FROM daily_close c
                          WHERE c.close_date = (CASE WHEN TG_OP = 'INSERT' THEN NEW.close_date ELSE OLD.close_date END)
                            AND c.is_locked) THEN
                RAISE EXCEPTION 'append-only table: % on % of a locked day is not permitted', TG_OP, TG_TABLE_NAME;
              END IF;
              RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$;
            CREATE TRIGGER trg_daily_close_bank_locked BEFORE INSERT OR UPDATE OR DELETE ON daily_close_bank
              FOR EACH ROW EXECUTE FUNCTION daily_close_bank_locked();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne('
            SELECT EXISTS (SELECT 1 FROM daily_close_bank)
                OR EXISTS (SELECT 1 FROM bank_movement WHERE receiving_account_id IS NOT NULL OR to_receiving_account_id IS NOT NULL)
                OR EXISTS (SELECT 1 FROM withdrawal WHERE paid_from_account_id IS NOT NULL) AS used
        ')->used;
        if ($used) {
            throw new RuntimeException('Money already names its bank; rolling back would lose it.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS daily_close_bank;
            DROP FUNCTION IF EXISTS daily_close_bank_locked();
            DROP TRIGGER IF EXISTS trg_withdrawal_paid_from_fixed ON withdrawal;
            DROP FUNCTION IF EXISTS withdrawal_paid_from_fixed();
            ALTER TABLE withdrawal DROP COLUMN IF EXISTS paid_from_account_id;
            DROP INDEX IF EXISTS idx_bank_movement_account;
            ALTER TABLE bank_movement
              DROP CONSTRAINT IF EXISTS bank_movement_transfer_two_accounts,
              DROP CONSTRAINT IF EXISTS bank_movement_to_only_own_transfer,
              DROP COLUMN IF EXISTS to_receiving_account_id,
              DROP COLUMN IF EXISTS receiving_account_id;
            SQL);
    }
};
