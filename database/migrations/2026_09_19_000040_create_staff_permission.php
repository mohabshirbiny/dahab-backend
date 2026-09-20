<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('staff_permission');
    }
};
