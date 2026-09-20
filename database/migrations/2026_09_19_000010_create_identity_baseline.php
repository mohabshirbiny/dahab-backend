<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement('DROP TYPE IF EXISTS staff_role CASCADE');
        DB::statement("CREATE TYPE staff_role AS ENUM ('ceo','coo','finance','operations','verification','igi_branch')");

        DB::statement("
            CREATE TABLE staff (
                staff_id    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                role        staff_role NOT NULL,
                full_name   TEXT NOT NULL,
                email       CITEXT UNIQUE NOT NULL,
                phone       TEXT,
                is_active   BOOLEAN NOT NULL DEFAULT TRUE,
                branch_id   SMALLINT,
                created_by  UUID REFERENCES staff(staff_id),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT igi_has_branch CHECK (
                    (role = 'igi_branch'::staff_role) = (branch_id IS NOT NULL)
                )
            )
        ");

        DB::statement("
            CREATE TABLE customer (
                customer_id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                display_ref      TEXT UNIQUE NOT NULL,
                phone            TEXT UNIQUE NOT NULL,
                email            CITEXT UNIQUE,
                email_verified_at TIMESTAMPTZ,
                full_name        TEXT,
                preferred_lang   TEXT NOT NULL DEFAULT 'ar' CHECK (preferred_lang IN ('ar','en')),
                is_verified      BOOLEAN NOT NULL DEFAULT FALSE,
                is_suspended     BOOLEAN NOT NULL DEFAULT FALSE,
                suspended_reason TEXT,
                suspended_by     UUID REFERENCES staff(staff_id),
                suspended_at     TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT suspended_needs_actor CHECK (
                    NOT is_suspended OR (suspended_by IS NOT NULL AND suspended_reason IS NOT NULL)
                )
            )
        ");

        DB::statement('
            CREATE TABLE account_freeze (
                freeze_id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                frozen_staff_id    UUID NOT NULL REFERENCES staff(staff_id),
                frozen_by          UUID NOT NULL REFERENCES staff(staff_id),
                frozen_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                unfreeze_confirm_1 UUID REFERENCES staff(staff_id),
                unfreeze_confirm_2 UUID REFERENCES staff(staff_id),
                unfrozen_at        TIMESTAMPTZ,
                CONSTRAINT no_self_freeze CHECK (frozen_by <> frozen_staff_id)
            )
        ');

        DB::statement('
            CREATE TABLE founder_device_approval (
                approval_id        UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                staff_id           UUID NOT NULL REFERENCES staff(staff_id),
                device_fingerprint TEXT NOT NULL,
                requested_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                approved_by        UUID REFERENCES staff(staff_id),
                approved_at        TIMESTAMPTZ,
                CONSTRAINT approver_is_not_self CHECK (approved_by IS NULL OR approved_by <> staff_id)
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('founder_device_approval');
        Schema::dropIfExists('account_freeze');
        Schema::dropIfExists('customer');
        Schema::dropIfExists('staff');
        DB::statement('DROP TYPE IF EXISTS staff_role');
    }
};
