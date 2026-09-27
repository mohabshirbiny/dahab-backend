<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings (spec 005): `docs/Database schema/01_schema_core.sql` §3 — the
 * single source of every tunable number — with the spec 005 additions:
 * staff FKs, a reason on each history row, the history made append-only,
 * the two price_correction.* keys moved to karat_price_adjustment, and
 * three new keys. The Dashboard changes values; keys come with releases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE setting (
                setting_key   TEXT PRIMARY KEY,
                value_numeric NUMERIC(18,4),
                value_text    TEXT,
                value_bool    BOOLEAN,
                unit          TEXT,
                description   TEXT NOT NULL,
                updated_by    UUID REFERENCES staff(staff_id),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ');

        DB::statement('
            CREATE TABLE setting_history (
                setting_history_id BIGSERIAL PRIMARY KEY,
                setting_key   TEXT NOT NULL,
                old_numeric   NUMERIC(18,4),
                new_numeric   NUMERIC(18,4),
                old_text      TEXT,
                new_text      TEXT,
                old_bool      BOOLEAN,
                new_bool      BOOLEAN,
                changed_by    UUID NOT NULL REFERENCES staff(staff_id),
                reason        TEXT NOT NULL,
                changed_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ');
        DB::statement('CREATE INDEX setting_history_key ON setting_history (setting_key, changed_at DESC)');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION pricing_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% rows are append-only (attempted %)', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER setting_history_no_update
                BEFORE UPDATE OR DELETE ON setting_history
                FOR EACH ROW EXECUTE FUNCTION pricing_block_mutation();
        ");

        DB::statement("
            INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
              ('commission.gold_pct',              20,     'percent',       'Commission on the making charge recovered on gold'),
              ('commission.stone_pct',             5,      'percent',       'Commission on value added above gold on stones'),
              ('commission.minimum_egp',           200,    'egp',           'Minimum commission, never broken'),
              ('vat.pct',                          14,     'percent',       'VAT, applied to commission only'),
              ('deposit.buyer_pct',                20,     'percent',       'Buyer deposit held on a buy request'),
              ('deposit.seller_forfeit_share_pct', 50,     'percent',       'Share of a forfeited deposit paid to the seller'),
              ('deadline.seller_reply_hours',      48,     'hours',         'Seller must reply to a request within N clock hours'),
              ('deadline.reach_branch_working_hours', 12,  'working_hours', 'Seller must reach the branch within N working hours'),
              ('deadline.buyer_pay_days',          10,     'days',          'Buyer pays the balance within N days of inspection'),
              ('deadline.collect_weeks',           3,      'weeks',         'Piece waits at the branch N weeks after payment (buyer paid, not collected)'),
              ('deadline.seller_return_weeks',     3,      'weeks',         'Returned piece waits at the branch N weeks for the seller to collect (buyer did not pay)'),
              ('deadline.free_relist_working_hours', 12,   'working_hours', 'Free 0% relist window after collecting'),
              ('withdrawal.account_change_pause_hours', 48,'hours',         'Withdrawals pause N hours after a payout-account change'),
              ('inspection.weight_tolerance_pct',  1.5,    'percent',       'Weight difference auto-adjusted; above needs approval'),
              ('marketmaker.min_list_age_days',    7,      'days',          'A piece must be listed N days before a MM code can buy'),
              ('payout.first_sale_cap_egp',        100000, 'egp',           'Cap on paying a first-time gold seller before the buyer pays'),
              ('suspension.cancellations_threshold', 2,    'count',         'Seller cancellations before listing is suspended'),
              ('flag.pattern_txn_threshold',       5,      'count',         'Transactions before a pattern is flagged for review'),
              ('compensation.cap_per_payment_egp', 2000,   'egp',           'Compensation cap per payment (Finance)'),
              ('compensation.cap_per_day_egp',     5000,   'egp',           'Compensation cap per day (Finance)'),
              ('manualprice.confirm_deviation_pct',10,     'percent',       'Manual gold price above this deviation needs a second confirm'),
              ('manualprice.pending_expiry_hours', 24,     'hours',         'A manual price waiting for confirmation lapses after N hours'),
              ('pricefeed.stale_after_minutes',    5,      'minutes',       'The price feed counts as down after N minutes without a good reading')
        ");

        DB::statement("
            INSERT INTO setting (setting_key, value_bool, unit, description) VALUES
              ('manualprice.confirmer_must_differ', TRUE, 'bool', 'The person who confirms a manual price must differ from the one who entered it')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS setting_history');
        DB::statement('DROP TABLE IF EXISTS setting');
        DB::statement('DROP FUNCTION IF EXISTS pricing_block_mutation()');
    }
};
