<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A buyer code that takes a percentage or an amount off the price (owner request 2026-10-26,
 * docs/features/buyer-price-discount.md). Staff choose what pays for it: Dahab's commission or, on
 * gold, the spread. The discount never goes above what pays for it (the ordinary commission net of
 * VAT, or the positive spread), so Dahab never pays out of its own pocket.
 *
 *  - promo_code: `price_mode` (none|percent|amount), `price_percent`, `price_amount_egp`,
 *    `discount_funded_by` (commission|spread). A buyer code does exactly one thing: the seller's
 *    price (gold), a lower deposit (stones) or a price discount (any product).
 *  - buy_request: the code's price terms, snapshotted (the settlement cannot read promo_code).
 *  - "order": `price_discount_egp` and `price_discount_funded_by`, stored at settlement.
 *  - DH020 freezes the new terms of a used code; DH012 ties the buyer's invoice to the total paid.
 */
return new class extends Migration
{
    private const TERMS = [
        'kind', 'tied_customer_id', 'commission_waived', 'gives_spread', 'audience', 'commission_mode',
        'commission_percent', 'commission_amount_egp', 'side', 'product_type', 'deposit_mode', 'deposit_percent',
        'deposit_amount_egp', 'use_limit', 'first_sale_only', 'pays_advance', 'min_listed_days', 'requires_approval',
        'starts_on',
    ];

    private const PRICE_TERMS = ['price_mode', 'price_percent', 'price_amount_egp', 'discount_funded_by'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE promo_code
              ADD COLUMN IF NOT EXISTS price_mode         TEXT NOT NULL DEFAULT 'none',
              ADD COLUMN IF NOT EXISTS price_percent      NUMERIC(7,4),
              ADD COLUMN IF NOT EXISTS price_amount_egp   NUMERIC(18,4),
              ADD COLUMN IF NOT EXISTS discount_funded_by TEXT;
            ALTER TABLE promo_code DROP CONSTRAINT IF EXISTS promo_code_price_shape;
            ALTER TABLE promo_code ADD CONSTRAINT promo_code_price_shape CHECK (
              price_mode IN ('none','percent','amount')
              AND (price_mode = 'percent') = (price_percent IS NOT NULL)
              AND (price_mode = 'amount') = (price_amount_egp IS NOT NULL)
              AND (price_percent IS NULL OR (price_percent > 0 AND price_percent < 100))
              AND (price_amount_egp IS NULL OR price_amount_egp > 0)
              AND (price_mode = 'none') = (discount_funded_by IS NULL)
              AND (discount_funded_by IS NULL OR discount_funded_by IN ('commission','spread'))
              AND (discount_funded_by IS DISTINCT FROM 'spread' OR product_type = 'gold'));

            -- A buyer code does one thing: the seller's price (gold), a lower deposit (stones) or a price discount.
            ALTER TABLE promo_code DROP CONSTRAINT promo_code_kind_terms;
            ALTER TABLE promo_code ADD CONSTRAINT promo_code_kind_terms CHECK (
              (kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread))
              AND (kind IN ('market_maker','buyer') OR NOT gives_spread)
              AND (kind <> 'discount' OR commission_mode <> 'none')
              AND (kind <> 'buyer' OR (commission_mode = 'none'
                   AND (gives_spread::int + (deposit_mode <> 'none')::int + (price_mode <> 'none')::int) = 1
                   AND (NOT gives_spread OR product_type = 'gold')
                   AND (deposit_mode = 'none' OR product_type <> 'gold')))
              AND (kind = 'buyer' OR deposit_mode = 'none')
              AND (kind = 'buyer' OR price_mode = 'none'));

            ALTER TABLE buy_request
              ADD COLUMN IF NOT EXISTS price_discount_percent    NUMERIC(7,4),
              ADD COLUMN IF NOT EXISTS price_discount_amount_egp NUMERIC(18,4),
              ADD COLUMN IF NOT EXISTS price_discount_funded_by  TEXT
                CHECK (price_discount_funded_by IS NULL OR price_discount_funded_by IN ('commission','spread'));
            ALTER TABLE buy_request DROP CONSTRAINT IF EXISTS buy_request_price_discount_shape;
            ALTER TABLE buy_request ADD CONSTRAINT buy_request_price_discount_shape CHECK (
              (price_discount_funded_by IS NULL) = (price_discount_percent IS NULL AND price_discount_amount_egp IS NULL)
              AND (price_discount_percent IS NULL OR price_discount_amount_egp IS NULL));

            ALTER TABLE "order"
              ADD COLUMN IF NOT EXISTS price_discount_egp       NUMERIC(18,4) CHECK (price_discount_egp IS NULL OR price_discount_egp >= 0),
              ADD COLUMN IF NOT EXISTS price_discount_funded_by TEXT
                CHECK (price_discount_funded_by IS NULL OR price_discount_funded_by IN ('commission','spread'));
            SQL);

        DB::unprepared(self::guard([...self::TERMS, ...self::PRICE_TERMS]));
        DB::unprepared(self::reconciled('final_buyer_total - COALESCE(o.spread_given_up, 0) - COALESCE(o.price_discount_egp, 0)', ', price_discount_egp'));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(self::reconciled('final_buyer_total - COALESCE(o.spread_given_up, 0)', ''));
        DB::unprepared(self::guard(self::TERMS));
        DB::unprepared(<<<'SQL'
            ALTER TABLE promo_code DROP CONSTRAINT promo_code_kind_terms;
            ALTER TABLE promo_code ADD CONSTRAINT promo_code_kind_terms CHECK (
              (kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread))
              AND (kind IN ('market_maker','buyer') OR NOT gives_spread)
              AND (kind <> 'discount' OR commission_mode <> 'none')
              AND (kind <> 'buyer' OR (commission_mode = 'none'
                   AND ((product_type = 'gold') = gives_spread)
                   AND ((product_type = 'gold') = (deposit_mode = 'none'))))
              AND (kind = 'buyer' OR deposit_mode = 'none'));
            ALTER TABLE "order" DROP COLUMN IF EXISTS price_discount_funded_by, DROP COLUMN IF EXISTS price_discount_egp;
            ALTER TABLE buy_request DROP CONSTRAINT IF EXISTS buy_request_price_discount_shape;
            ALTER TABLE buy_request DROP COLUMN IF EXISTS price_discount_funded_by,
              DROP COLUMN IF EXISTS price_discount_amount_egp, DROP COLUMN IF EXISTS price_discount_percent;
            ALTER TABLE promo_code DROP CONSTRAINT IF EXISTS promo_code_price_shape;
            ALTER TABLE promo_code DROP COLUMN IF EXISTS discount_funded_by, DROP COLUMN IF EXISTS price_amount_egp,
              DROP COLUMN IF EXISTS price_percent, DROP COLUMN IF EXISTS price_mode;
            SQL);
    }

    /** DH020 (2026_10_26_000010): the terms of a used code never change; a used code is never deleted. */
    private static function guard(array $terms): string
    {
        $changed = implode("\n                 OR ", array_map(fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", $terms));

        return <<<SQL
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
            SQL;
    }

    /** `tax_invoice_reconciled()` (2026_10_25_000030) with the buyer's total paid. */
    private static function reconciled(string $buyerPaid, string $extraColumn): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION tax_invoice_reconciled() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              o RECORD;
              v_ok BOOLEAN;
            BEGIN
              SELECT order_ref, seller_id, buyer_id, settlement_txn_id, commission_amount, vat_amount, final_buyer_total, spread_given_up{$extraColumn}
                INTO o FROM "order" WHERE order_id = NEW.order_id;
              IF NOT FOUND OR o.settlement_txn_id IS NULL
                 OR NEW.invoice_no <> o.order_ref || (CASE NEW.party_role WHEN 'seller' THEN '-S' ELSE '-B' END)
                 OR NEW.customer_id <> (CASE NEW.party_role WHEN 'seller' THEN o.seller_id ELSE o.buyer_id END)
                 OR (NEW.party_role = 'seller' AND (NEW.net_amount <> o.commission_amount OR NEW.vat_amount <> o.vat_amount))
                 OR (NEW.party_role = 'buyer' AND NEW.net_amount <> o.{$buyerPaid}) THEN
                RAISE EXCEPTION 'invoice % does not match the settlement of its order', NEW.invoice_no USING ERRCODE = 'DH012';
              END IF;
              IF NEW.party_role = 'seller' THEN
                PERFORM set_config('app.rls_scope', 'ledger', true);
                SELECT (NEW.net_amount = 0 OR EXISTS (
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
