<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds SQL helpers that read the request-scoped actor from
     * `SET LOCAL app.current_customer_id`/`app.current_staff_id`.
     *
     * Row-level security policies that USE these helpers are intentionally
     * NOT enabled in this migration. Turning RLS on affects every read
     * path — including registration and login — and requires a system-role
     * bypass that is out of scope for the auth MVP (US1). RLS activation
     * lands in a follow-up feature; the helpers land here so it becomes
     * a one-line migration when it does.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dahab_current_customer_id() RETURNS uuid AS $$
            BEGIN
                RETURN NULLIF(current_setting('app.current_customer_id', true), '')::uuid;
            EXCEPTION WHEN OTHERS THEN
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql STABLE;

            CREATE OR REPLACE FUNCTION dahab_current_staff_id() RETURNS uuid AS $$
            BEGIN
                RETURN NULLIF(current_setting('app.current_staff_id', true), '')::uuid;
            EXCEPTION WHEN OTHERS THEN
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS dahab_current_customer_id()');
        DB::statement('DROP FUNCTION IF EXISTS dahab_current_staff_id()');
    }
};
