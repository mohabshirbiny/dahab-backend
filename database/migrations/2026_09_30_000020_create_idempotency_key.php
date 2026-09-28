<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Server-side idempotency store (spec 007 research R2, Part 2 "Idempotency",
 * docs/Database schema/05_schema_security.sql).
 *
 * Operational, not audit: a row moves in_flight -> completed | failed and is
 * pruned after expires_at (idempotency:prune). Forced RLS like every
 * customer-owned table: a customer context sees only its own keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE idempotency_key (
                id                BIGSERIAL PRIMARY KEY,
                idem_key          UUID NOT NULL,
                actor_kind        TEXT NOT NULL CHECK (actor_kind IN ('customer','staff')),
                actor_customer_id UUID REFERENCES customer(customer_id),
                actor_staff_id    UUID REFERENCES staff(staff_id),
                endpoint          TEXT NOT NULL,
                request_hash      CHAR(64) NOT NULL,
                state             TEXT NOT NULL DEFAULT 'in_flight'
                                    CHECK (state IN ('in_flight','completed','failed')),
                response_status   SMALLINT,
                response_body     TEXT,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                completed_at      TIMESTAMPTZ,
                expires_at        TIMESTAMPTZ NOT NULL,
                CONSTRAINT idempotency_has_actor CHECK (
                     (actor_kind = 'customer' AND actor_customer_id IS NOT NULL AND actor_staff_id IS NULL)
                  OR (actor_kind = 'staff'    AND actor_staff_id IS NOT NULL    AND actor_customer_id IS NULL)
                )
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX uq_idempotency_key ON idempotency_key
            (actor_kind, COALESCE(actor_customer_id, actor_staff_id), endpoint, idem_key)');
        DB::statement('CREATE INDEX idx_idempotency_expires ON idempotency_key (expires_at)');

        DB::statement('ALTER TABLE idempotency_key ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE idempotency_key FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY idempotency_key_isolation ON idempotency_key FOR ALL
            USING (dahab_rls_elevated() OR actor_customer_id = dahab_current_customer_id())
            WITH CHECK (dahab_rls_elevated() OR actor_customer_id = dahab_current_customer_id())');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS idempotency_key');
    }
};
