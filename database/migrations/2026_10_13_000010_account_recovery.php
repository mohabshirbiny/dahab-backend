<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 020 (specs/020-account-recovery-and-staff-access/data-model.md §1).
 *
 * No new table and no new column:
 *  - a password reset opens the same withdrawal pause as a phone or email change (D20-2), so
 *    `withdrawal_pause.trigger_kind` accepts `password_reset`;
 *  - the permission `staff.manage` (create, disable or enable staff) is inserted and given to
 *    the CEO role only (D20-4). Inserting it here means a deploy needs no manual catalogue
 *    sync; the sync stays additive and idempotent (it finds the row and leaves the roles alone).
 */
return new class extends Migration
{
    private const PERMISSION = 'staff.manage';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE withdrawal_pause DROP CONSTRAINT IF EXISTS withdrawal_pause_trigger_kind_check;
            ALTER TABLE withdrawal_pause ADD CONSTRAINT withdrawal_pause_trigger_kind_check
              CHECK (trigger_kind IN ('payout_account','phone_change','email_change','password_reset'));
            SQL);

        DB::statement(
            'INSERT INTO permissions (name, guard_name, created_at, updated_at)
             VALUES (?, ?, now(), now())
             ON CONFLICT (name, guard_name) DO NOTHING',
            [self::PERMISSION, 'staff'],
        );

        // The CEO role, when it already exists (an install that predates this spec). A fresh
        // database gets it from the catalogue sync, which gives the CEO every code.
        DB::statement(
            "INSERT INTO role_has_permissions (permission_id, role_id)
             SELECT p.id, r.id
               FROM permissions p
               JOIN roles r ON r.name = 'ceo' AND r.guard_name = 'staff'
              WHERE p.name = ? AND p.guard_name = 'staff'
             ON CONFLICT DO NOTHING",
            [self::PERMISSION],
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne("SELECT EXISTS (SELECT 1 FROM withdrawal_pause WHERE trigger_kind = 'password_reset') AS used")->used;
        if ($used) {
            throw new RuntimeException('Spec 020 data exists: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE withdrawal_pause DROP CONSTRAINT IF EXISTS withdrawal_pause_trigger_kind_check;
            ALTER TABLE withdrawal_pause ADD CONSTRAINT withdrawal_pause_trigger_kind_check
              CHECK (trigger_kind IN ('payout_account','phone_change','email_change'));
            SQL);

        DB::statement(
            "DELETE FROM role_has_permissions
              WHERE permission_id IN (SELECT id FROM permissions WHERE name = ? AND guard_name = 'staff')",
            [self::PERMISSION],
        );
        DB::statement("DELETE FROM permissions WHERE name = ? AND guard_name = 'staff'", [self::PERMISSION]);
    }
};
