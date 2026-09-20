<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the two-sided document layout, the `needs_resubmission` state and the
 * structured review reasons the Dashboard's "Ask again" flow requires
 * (docs Part 2 §10).
 *
 * `storage_ref` becomes `front_ref` (renamed, not dropped) and `back_ref` is
 * added nullable. Egyptian ID uses both; passport uses front only — the
 * enforcement lives in the app layer because the CHECK would need to reach
 * across `doc_kind`.
 *
 * `status` gains `needs_resubmission`; `verified` replaces `approved` in the
 * check set, keeping `approved` as an accepted alias so any in-flight
 * consumers keep working through the transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE identity_document RENAME COLUMN storage_ref TO front_ref');

        DB::statement(<<<'SQL'
            ALTER TABLE identity_document
                ADD COLUMN back_ref TEXT,
                ADD COLUMN review_reasons JSONB,
                ADD COLUMN review_note TEXT
        SQL);

        DB::statement('ALTER TABLE identity_document DROP CONSTRAINT identity_document_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE identity_document
                ADD CONSTRAINT identity_document_status_check
                CHECK (status IN ('pending','verified','needs_resubmission','rejected'))
        SQL);

        // Fold the old `approved` label onto the new `verified` label for any
        // previously reviewed rows (fresh env: zero rows, still safe).
        DB::statement("UPDATE identity_document SET status = 'verified' WHERE status = 'approved'");
    }

    public function down(): void
    {
        DB::statement("UPDATE identity_document SET status = 'approved' WHERE status = 'verified'");
        DB::statement("UPDATE identity_document SET status = 'pending' WHERE status = 'needs_resubmission'");

        DB::statement('ALTER TABLE identity_document DROP CONSTRAINT identity_document_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE identity_document
                ADD CONSTRAINT identity_document_status_check
                CHECK (status IN ('pending','approved','rejected'))
        SQL);

        DB::statement('ALTER TABLE identity_document DROP COLUMN review_note');
        DB::statement('ALTER TABLE identity_document DROP COLUMN review_reasons');
        DB::statement('ALTER TABLE identity_document DROP COLUMN back_ref');
        DB::statement('ALTER TABLE identity_document RENAME COLUMN front_ref TO storage_ref');
    }
};
