<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A staff member's optional branch (spec 002 FR-041) now references a real
 * branch (spec 004). The schema declares this FK; it was left off until the
 * `branch` table existed. Assignments that point at no branch (development
 * data) are cleared first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE staff SET branch_id = NULL WHERE branch_id IS NOT NULL AND branch_id NOT IN (SELECT branch_id FROM branch)');
        DB::statement('ALTER TABLE staff ADD CONSTRAINT staff_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branch(branch_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE staff DROP CONSTRAINT IF EXISTS staff_branch_id_foreign');
    }
};
