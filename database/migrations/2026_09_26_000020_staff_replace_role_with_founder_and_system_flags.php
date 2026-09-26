<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The fixed `staff.role` enum column is retired (spec 002, research R3):
 * a staff member's roles live only in Spatie's `model_has_roles`, founder
 * status becomes a flag no endpoint writes (FR-043), and `is_system` marks
 * the single non-login actor used by scheduled jobs (FR-060).
 *
 * Existing rows keep their access: each staff member is linked to the Spatie
 * role named like their old enum value before the column is dropped.
 */
return new class extends Migration
{
    private const STAFF_MODEL = 'App\\Models\\Staff';

    private const SEED_ROLES = ['ceo', 'coo', 'finance', 'operations', 'verification', 'igi_branch'];

    public function up(): void
    {
        foreach (DB::table('staff')->select('staff_id', DB::raw('role::text AS role'))->get() as $row) {
            $roleId = DB::table('roles')->where('name', $row->role)->where('guard_name', 'staff')->value('id')
                ?? DB::table('roles')->insertGetId([
                    'name' => $row->role,
                    'guard_name' => 'staff',
                    'display_name' => ucwords(str_replace('_', ' ', $row->role)),
                    'requires_mfa' => in_array($row->role, ['ceo', 'coo', 'finance'], true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $roleId,
                'model_type' => self::STAFF_MODEL,
                'model_id' => $row->staff_id,
            ]);
        }

        DB::statement('ALTER TABLE staff ADD COLUMN is_founder BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement('ALTER TABLE staff ADD COLUMN is_system BOOLEAN NOT NULL DEFAULT FALSE');
        DB::statement("UPDATE staff SET is_founder = TRUE WHERE role IN ('ceo', 'coo')");
        DB::statement('ALTER TABLE staff ADD CONSTRAINT staff_system_not_founder CHECK (NOT (is_system AND is_founder))');
        DB::statement('CREATE UNIQUE INDEX one_system_staff ON staff ((true)) WHERE is_system');

        DB::statement('ALTER TABLE staff DROP CONSTRAINT igi_has_branch');
        DB::statement('ALTER TABLE staff DROP COLUMN role');
        DB::statement('DROP TYPE staff_role');
    }

    public function down(): void
    {
        $roles = "'".implode("','", self::SEED_ROLES)."'";

        DB::statement("CREATE TYPE staff_role AS ENUM ({$roles})");
        DB::statement("ALTER TABLE staff ADD COLUMN role staff_role NOT NULL DEFAULT 'operations'");
        DB::statement("
            UPDATE staff s SET role = sub.name::staff_role
            FROM (
                SELECT DISTINCT ON (mhr.model_id) mhr.model_id, r.name
                FROM model_has_roles mhr
                JOIN roles r ON r.id = mhr.role_id
                WHERE mhr.model_type = ? AND r.name IN ({$roles})
                ORDER BY mhr.model_id, r.name
            ) sub
            WHERE sub.model_id = s.staff_id
        ", [self::STAFF_MODEL]);
        DB::statement('ALTER TABLE staff ALTER COLUMN role DROP DEFAULT');

        $broken = DB::selectOne("SELECT count(*) AS n FROM staff WHERE (role = 'igi_branch') <> (branch_id IS NOT NULL)")->n;
        if ($broken > 0) {
            throw new RuntimeException('staff.role cannot be restored: IGI rows without branch_id (or non-IGI rows with one).');
        }
        DB::statement("ALTER TABLE staff ADD CONSTRAINT igi_has_branch CHECK ((role = 'igi_branch'::staff_role) = (branch_id IS NOT NULL))");

        DB::statement('DROP INDEX IF EXISTS one_system_staff');
        DB::statement('ALTER TABLE staff DROP CONSTRAINT IF EXISTS staff_system_not_founder');
        DB::statement('ALTER TABLE staff DROP COLUMN is_system');
        DB::statement('ALTER TABLE staff DROP COLUMN is_founder');
    }
};
