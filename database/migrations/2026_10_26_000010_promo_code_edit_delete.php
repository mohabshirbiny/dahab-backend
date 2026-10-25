<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Editing and deleting promo codes (owner request 2026-10-26). A code nobody has used yet — no use
 * logged (allowed or blocked), no credit, no buy request, no listing, no advance points at it — may
 * have every term changed or be deleted. Once used, its terms stay as they were used (DH020): only
 * the cap, the end date and the on/off change, and it is never deleted. `promo_code_unused(code)`
 * is the one test, read by the guard and by the API.
 */
return new class extends Migration
{
    private const TERMS = [
        'kind', 'tied_customer_id', 'commission_waived', 'gives_spread', 'audience', 'commission_mode',
        'commission_percent', 'commission_amount_egp', 'side', 'product_type', 'deposit_mode', 'deposit_percent',
        'deposit_amount_egp', 'use_limit', 'first_sale_only', 'pays_advance', 'min_listed_days', 'requires_approval',
        'starts_on',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- True while nothing points at the code. Reads every table across row security (system scope).
            CREATE OR REPLACE FUNCTION promo_code_unused(p_code TEXT) RETURNS BOOLEAN
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_unused BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT NOT EXISTS (SELECT 1 FROM promo_code_use WHERE code = p_code)
                 AND NOT EXISTS (SELECT 1 FROM commission_credit WHERE promo_code = p_code)
                 AND NOT EXISTS (SELECT 1 FROM buy_request WHERE promo_code = p_code)
                 AND NOT EXISTS (SELECT 1 FROM listing_promo WHERE promo_code = p_code)
                 AND NOT EXISTS (SELECT 1 FROM first_sale_advance WHERE promo_code = p_code)
                INTO v_unused;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_unused;
            END $$;
            SQL);

        $changed = implode("\n                 OR ", array_map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", self::TERMS));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION promo_code_guard() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF NOT promo_code_unused(OLD.code) THEN
                  RAISE EXCEPTION 'a promo code that was used is never deleted' USING ERRCODE = 'DH020';
                END IF;
                RETURN OLD;
              END IF;
              IF NEW.code IS DISTINCT FROM OLD.code
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'a promo code keeps its name and its maker' USING ERRCODE = 'DH020';
              END IF;
              IF ({$changed}) AND NOT promo_code_unused(OLD.code) THEN
                RAISE EXCEPTION 'the terms of a promo code that was used never change' USING ERRCODE = 'DH020';
              END IF;
              RETURN NEW;
            END \$\$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $changed = implode("\n                 OR ", array_map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", [...self::TERMS, 'ends_on']));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION promo_code_guard() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a promo code is never deleted' USING ERRCODE = 'DH020';
              END IF;
              IF NEW.code IS DISTINCT FROM OLD.code
                 OR {$changed}
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'only the cap and the on/off of a promo code may change' USING ERRCODE = 'DH020';
              END IF;
              RETURN NEW;
            END \$\$;
            DROP FUNCTION IF EXISTS promo_code_unused(TEXT);
            SQL);
    }
};
