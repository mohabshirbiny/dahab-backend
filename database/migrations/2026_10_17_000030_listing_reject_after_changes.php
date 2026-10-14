<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Testing fixes, item 8: staff may reject, for good, a listing that is waiting for the
 * seller's changes (manual only, no expiry). The guard functions read listing_transition,
 * so one row is the whole change; listing_state_change already requires a note for
 * `to_state = 'rejected'`, and `rejected` stays final (no outgoing row).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO listing_transition (from_state, to_state, note)
            VALUES ('changes_requested', 'rejected', 'rejected while waiting for the seller''s changes')
            ON CONFLICT (from_state, to_state) DO NOTHING
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM listing_transition WHERE from_state = 'changes_requested' AND to_state = 'rejected'");
    }
};
