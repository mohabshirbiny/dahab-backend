<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE customer_trusted_device (
                id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                customer_id      UUID NOT NULL REFERENCES customer(customer_id) ON DELETE CASCADE,
                fingerprint_hash TEXT NOT NULL,
                first_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                last_seen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (customer_id, fingerprint_hash)
            )
        ');

        DB::statement('
            CREATE TABLE staff_device_fingerprint (
                id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                staff_id         UUID NOT NULL REFERENCES staff(staff_id) ON DELETE CASCADE,
                fingerprint_hash TEXT NOT NULL,
                first_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                last_seen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (staff_id, fingerprint_hash)
            )
        ');

        DB::statement("
            CREATE TABLE one_time_token (
                id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                token_hash        TEXT NOT NULL UNIQUE,
                purpose           TEXT NOT NULL CHECK (purpose IN (
                    'password_reset_customer',
                    'password_reset_staff',
                    'email_verification'
                )),
                actor_customer_id UUID REFERENCES customer(customer_id) ON DELETE CASCADE,
                actor_staff_id    UUID REFERENCES staff(staff_id)       ON DELETE CASCADE,
                payload           JSONB,
                expires_at        TIMESTAMPTZ NOT NULL,
                consumed_at       TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT one_time_token_exactly_one_actor CHECK (
                    (actor_customer_id IS NOT NULL) <> (actor_staff_id IS NOT NULL)
                )
            )
        ");
        DB::statement('CREATE INDEX idx_one_time_token_actor_customer ON one_time_token(actor_customer_id)');
        DB::statement('CREATE INDEX idx_one_time_token_actor_staff    ON one_time_token(actor_staff_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_token');
        Schema::dropIfExists('staff_device_fingerprint');
        Schema::dropIfExists('customer_trusted_device');
    }
};
