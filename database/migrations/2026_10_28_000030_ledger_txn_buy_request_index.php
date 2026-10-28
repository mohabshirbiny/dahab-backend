<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard performance: the Overview's "held now" per order reads the
 * entries of the orders' buy requests only, through this index, instead of
 * summing every held posting the ledger ever had. Read path only; nothing
 * posted changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_ledger_txn_buy_request ON ledger_transaction(buy_request_id) WHERE buy_request_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_ledger_txn_buy_request');
    }
};
