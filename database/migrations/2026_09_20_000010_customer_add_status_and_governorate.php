<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the customer verification lifecycle state (docs Part 1 §4.2).
 *
 * `status` is the authoritative lifecycle column. `is_verified` and
 * `is_suspended` remain for backward compatibility but are treated as
 * derived from `status`; a DB CHECK keeps them in lock-step, so a
 * mutation of one without the other is refused at the database.
 *
 * Also adds `governorate` (nullable until the customer completes the
 * multi-step registration) with a CHECK against the 27 Egyptian
 * governorate codes; the enum lives in App\Enums\Governorate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE customer
                ADD COLUMN status TEXT NOT NULL DEFAULT 'active'
                    CHECK (status IN ('pending_verification','active','rejected','suspended')),
                ADD COLUMN governorate TEXT
                    CHECK (governorate IS NULL OR governorate IN (
                        'cairo','giza','alexandria','dakahlia','red_sea','beheira','fayoum','gharbia',
                        'ismailia','menofia','minya','qaliubia','new_valley','suez','aswan','asyut',
                        'beni_suef','port_said','damietta','sharkia','south_sinai','kafr_el_sheikh',
                        'matrouh','luxor','qena','north_sinai','sohag'
                    )),
                ADD CONSTRAINT customer_status_flags_consistent CHECK (
                    (status = 'pending_verification' AND is_verified = FALSE AND is_suspended = FALSE)
                 OR (status = 'active'               AND is_verified = TRUE  AND is_suspended = FALSE)
                 OR (status = 'rejected'             AND is_verified = FALSE AND is_suspended = FALSE)
                 OR (status = 'suspended'            AND is_suspended = TRUE)
                );
        SQL);

        // Backfill: existing rows keep the old semantics — nothing was pending_verification before.
        DB::statement(<<<'SQL'
            UPDATE customer SET status =
                CASE
                    WHEN is_suspended THEN 'suspended'
                    WHEN is_verified  THEN 'active'
                    ELSE 'pending_verification'
                END
        SQL);

        // Once backfilled, drop the default so every new row states its status explicitly.
        DB::statement('ALTER TABLE customer ALTER COLUMN status DROP DEFAULT');

        DB::statement('CREATE INDEX customer_status_idx ON customer (status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_status_idx');
        DB::statement('ALTER TABLE customer DROP CONSTRAINT IF EXISTS customer_status_flags_consistent');
        DB::statement('ALTER TABLE customer DROP COLUMN IF EXISTS governorate');
        DB::statement('ALTER TABLE customer DROP COLUMN IF EXISTS status');
    }
};
