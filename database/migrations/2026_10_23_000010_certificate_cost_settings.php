<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What the seller pays for IGI's certificate, by the certificate they bring (Part 3 §5 / OI-3.2):
 * an IGI certificate costs nothing, another lab's is reduced, none is the full cost. Staff set
 * the amounts on the Rates page; the sell form shows them. Not yet taken at settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
              ('certificate.igi_cost_egp',       0,    'egp', 'IGI certificate cost when the seller brings an IGI certificate'),
              ('certificate.other_lab_cost_egp', 1000, 'egp', 'IGI certificate cost when the seller brings another lab''s certificate'),
              ('certificate.none_cost_egp',      2000, 'egp', 'IGI certificate cost when the seller brings no certificate')
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM setting WHERE setting_key IN
            ('certificate.igi_cost_egp', 'certificate.other_lab_cost_egp', 'certificate.none_cost_egp')");
    }
};
