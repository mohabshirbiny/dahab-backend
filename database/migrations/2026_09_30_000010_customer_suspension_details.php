<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suspension details on the customer row (spec 007 research R3/R5,
 * docs/Database schema/02_schema_identity.sql).
 *
 * - `suspended_note`: the staff note, never shown to the customer.
 * - `status_before_suspension`: the state a suspension interrupted;
 *   reinstating returns to exactly that state.
 * - The suspension reason is one of the design's seven codes. Nothing could
 *   suspend before this feature, but old codes (seeders, factories) are
 *   remapped defensively.
 *
 * down(): the three 1:1 codes map back; `staff_request` became `other` and
 * stays `other` (lossy, noted). Codes new in this feature have no old
 * equivalent — roll back only before any are recorded.
 */
return new class extends Migration
{
    /** @var array<string, string> old => new */
    private const REMAP = [
        'fraud_suspected' => 'piece_misrepresented',
        'policy_violation' => 'off_platform_dealing',
        'kyc_failed' => 'identity_unconfirmed',
        'staff_request' => 'other',
    ];

    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE customer
                ADD COLUMN suspended_note TEXT,
                ADD COLUMN status_before_suspension TEXT
                    CHECK (status_before_suspension IN ('pending_verification','active','rejected'))
        SQL);

        // Rows suspended before this feature were verified (the only path in).
        DB::statement("UPDATE customer SET status_before_suspension = 'active' WHERE status = 'suspended'");

        foreach (self::REMAP as $old => $new) {
            DB::table('customer')->where('suspended_reason', $old)->update(['suspended_reason' => $new]);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE customer
                ADD CONSTRAINT customer_suspension_state CHECK (
                    (status = 'suspended') = (status_before_suspension IS NOT NULL)
                ),
                ADD CONSTRAINT customer_suspended_reason_check CHECK (
                    suspended_reason IS NULL OR suspended_reason IN (
                        'piece_misrepresented','off_platform_dealing','repeated_disputes',
                        'reported_by_users','identity_unconfirmed','customer_request','other')
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer DROP CONSTRAINT IF EXISTS customer_suspended_reason_check');
        DB::statement('ALTER TABLE customer DROP CONSTRAINT IF EXISTS customer_suspension_state');

        foreach (self::REMAP as $old => $new) {
            if ($new !== 'other') {
                DB::table('customer')->where('suspended_reason', $new)->update(['suspended_reason' => $old]);
            }
        }

        DB::statement('ALTER TABLE customer DROP COLUMN IF EXISTS status_before_suspension');
        DB::statement('ALTER TABLE customer DROP COLUMN IF EXISTS suspended_note');
    }
};
