<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner notes of 2026-10-25 (docs/features/offer-details-and-invoices.md):
 *
 *  - a branch's contact phone and map link, shown to customers with its hours;
 *  - a stone piece's carat, clarity and cut, as the seller states them (stones only);
 *  - the ordinary commission VAT included, stored at settlement, so the seller's saving is shown whole;
 *  - every settled sale has its seller invoice, a sale of no commission one of 0 (DH012);
 *  - the buyer's invoice is the total paid: the spread a code handed back is out of it (DH012).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE branch
              ADD COLUMN IF NOT EXISTS contact_phone TEXT CHECK (contact_phone IS NULL OR char_length(contact_phone) BETWEEN 1 AND 30),
              ADD COLUMN IF NOT EXISTS map_url       TEXT CHECK (map_url IS NULL OR char_length(map_url) BETWEEN 1 AND 500);

            ALTER TABLE listing
              ADD COLUMN IF NOT EXISTS stone_carat   NUMERIC(6,2) CHECK (stone_carat IS NULL OR stone_carat > 0),
              ADD COLUMN IF NOT EXISTS stone_clarity TEXT CHECK (stone_clarity IN ('vvs', 'vs', 'si', 'i')),
              ADD COLUMN IF NOT EXISTS stone_cut     TEXT CHECK (stone_cut IN ('excellent', 'very_good', 'good'));
            ALTER TABLE listing DROP CONSTRAINT IF EXISTS listing_stone_details_shape;
            ALTER TABLE listing ADD CONSTRAINT listing_stone_details_shape CHECK (
              category <> 'gold' OR (stone_carat IS NULL AND stone_clarity IS NULL AND stone_cut IS NULL));

            ALTER TABLE "order"
              ADD COLUMN IF NOT EXISTS commission_ordinary_total NUMERIC(18,4)
                CHECK (commission_ordinary_total IS NULL OR commission_ordinary_total >= 0);

            -- A seller invoice may be 0 (a sale of no commission); a buyer invoice never.
            ALTER TABLE tax_invoice DROP CONSTRAINT IF EXISTS tax_invoice_net_amount_check;
            ALTER TABLE tax_invoice DROP CONSTRAINT IF EXISTS tax_invoice_net_positive;
            ALTER TABLE tax_invoice ADD CONSTRAINT tax_invoice_net_positive
              CHECK (net_amount > 0 OR (net_amount = 0 AND party_role = 'seller'));
            SQL);

        DB::unprepared(self::reconciled(<<<'SQL'
                 OR (NEW.party_role = 'buyer' AND NEW.net_amount <> o.final_buyer_total - COALESCE(o.spread_given_up, 0)) THEN
            SQL, <<<'SQL'
                SELECT (NEW.net_amount = 0 OR EXISTS (
            SQL));

        DB::unprepared(<<<'SQL'
            -- Every settled sale has its seller invoice, of 0 when nothing was charged (checked at commit).
            CREATE OR REPLACE FUNCTION tax_invoice_waived_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_has BOOLEAN;
            BEGIN
              IF TG_TABLE_NAME = 'tax_invoice' THEN
                RETURN NULL;
              END IF;
              IF NEW.settlement_txn_id IS NOT NULL THEN
                PERFORM set_config('app.rls_scope', 'system', true);
                SELECT EXISTS (SELECT 1 FROM tax_invoice WHERE order_id = NEW.order_id AND party_role = 'seller') INTO v_has;
                PERFORM set_config('app.rls_scope', prev_scope, true);
                IF NOT v_has THEN
                  RAISE EXCEPTION 'order % was settled but has no seller invoice', NEW.order_id USING ERRCODE = 'DH012';
                END IF;
              END IF;
              RETURN NULL;
            END $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(self::reconciled(<<<'SQL'
                 OR (NEW.party_role = 'buyer' AND NEW.net_amount <> o.final_buyer_total) THEN
            SQL, <<<'SQL'
                SELECT (EXISTS (
            SQL));

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tax_invoice_waived_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_commission NUMERIC;
              v_order UUID;
              v_has BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              IF TG_TABLE_NAME = 'tax_invoice' THEN
                v_order := NEW.order_id;
                SELECT commission_amount INTO v_commission FROM "order" WHERE order_id = v_order;
                IF NEW.party_role = 'seller' AND COALESCE(v_commission, 0) = 0 THEN
                  PERFORM set_config('app.rls_scope', prev_scope, true);
                  RAISE EXCEPTION 'a sale of no commission has no seller invoice (order %)', v_order USING ERRCODE = 'DH012';
                END IF;
              ELSE
                v_order := NEW.order_id;
                IF NEW.settlement_txn_id IS NOT NULL AND COALESCE(NEW.commission_amount, 0) > 0 THEN
                  SELECT EXISTS (SELECT 1 FROM tax_invoice WHERE order_id = v_order AND party_role = 'seller') INTO v_has;
                  IF NOT v_has THEN
                    PERFORM set_config('app.rls_scope', prev_scope, true);
                    RAISE EXCEPTION 'order % was settled with a commission but has no seller invoice', v_order USING ERRCODE = 'DH012';
                  END IF;
                END IF;
              END IF;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN NULL;
            END $$;

            ALTER TABLE tax_invoice DROP CONSTRAINT IF EXISTS tax_invoice_net_positive;
            ALTER TABLE tax_invoice ADD CONSTRAINT tax_invoice_net_amount_check CHECK (net_amount > 0);
            ALTER TABLE "order" DROP COLUMN IF EXISTS commission_ordinary_total;
            ALTER TABLE listing DROP CONSTRAINT IF EXISTS listing_stone_details_shape;
            ALTER TABLE listing DROP COLUMN IF EXISTS stone_cut, DROP COLUMN IF EXISTS stone_clarity, DROP COLUMN IF EXISTS stone_carat;
            ALTER TABLE branch DROP COLUMN IF EXISTS map_url, DROP COLUMN IF EXISTS contact_phone;
            SQL);
    }

    /** `tax_invoice_reconciled()` with its buyer-total line and the start of its commission-posting check. */
    private static function reconciled(string $buyerLine, string $commissionStart): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION tax_invoice_reconciled() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              o RECORD;
              v_ok BOOLEAN;
            BEGIN
              SELECT order_ref, seller_id, buyer_id, settlement_txn_id, commission_amount, vat_amount, final_buyer_total, spread_given_up
                INTO o FROM "order" WHERE order_id = NEW.order_id;
              IF NOT FOUND OR o.settlement_txn_id IS NULL
                 OR NEW.invoice_no <> o.order_ref || (CASE NEW.party_role WHEN 'seller' THEN '-S' ELSE '-B' END)
                 OR NEW.customer_id <> (CASE NEW.party_role WHEN 'seller' THEN o.seller_id ELSE o.buyer_id END)
                 OR (NEW.party_role = 'seller' AND (NEW.net_amount <> o.commission_amount OR NEW.vat_amount <> o.vat_amount))
            {$buyerLine}
                RAISE EXCEPTION 'invoice % does not match the settlement of its order', NEW.invoice_no USING ERRCODE = 'DH012';
              END IF;
              IF NEW.party_role = 'seller' THEN
                PERFORM set_config('app.rls_scope', 'ledger', true);
            {$commissionStart}
                         SELECT 1 FROM ledger_posting p JOIN account a ON a.account_id = p.account_id
                          WHERE p.ledger_txn_id = o.settlement_txn_id AND a.kind = 'dahab_commission' AND p.amount = NEW.net_amount))
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
            END \$\$;
            SQL;
    }
};
