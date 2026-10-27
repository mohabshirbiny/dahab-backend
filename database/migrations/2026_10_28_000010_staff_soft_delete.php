<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staff edit and delete (docs/features/staff-edit-delete.md).
 *
 * A deleted staff member keeps their row (audit and every record point at it) with `deleted_at`
 * set and `is_active` false; they are gone from every list and lookup and cannot sign in. Their
 * email is freed: it is unique among staff not deleted only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE staff ADD COLUMN deleted_at TIMESTAMPTZ');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE staff ADD CONSTRAINT staff_deleted_inactive CHECK (deleted_at IS NULL OR NOT is_active)');
        DB::statement('ALTER TABLE staff DROP CONSTRAINT staff_email_key');
        DB::statement('CREATE UNIQUE INDEX staff_email_live ON staff (email) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX staff_email_live');
            DB::statement('ALTER TABLE staff ADD CONSTRAINT staff_email_key UNIQUE (email)');
            DB::statement('ALTER TABLE staff DROP CONSTRAINT staff_deleted_inactive');
        }

        DB::statement('ALTER TABLE staff DROP COLUMN deleted_at');
    }
};
