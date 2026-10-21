<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Testing notes: staff may give a seller 2 or 4 working hours too, not only 6, 12, 24 or 48.
 * Only the CHECK on `order_extension_request.hours_granted` changes; the spec 012 extend that
 * writes the deadline takes any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('
            ALTER TABLE order_extension_request DROP CONSTRAINT IF EXISTS order_extension_request_hours_granted_check;
            ALTER TABLE order_extension_request ADD CONSTRAINT order_extension_request_hours_granted_check
              CHECK (hours_granted IN (2,4,6,12,24,48));
        ');
    }

    public function down(): void
    {
        DB::unprepared('
            ALTER TABLE order_extension_request DROP CONSTRAINT IF EXISTS order_extension_request_hours_granted_check;
            ALTER TABLE order_extension_request ADD CONSTRAINT order_extension_request_hours_granted_check
              CHECK (hours_granted IN (6,12,24,48));
        ');
    }
};
