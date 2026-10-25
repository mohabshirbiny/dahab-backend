<?php

use App\Support\DatabaseActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The certificate fee taken at settlement (Part 3 §5 / OI-3.2, settings from 2026_10_23_000010):
 * the seller of a stone piece names the certificate they bring (`listing.certificate_kind`); the
 * cost for that kind is locked on the order at acceptance (`"order".certificate_cost`) and taken
 * from the seller's proceeds at pay-balance onto a new internal account, `igi_certificates`.
 * A listing or order without these values (everything before this) is charged nothing.
 *
 * Not in a transaction: a new enum value cannot be used in the transaction that adds it.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("ALTER TYPE account_kind ADD VALUE IF NOT EXISTS 'igi_certificates'");

        DB::unprepared(<<<'SQL'
            ALTER TABLE listing
              ADD COLUMN IF NOT EXISTS certificate_kind TEXT
                CHECK (certificate_kind IN ('igi', 'other_lab', 'none'));
            ALTER TABLE "order"
              ADD COLUMN IF NOT EXISTS certificate_cost NUMERIC(18,4)
                CHECK (certificate_cost IS NULL OR certificate_cost >= 0);
        SQL);

        DatabaseActor::elevate('maintenance', fn () => DB::insert(
            "INSERT INTO account (kind) VALUES ('igi_certificates') ON CONFLICT DO NOTHING",
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The enum value and the account stay: postings may reference them.
        DB::unprepared('ALTER TABLE "order" DROP COLUMN IF EXISTS certificate_cost; ALTER TABLE listing DROP COLUMN IF EXISTS certificate_kind;');
    }
};
