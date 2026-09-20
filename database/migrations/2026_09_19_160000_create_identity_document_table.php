<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `identity_document` in docs/Database schema/02_schema_identity.sql.
     * The table holds a reference to the encrypted object plus review metadata,
     * never the image. RLS (`cust_self_document`) is deliberately not enabled
     * here — see 2026_09_19_000010's note on the RLS follow-up.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE identity_document (
                document_id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                customer_id      UUID NOT NULL REFERENCES customer(customer_id),
                doc_kind         TEXT NOT NULL CHECK (doc_kind IN ('egyptian_id','passport')),
                storage_ref      TEXT NOT NULL,
                status           TEXT NOT NULL DEFAULT 'pending'
                                   CHECK (status IN ('pending','approved','rejected')),
                reviewed_by      UUID REFERENCES staff(staff_id),
                reviewed_at      TIMESTAMPTZ,
                image_deleted_at TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_document');
    }
};
