<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit log viewer (spec 006) lists entries newest first and pages by
 * (created_at, audit_id); this index serves both. No other change to
 * audit_log: its rows, trigger and RLS policies are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_audit_created_id ON audit_log (created_at DESC, audit_id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_audit_created_id');
    }
};
