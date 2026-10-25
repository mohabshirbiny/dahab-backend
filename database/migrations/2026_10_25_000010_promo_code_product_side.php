<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promo codes by product and side (docs/features/promo-code-product-side.md).
 *
 * A code now says which product it is for (`product_type`: gold | diamond | gold_with_diamond) and
 * which side it serves (`side`: seller | buyer). Seller codes lower the commission (waive, a
 * percentage, or a fixed EGP amount). Buyer codes are the new kind `buyer`: on gold the buyer pays
 * the seller's price (the positive spread is handed back); on diamond and gold-with-diamond the
 * deposit is lowered by a percentage or a fixed EGP amount. The market-maker kind is left as it was.
 *
 * `buy_request` snapshots what its code does (`promo_waives_commission`, `promo_gives_spread`,
 * `deposit_discount_egp`), because the settlement runs in the `order` scope, which cannot read
 * `promo_code`. A request made with a market-maker code keeps both flags on, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE promo_code
              ADD COLUMN side                  TEXT,
              ADD COLUMN product_type          TEXT,
              ADD COLUMN commission_amount_egp NUMERIC(18,4),
              ADD COLUMN deposit_mode          TEXT NOT NULL DEFAULT 'none',
              ADD COLUMN deposit_percent       NUMERIC(7,4),
              ADD COLUMN deposit_amount_egp    NUMERIC(18,4);

            -- A market-maker code serves the buyer.
            SELECT set_config('app.rls_scope', 'maintenance', true);
            UPDATE promo_code SET side = CASE WHEN kind = 'market_maker' THEN 'buyer' ELSE 'seller' END;
            ALTER TABLE promo_code ALTER COLUMN side SET NOT NULL;

            -- The side follows the kind, so a row written without it (the earlier specs' way) still gets one.
            CREATE OR REPLACE FUNCTION promo_code_defaults() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.commission_waived AND NEW.commission_mode = 'none' THEN
                NEW.commission_mode := 'waive';
              END IF;
              NEW.side := CASE WHEN NEW.kind IN ('market_maker','buyer') THEN 'buyer' ELSE 'seller' END;
              RETURN NEW;
            END $$;

            ALTER TABLE promo_code DROP CONSTRAINT promo_code_kind_check;
            ALTER TABLE promo_code DROP CONSTRAINT promo_code_mode_check;
            ALTER TABLE promo_code DROP CONSTRAINT promo_code_kind_terms;
            ALTER TABLE promo_code
              ADD CONSTRAINT promo_code_kind_check CHECK (kind IN ('first_sale','market_maker','discount','buyer')),
              ADD CONSTRAINT promo_code_mode_check CHECK (commission_mode IN ('waive','percent','amount','none')),
              ADD CONSTRAINT promo_code_side_check CHECK (side IN ('seller','buyer')),
              ADD CONSTRAINT promo_code_side_kind CHECK ((kind IN ('market_maker','buyer')) = (side = 'buyer')),
              ADD CONSTRAINT promo_code_product_check CHECK (product_type IS NULL OR product_type IN ('gold','diamond','gold_with_diamond')),
              ADD CONSTRAINT promo_code_amount_shape CHECK ((commission_mode = 'amount') = (commission_amount_egp IS NOT NULL)
                AND (commission_amount_egp IS NULL OR commission_amount_egp > 0)),
              ADD CONSTRAINT promo_code_deposit_mode_check CHECK (deposit_mode IN ('none','percent','amount')),
              ADD CONSTRAINT promo_code_deposit_shape CHECK (
                (deposit_mode = 'percent') = (deposit_percent IS NOT NULL)
                AND (deposit_mode = 'amount') = (deposit_amount_egp IS NOT NULL)
                AND (deposit_percent IS NULL OR (deposit_percent > 0 AND deposit_percent < 100))
                AND (deposit_amount_egp IS NULL OR deposit_amount_egp > 0)),
              ADD CONSTRAINT promo_code_kind_terms CHECK (
                (kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread))
                AND (kind IN ('market_maker','buyer') OR NOT gives_spread)
                AND (kind <> 'discount' OR commission_mode <> 'none')
                -- A buyer code has no commission; gold gives the spread back, stones lower the deposit.
                AND (kind <> 'buyer' OR (commission_mode = 'none'
                     AND ((product_type = 'gold') = gives_spread)
                     AND ((product_type = 'gold') = (deposit_mode = 'none'))))
                AND (kind = 'buyer' OR deposit_mode = 'none'));

            CREATE OR REPLACE FUNCTION promo_code_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a promo code is never deleted' USING ERRCODE = 'DH020';
              END IF;
              IF NEW.code IS DISTINCT FROM OLD.code OR NEW.kind IS DISTINCT FROM OLD.kind
                 OR NEW.tied_customer_id IS DISTINCT FROM OLD.tied_customer_id
                 OR NEW.commission_waived IS DISTINCT FROM OLD.commission_waived
                 OR NEW.gives_spread IS DISTINCT FROM OLD.gives_spread
                 OR NEW.audience IS DISTINCT FROM OLD.audience
                 OR NEW.commission_mode IS DISTINCT FROM OLD.commission_mode
                 OR NEW.commission_percent IS DISTINCT FROM OLD.commission_percent
                 OR NEW.commission_amount_egp IS DISTINCT FROM OLD.commission_amount_egp
                 OR NEW.side IS DISTINCT FROM OLD.side
                 OR NEW.product_type IS DISTINCT FROM OLD.product_type
                 OR NEW.deposit_mode IS DISTINCT FROM OLD.deposit_mode
                 OR NEW.deposit_percent IS DISTINCT FROM OLD.deposit_percent
                 OR NEW.deposit_amount_egp IS DISTINCT FROM OLD.deposit_amount_egp
                 OR NEW.use_limit IS DISTINCT FROM OLD.use_limit
                 OR NEW.first_sale_only IS DISTINCT FROM OLD.first_sale_only
                 OR NEW.pays_advance IS DISTINCT FROM OLD.pays_advance
                 OR NEW.min_listed_days IS DISTINCT FROM OLD.min_listed_days
                 OR NEW.requires_approval IS DISTINCT FROM OLD.requires_approval
                 OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                 OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'only the cap and the on/off of a promo code may change' USING ERRCODE = 'DH020';
              END IF;
              RETURN NEW;
            END $$;

            ALTER TABLE buy_request
              ADD COLUMN promo_waives_commission BOOLEAN NOT NULL DEFAULT FALSE,
              ADD COLUMN promo_gives_spread      BOOLEAN NOT NULL DEFAULT FALSE,
              ADD COLUMN deposit_discount_egp    NUMERIC(18,4) NOT NULL DEFAULT 0 CHECK (deposit_discount_egp >= 0);
            UPDATE buy_request SET promo_waives_commission = TRUE, promo_gives_spread = TRUE WHERE promo_code IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT set_config('app.rls_scope', 'maintenance', true)");
        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM promo_code WHERE kind = 'buyer' OR commission_mode = 'amount' OR product_type IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM buy_request WHERE deposit_discount_egp > 0)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Promo codes by product and side exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE buy_request
              DROP COLUMN deposit_discount_egp, DROP COLUMN promo_gives_spread, DROP COLUMN promo_waives_commission;

            CREATE OR REPLACE FUNCTION promo_code_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a promo code is never deleted' USING ERRCODE = 'DH020';
              END IF;
              IF NEW.code IS DISTINCT FROM OLD.code OR NEW.kind IS DISTINCT FROM OLD.kind
                 OR NEW.tied_customer_id IS DISTINCT FROM OLD.tied_customer_id
                 OR NEW.commission_waived IS DISTINCT FROM OLD.commission_waived
                 OR NEW.gives_spread IS DISTINCT FROM OLD.gives_spread
                 OR NEW.audience IS DISTINCT FROM OLD.audience
                 OR NEW.commission_mode IS DISTINCT FROM OLD.commission_mode
                 OR NEW.commission_percent IS DISTINCT FROM OLD.commission_percent
                 OR NEW.use_limit IS DISTINCT FROM OLD.use_limit
                 OR NEW.first_sale_only IS DISTINCT FROM OLD.first_sale_only
                 OR NEW.pays_advance IS DISTINCT FROM OLD.pays_advance
                 OR NEW.min_listed_days IS DISTINCT FROM OLD.min_listed_days
                 OR NEW.requires_approval IS DISTINCT FROM OLD.requires_approval
                 OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                 OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'only the cap and the on/off of a promo code may change' USING ERRCODE = 'DH020';
              END IF;
              RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION promo_code_defaults() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.commission_waived AND NEW.commission_mode = 'none' THEN
                NEW.commission_mode := 'waive';
              END IF;
              RETURN NEW;
            END $$;

            ALTER TABLE promo_code
              DROP CONSTRAINT promo_code_kind_terms, DROP CONSTRAINT promo_code_deposit_shape,
              DROP CONSTRAINT promo_code_deposit_mode_check, DROP CONSTRAINT promo_code_amount_shape,
              DROP CONSTRAINT promo_code_product_check,
              DROP CONSTRAINT promo_code_side_kind, DROP CONSTRAINT promo_code_side_check,
              DROP CONSTRAINT promo_code_mode_check, DROP CONSTRAINT promo_code_kind_check;
            ALTER TABLE promo_code
              ADD CONSTRAINT promo_code_kind_check CHECK (kind IN ('first_sale','market_maker','discount')),
              ADD CONSTRAINT promo_code_mode_check CHECK (commission_mode IN ('waive','percent','none')),
              ADD CONSTRAINT promo_code_kind_terms CHECK (
                (kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread))
                AND (kind = 'market_maker' OR NOT gives_spread)
                AND (kind <> 'discount' OR commission_mode <> 'none')),
              DROP COLUMN deposit_amount_egp, DROP COLUMN deposit_percent, DROP COLUMN deposit_mode,
              DROP COLUMN commission_amount_egp, DROP COLUMN product_type, DROP COLUMN side;
            SQL);
    }
};
