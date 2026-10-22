<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Testing notes: a seller's first sale is paid ahead with no code and no staff enablement when the
 * piece is gold. `first_sale_advance.basis` gains `automatic` (no code, no enablement); the
 * `advance_basis_ref` CHECK already holds for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            ALTER TABLE first_sale_advance DROP CONSTRAINT IF EXISTS first_sale_advance_basis_check;
            ALTER TABLE first_sale_advance ADD CONSTRAINT first_sale_advance_basis_check
              CHECK (basis IN ('promo_code','staff_enablement','automatic'));
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            ALTER TABLE first_sale_advance DROP CONSTRAINT IF EXISTS first_sale_advance_basis_check;
            ALTER TABLE first_sale_advance ADD CONSTRAINT first_sale_advance_basis_check
              CHECK (basis IN ('promo_code','staff_enablement'));
        ");
    }
};
