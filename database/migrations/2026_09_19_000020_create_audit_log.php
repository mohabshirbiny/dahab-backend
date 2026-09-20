<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE audit_log (
                audit_id           BIGSERIAL PRIMARY KEY,
                actor_staff_id     UUID REFERENCES staff(staff_id),
                actor_customer_id  UUID REFERENCES customer(customer_id),
                action             TEXT NOT NULL,
                entity_type        TEXT NOT NULL,
                entity_id          UUID,
                before_json        JSONB,
                after_json         JSONB,
                reason             TEXT,
                ip_address         INET,
                device_fingerprint TEXT,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT audit_has_actor CHECK (
                    actor_staff_id IS NOT NULL OR actor_customer_id IS NOT NULL
                )
            )
        ');

        DB::statement('CREATE INDEX idx_audit_entity  ON audit_log(entity_type, entity_id)');
        DB::statement('CREATE INDEX idx_audit_actor   ON audit_log(actor_staff_id)');
        DB::statement('CREATE INDEX idx_audit_created ON audit_log(created_at)');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION audit_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log rows are append-only (attempted %)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_no_update
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_block_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_no_update ON audit_log');
        DB::statement('DROP FUNCTION IF EXISTS audit_block_mutation()');
        Schema::dropIfExists('audit_log');
    }
};
