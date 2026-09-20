<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `customer.customer_type` for the Dashboard's Users and Verification
 * page (Ordinary / Market maker). Every existing and new customer is
 * `ordinary`; nothing in the customer API can set it, so the value is only
 * ever changed by staff-side work that is not built yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE customer
                ADD COLUMN customer_type TEXT NOT NULL DEFAULT 'ordinary'
                    CHECK (customer_type IN ('ordinary','market_maker'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer DROP COLUMN IF EXISTS customer_type');
    }
};
