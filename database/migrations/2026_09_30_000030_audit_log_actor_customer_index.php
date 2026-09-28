<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer file's History lists what the customer did, newest first
 * (spec 007 research R7, SC-005; docs/Database schema/05_schema_security.sql).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX idx_audit_actor_customer ON audit_log (actor_customer_id, created_at DESC)
            WHERE actor_customer_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_audit_actor_customer');
    }
};
