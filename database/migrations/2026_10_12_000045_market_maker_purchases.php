<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 019 US6 / 6B, market makers, purchases (specs/019-app-content/data-model.md §4B).
 *
 * Built on the owner's instruction ahead of Finance's written sign-off of D3 (the spread to
 * the dealer and its ledger and VAT treatment); the report says so. `buy_request` gains the
 * code a request was made with; `promo_code_use` gains what the use was for. No ledger event
 * kind is added: the sale settles as an ordinary `balance_payment`, with the spread line
 * credited to the dealer instead of `dahab_spread`.
 *
 * The buy-request service runs in the `queue` scope, which is not an elevation, so it may
 * read codes and approvals and write the use log — and nothing else of the programme.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE buy_request ADD COLUMN promo_code TEXT REFERENCES promo_code(code);
            CREATE INDEX idx_buy_request_promo ON buy_request (promo_code) WHERE promo_code IS NOT NULL;

            ALTER TABLE promo_code_use
              ADD COLUMN buy_request_id UUID REFERENCES buy_request(buy_request_id),
              ADD COLUMN amount_egp NUMERIC(18,4),
              ADD COLUMN device_fingerprint TEXT;

            DROP POLICY promo_code_staff ON promo_code;
            CREATE POLICY promo_code_staff ON promo_code FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'queue')
              WITH CHECK ((SELECT dahab_rls_elevated()));

            DROP POLICY promo_code_use_staff ON promo_code_use;
            CREATE POLICY promo_code_use_staff ON promo_code_use FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'queue')
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'queue');

            DROP POLICY market_maker_approval_staff ON market_maker_approval;
            CREATE POLICY market_maker_approval_staff ON market_maker_approval FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','market'))
              WITH CHECK ((SELECT dahab_rls_elevated()));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne('SELECT EXISTS (SELECT 1 FROM buy_request WHERE promo_code IS NOT NULL) OR EXISTS (SELECT 1 FROM promo_code_use WHERE buy_request_id IS NOT NULL) AS used')->used;
        if ($used) {
            throw new RuntimeException('Spec 019 market-maker purchases exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY promo_code_staff ON promo_code;
            CREATE POLICY promo_code_staff ON promo_code FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            DROP POLICY promo_code_use_staff ON promo_code_use;
            CREATE POLICY promo_code_use_staff ON promo_code_use FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            DROP POLICY market_maker_approval_staff ON market_maker_approval;
            CREATE POLICY market_maker_approval_staff ON market_maker_approval FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            ALTER TABLE promo_code_use DROP COLUMN device_fingerprint, DROP COLUMN amount_egp, DROP COLUMN buy_request_id;
            DROP INDEX IF EXISTS idx_buy_request_promo;
            ALTER TABLE buy_request DROP COLUMN promo_code;
            SQL);
    }
};
