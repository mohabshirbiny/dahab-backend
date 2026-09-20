<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `document_view_log` + trigger `docview_no_update` in
     * docs/Database schema/05_schema_security.sql. Append-only: the docs call a
     * shared `block_mutation()`; like `audit_log`, this table owns a dedicated
     * function so dropping one table's trigger never touches another's.
     */
    public function up(): void
    {
        DB::statement('
            CREATE TABLE document_view_log (
                view_id     BIGSERIAL PRIMARY KEY,
                document_id UUID NOT NULL REFERENCES identity_document(document_id),
                viewed_by   UUID NOT NULL REFERENCES staff(staff_id),
                viewed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                ip_address  INET
            )
        ');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION docview_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'document_view_log rows are append-only (attempted %)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER docview_no_update
                BEFORE UPDATE OR DELETE ON document_view_log
                FOR EACH ROW EXECUTE FUNCTION docview_block_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS docview_no_update ON document_view_log');
        DB::statement('DROP FUNCTION IF EXISTS docview_block_mutation()');
        Schema::dropIfExists('document_view_log');
    }
};
