<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE customer_password (
                customer_id         UUID PRIMARY KEY REFERENCES customer(customer_id) ON DELETE CASCADE,
                password_hash       TEXT NOT NULL,
                password_changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ');

        DB::statement('
            CREATE TABLE staff_password (
                staff_id              UUID PRIMARY KEY REFERENCES staff(staff_id) ON DELETE CASCADE,
                password_hash         TEXT NOT NULL,
                password_changed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                force_reenroll_mfa_at TIMESTAMPTZ
            )
        ');

        DB::statement('
            CREATE TABLE staff_mfa (
                staff_id             UUID PRIMARY KEY REFERENCES staff(staff_id) ON DELETE CASCADE,
                mfa_secret_encrypted TEXT NOT NULL,
                enrolled_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                recovery_codes_hash  JSONB
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_mfa');
        Schema::dropIfExists('staff_password');
        Schema::dropIfExists('customer_password');
    }
};
