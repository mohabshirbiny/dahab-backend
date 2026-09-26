<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The single "System" actor that scheduled jobs attribute their writes to
 * (spec 002 FR-060, Part 2 §11). Created by migration, not a seeder, so it
 * exists in every environment. It has no password and no roles, so it can
 * never sign in and holds no permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('staff')->where('is_system', true)->exists()) {
            return;
        }

        DB::table('staff')->insert([
            'full_name' => 'System',
            'email' => 'system@dahab.internal',
            'is_active' => true,
            'is_founder' => false,
            'is_system' => true,
        ]);
    }

    public function down(): void
    {
        $id = DB::table('staff')->where('is_system', true)->value('staff_id');

        if ($id === null) {
            return;
        }

        $referenced = DB::table('audit_log')->where('actor_staff_id', $id)->exists();
        if ($referenced) {
            throw new RuntimeException('The system actor is referenced by audit_log rows and cannot be removed.');
        }

        DB::table('staff')->where('staff_id', $id)->delete();
    }
};
