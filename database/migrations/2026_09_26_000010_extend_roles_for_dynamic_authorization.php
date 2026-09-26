<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff roles become Dashboard-managed data (spec 002, research R1). Spatie's
 * `roles` table gains a display name, a description, and the per-role
 * "requires MFA" flag that replaces the hard-coded role list in config.
 * `name` stays the immutable machine name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('requires_mfa')->default(false);
        });

        DB::table('roles')->update(['display_name' => DB::raw("initcap(replace(name, '_', ' '))")]);
        DB::table('roles')->whereIn('name', ['ceo', 'coo', 'finance'])->update(['requires_mfa' => true]);

        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable(false)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_name_format CHECK (name ~ '^[a-z][a-z0-9_]{2,49}$')");
            DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_guard_staff CHECK (guard_name = 'staff')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_guard_staff');
            DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_name_format');
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'description', 'requires_mfa']);
        });
    }
};
