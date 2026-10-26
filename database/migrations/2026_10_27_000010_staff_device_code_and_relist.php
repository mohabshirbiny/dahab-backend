<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings page and relist (docs/features/settings-page-and-relist.md).
 *
 * - `auth.staff_new_device_sms`: a staff member signing in on a device not yet known for them
 *   confirms it with a code sent by SMS to their staff phone; a founder's first device too
 *   (instead of `founder:trust-device`). On by default. Off: the spec 021 behaviour only.
 * - `withdrawn → live`: a piece taken down off the market (by its seller or by staff) is
 *   brought back. Only a withdrawal from live or reserved qualifies; the action checks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO setting (setting_key, value_bool, unit, description) VALUES
              ('auth.staff_new_device_sms', TRUE, 'bool', 'A staff member on a new device confirms it with a code sent by SMS')
        ");

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            INSERT INTO listing_transition (from_state, to_state, note) VALUES
              ('withdrawn', 'live', 'a piece taken down off the market brought back')
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM setting WHERE setting_key = 'auth.staff_new_device_sms'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("DELETE FROM listing_transition WHERE from_state = 'withdrawn' AND to_state = 'live'");
        }
    }
};
