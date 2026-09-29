<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot of the provider fee on a transfer notice (spec 009, decision
 * 2026-09-30). `topup.notice_fee_percent` keeps the receiving account's
 * provider_fee_percent as it was when the customer filed the notice, so a
 * later change to the account's fee never changes the display-only estimate
 * (`expected_amount`) of a notice that already exists. Credits are untouched:
 * staff still credit what actually arrived.
 *
 * Open notices filed before this column existed take their account's current
 * fee; the column is then frozen with the other identity columns
 * (trg_topup_guard, SQLSTATE DH003).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE topup
              ADD COLUMN notice_fee_percent NUMERIC(5,3) CHECK (notice_fee_percent BETWEEN 0 AND 100),
              ADD CONSTRAINT topup_fee_on_notice CHECK (origin = 'notice' OR notice_fee_percent IS NULL);
        SQL);

        // Backfill open notices. topup has forced RLS: read and write them in the
        // maintenance scope for this transaction only.
        DB::unprepared(<<<'SQL'
            SELECT set_config('app.rls_scope', 'maintenance', true);
            UPDATE topup t SET notice_fee_percent = ra.provider_fee_percent
              FROM receiving_account ra
             WHERE ra.receiving_account_id = t.notice_account_id
               AND t.origin = 'notice' AND t.status IN ('pending', 'on_hold');
        SQL);

        $this->guard(frozenFee: true);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->guard(frozenFee: false);
        DB::unprepared('ALTER TABLE topup DROP CONSTRAINT IF EXISTS topup_fee_on_notice, DROP COLUMN IF EXISTS notice_fee_percent;');
    }

    /** trg_topup_guard's function, with or without the fee snapshot among the identity columns. */
    private function guard(bool $frozenFee): void
    {
        $fee = $frozenFee ? 'OR NEW.notice_fee_percent IS DISTINCT FROM OLD.notice_fee_percent' : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION topup_guard() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
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
                 {$fee} THEN
                RAISE EXCEPTION 'top-up % identity columns cannot change', OLD.topup_id USING ERRCODE = 'DH003';
              END IF;
              RETURN NEW;
            END \$\$;
        SQL);
    }
};
