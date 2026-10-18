<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 026 (staff work notices, research R2): a work notice is grouped per staff member and
 * queue while unread. `group_key` names the open group (NULL for founder alerts, which are
 * never grouped) and `item_count` how many events it holds; the partial unique index allows
 * one unread group per staff member and key, so a read notice leaves the next event to start
 * a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE staff_notice
              ADD COLUMN group_key  TEXT,
              ADD COLUMN item_count INT NOT NULL DEFAULT 1 CHECK (item_count >= 1);

            CREATE UNIQUE INDEX uq_staff_notice_open_group ON staff_notice(staff_id, group_key)
              WHERE read_at IS NULL AND group_key IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS uq_staff_notice_open_group;
            ALTER TABLE staff_notice DROP COLUMN IF EXISTS item_count, DROP COLUMN IF EXISTS group_key;
            SQL);
    }
};
