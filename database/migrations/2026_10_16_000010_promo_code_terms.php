<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 023 M1 — the terms of a promo code, the use log's new columns, the ID-number hash and
 * the sixteen settings (specs/023-first-sale-and-referral/data-model.md §1, §7).
 *
 * `promo_code` gains the terms the Dashboard design's New-code form needs (who may use it, the
 * commission, the use limit, the waiting age and approval of a dealer's piece, the dates). All
 * of them are immutable after creation, like `commission_waived` (guard DH020 replaced).
 * Rows written by spec 019 keep their meaning: waive, spread to the dealer, one account, the
 * setting's waiting age, approval required. `kind` gains `discount`; `first_sale` now means
 * the code pays the advance.
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
              ADD COLUMN audience           TEXT NOT NULL DEFAULT 'account',
              ADD COLUMN commission_mode    TEXT NOT NULL DEFAULT 'none',
              ADD COLUMN commission_percent NUMERIC(7,4),
              ADD COLUMN use_limit          TEXT NOT NULL DEFAULT 'unlimited',
              ADD COLUMN first_sale_only    BOOLEAN NOT NULL DEFAULT FALSE,
              ADD COLUMN pays_advance       BOOLEAN NOT NULL DEFAULT FALSE,
              ADD COLUMN min_listed_days    SMALLINT,
              ADD COLUMN requires_approval  BOOLEAN NOT NULL DEFAULT TRUE,
              ADD COLUMN starts_on          DATE,
              ADD COLUMN ends_on            DATE;

            -- Every spec 019 code is a market-maker code that waives the commission.
            SELECT set_config('app.rls_scope', 'maintenance', true);
            UPDATE promo_code SET commission_mode = CASE WHEN commission_waived THEN 'waive' ELSE 'none' END;

            ALTER TABLE promo_code DROP CONSTRAINT promo_code_kind_check;
            ALTER TABLE promo_code
              ADD CONSTRAINT promo_code_kind_check CHECK (kind IN ('first_sale','market_maker','discount')),
              ADD CONSTRAINT promo_code_audience_check CHECK (audience IN ('account','anyone')),
              ADD CONSTRAINT promo_code_audience_tied CHECK ((audience = 'account') = (tied_customer_id IS NOT NULL)),
              ADD CONSTRAINT promo_code_mode_check CHECK (commission_mode IN ('waive','percent','none')),
              ADD CONSTRAINT promo_code_mode_waived CHECK ((commission_mode = 'waive') = commission_waived),
              ADD CONSTRAINT promo_code_percent_shape CHECK ((commission_mode = 'percent') = (commission_percent IS NOT NULL)),
              ADD CONSTRAINT promo_code_percent_range CHECK (commission_percent IS NULL OR (commission_percent > 0 AND commission_percent < 100)),
              ADD CONSTRAINT promo_code_use_limit_check CHECK (use_limit IN ('unlimited','once','monthly')),
              ADD CONSTRAINT promo_code_advance_kind CHECK ((kind = 'first_sale') = pays_advance),
              ADD CONSTRAINT promo_code_advance_first_sale CHECK (NOT pays_advance OR first_sale_only),
              ADD CONSTRAINT promo_code_listed_days CHECK (min_listed_days IS NULL OR min_listed_days BETWEEN 0 AND 3650),
              ADD CONSTRAINT promo_code_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on),
              ADD CONSTRAINT promo_code_kind_terms CHECK (
                (kind <> 'market_maker' OR (audience = 'account' AND commission_mode = 'waive' AND gives_spread))
                AND (kind = 'market_maker' OR NOT gives_spread)
                AND (kind <> 'discount' OR commission_mode <> 'none'));

            -- A code is never deleted, and only its cap and its on/off may change (DH020, now with the new terms).
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

            -- A row written the spec 019 way (`commission_waived` only) reads as `waive`.
            CREATE OR REPLACE FUNCTION promo_code_defaults() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.commission_waived AND NEW.commission_mode = 'none' THEN
                NEW.commission_mode := 'waive';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_promo_code_defaults BEFORE INSERT ON promo_code
              FOR EACH ROW EXECUTE FUNCTION promo_code_defaults();

            -- Seller-side uses: the listing the code was applied to and the person it counts against.
            ALTER TABLE promo_code_use
              ADD COLUMN listing_id UUID REFERENCES listing(listing_id),
              ADD COLUMN id_key     TEXT,
              ADD COLUMN credit_id  UUID,
              ADD CONSTRAINT promo_code_use_one_target CHECK (num_nonnulls(buy_request_id, listing_id) <= 1);
            CREATE INDEX idx_promo_code_use_key ON promo_code_use (code, id_key) WHERE allowed;
            CREATE INDEX idx_promo_code_use_listing ON promo_code_use (listing_id) WHERE listing_id IS NOT NULL;

            -- The reviewer keys the national-ID number at approval; only its HMAC is kept (research R8).
            ALTER TABLE identity_document ADD COLUMN id_number_hash TEXT;

            INSERT INTO setting (setting_key, value_bool, unit, description) VALUES
              ('payout.first_sale_enabled',               FALSE, 'bool', 'Pay a first-time gold seller ahead, before the buyer pays (Finance to sign off the ledger first)'),
              ('payout.first_sale_comp_offsets_writeoff', FALSE, 'bool', 'When an advance was paid and the buyer never pays, the seller''s share of the forfeited deposit goes to Dahab'),
              ('promo.seller_codes_enabled',              FALSE, 'bool', 'Customers may use seller-side commission codes on the sell form'),
              ('referral.enabled',                        FALSE, 'bool', 'Invite a friend and referral commission credits work'),
              ('marketmaker.require_approval',            TRUE,  'bool', 'A market-maker purchase needs the piece approved first');
            INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
              ('payout.first_sale_exposure_ceiling_egp', 1000000, 'egp',     'Most EGP out in first-sale advances at once, across everyone; above it the advance is skipped'),
              ('referral.friend_percent_off',            50,      'percent', 'The friend''s share off the commission on the sales a referral covers'),
              ('referral.inviter_reward_egp',            200,     'egp',     'Commission credit the inviter earns when the friend completes a sale'),
              ('referral.friend_sales_covered',          1,       'count',   'How many of the friend''s sales the percentage off applies to'),
              ('referral.credit_expiry_days',            90,      'days',    'How long a referral commission credit lasts'),
              ('referral.max_uses_per_code',             10,      'count',   'Friends who may join with one invitation code'),
              ('referral.max_invitations_per_account',   100,     'count',   'Share and Copy taps counted per account'),
              ('referral.max_per_device',                1,       'count',   'Referral redemptions allowed from one device'),
              ('referral.min_friend_sale_egp',           5000,    'egp',     'Smallest sale of the friend that earns the inviter''s reward'),
              ('marketmaker.making_charge_warn_pct',     30,      'percent', 'A making charge above this share of the gold value shows red on the approvals page'),
              ('listing.view_retention_days',            90,      'days',    'How long a listing view is kept');
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Rows that use what this migration added cannot be rolled back (spec 019 defaults only).
        // The tables have forced RLS: read them in the maintenance scope for this transaction.
        DB::statement("SELECT set_config('app.rls_scope', 'maintenance', true)");
        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM promo_code
                             WHERE audience <> 'account' OR commission_mode NOT IN ('waive') OR use_limit <> 'unlimited'
                                OR first_sale_only OR pays_advance OR min_listed_days IS NOT NULL OR NOT requires_approval
                                OR starts_on IS NOT NULL OR ends_on IS NOT NULL OR kind <> 'market_maker')
                 OR EXISTS (SELECT 1 FROM promo_code_use WHERE listing_id IS NOT NULL OR id_key IS NOT NULL OR credit_id IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM identity_document WHERE id_number_hash IS NOT NULL)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 023 promo-code terms or uses exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DELETE FROM setting WHERE setting_key IN (
              'payout.first_sale_enabled','payout.first_sale_comp_offsets_writeoff','promo.seller_codes_enabled',
              'referral.enabled','marketmaker.require_approval','payout.first_sale_exposure_ceiling_egp',
              'referral.friend_percent_off','referral.inviter_reward_egp','referral.friend_sales_covered',
              'referral.credit_expiry_days','referral.max_uses_per_code','referral.max_invitations_per_account',
              'referral.max_per_device','referral.min_friend_sale_egp','marketmaker.making_charge_warn_pct',
              'listing.view_retention_days');

            DROP TRIGGER IF EXISTS trg_promo_code_defaults ON promo_code;
            DROP FUNCTION IF EXISTS promo_code_defaults();

            ALTER TABLE identity_document DROP COLUMN id_number_hash;

            DROP INDEX IF EXISTS idx_promo_code_use_listing;
            DROP INDEX IF EXISTS idx_promo_code_use_key;
            ALTER TABLE promo_code_use
              DROP CONSTRAINT promo_code_use_one_target,
              DROP COLUMN credit_id, DROP COLUMN id_key, DROP COLUMN listing_id;

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
                 OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'only the cap and the on/off of a promo code may change' USING ERRCODE = 'DH020';
              END IF;
              RETURN NEW;
            END $$;

            ALTER TABLE promo_code
              DROP CONSTRAINT promo_code_kind_terms, DROP CONSTRAINT promo_code_dates, DROP CONSTRAINT promo_code_listed_days,
              DROP CONSTRAINT promo_code_advance_first_sale, DROP CONSTRAINT promo_code_advance_kind,
              DROP CONSTRAINT promo_code_use_limit_check, DROP CONSTRAINT promo_code_percent_range,
              DROP CONSTRAINT promo_code_percent_shape, DROP CONSTRAINT promo_code_mode_waived,
              DROP CONSTRAINT promo_code_mode_check, DROP CONSTRAINT promo_code_audience_tied,
              DROP CONSTRAINT promo_code_audience_check, DROP CONSTRAINT promo_code_kind_check;
            ALTER TABLE promo_code
              ADD CONSTRAINT promo_code_kind_check CHECK (kind IN ('first_sale','market_maker')),
              DROP COLUMN ends_on, DROP COLUMN starts_on, DROP COLUMN requires_approval, DROP COLUMN min_listed_days,
              DROP COLUMN pays_advance, DROP COLUMN first_sale_only, DROP COLUMN use_limit,
              DROP COLUMN commission_percent, DROP COLUMN commission_mode, DROP COLUMN audience;
            SQL);
    }
};
