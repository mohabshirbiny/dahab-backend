<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function ($table) {
            $table->uuid('family_id')->nullable()->index();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('actor_kind', 16)->default('customer');
        });

        DB::statement("ALTER TABLE personal_access_tokens
            ADD CONSTRAINT personal_access_tokens_actor_kind_check
            CHECK (actor_kind IN ('customer','staff'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE personal_access_tokens DROP CONSTRAINT IF EXISTS personal_access_tokens_actor_kind_check');
        Schema::table('personal_access_tokens', function ($table) {
            $table->dropIndex(['family_id']);
            $table->dropColumn(['family_id', 'rotated_at', 'revoked_at', 'actor_kind']);
        });
    }
};
