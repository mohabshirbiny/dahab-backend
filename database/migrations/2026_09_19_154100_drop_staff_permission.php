<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard authorization moved to spatie/laravel-permission
 * (see 2026_09_19_154050_create_permission_tables). The custom
 * role -> permission matrix table is retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('staff_permission');
    }

    public function down(): void
    {
        DB::statement('
            CREATE TABLE staff_permission (
                role            staff_role NOT NULL,
                permission_code TEXT       NOT NULL,
                granted         BOOLEAN    NOT NULL DEFAULT TRUE,
                PRIMARY KEY (role, permission_code)
            )
        ');
    }
};
