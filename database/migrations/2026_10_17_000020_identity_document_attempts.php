<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Testing fixes, item 2: every identity upload is kept as its own attempt.
 *
 * - `attempt_number` (1, 2, 3 …) per customer, unique, backfilled in upload order.
 * - New final status `superseded`: an "Ask again" document the customer has answered with
 *   a new attempt. It keeps its images, reasons and reviewer; only the new row is open.
 * - At most one open document (pending or needs_resubmission) per customer, enforced here
 *   as well as in SubmitIdentityDocumentAction.
 *
 * Forced RLS on identity_document (2026_09_26_000040) is unchanged: no new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE identity_document ADD COLUMN attempt_number INTEGER;

            UPDATE identity_document d
               SET attempt_number = n.rn
              FROM (SELECT document_id,
                           ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at, document_id) AS rn
                      FROM identity_document) n
             WHERE n.document_id = d.document_id;

            ALTER TABLE identity_document
                ALTER COLUMN attempt_number SET NOT NULL,
                ADD CONSTRAINT identity_document_attempt_positive CHECK (attempt_number >= 1),
                ADD CONSTRAINT identity_document_attempt_unique UNIQUE (customer_id, attempt_number);

            ALTER TABLE identity_document DROP CONSTRAINT identity_document_status_check;
            ALTER TABLE identity_document
                ADD CONSTRAINT identity_document_status_check
                CHECK (status IN ('pending','verified','needs_resubmission','rejected','superseded'));

            CREATE UNIQUE INDEX identity_document_one_open
                ON identity_document (customer_id)
                WHERE status IN ('pending','needs_resubmission');

            -- A new row takes the next number for its customer (registration, resubmission,
            -- factories). Writers lock the customer row first; the UNIQUE constraint backs it.
            CREATE OR REPLACE FUNCTION identity_document_next_attempt() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.attempt_number IS NULL THEN
                SELECT COALESCE(MAX(attempt_number), 0) + 1 INTO NEW.attempt_number
                  FROM identity_document WHERE customer_id = NEW.customer_id;
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_identity_document_next_attempt
                BEFORE INSERT ON identity_document
                FOR EACH ROW EXECUTE FUNCTION identity_document_next_attempt();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_identity_document_next_attempt ON identity_document;
            DROP FUNCTION IF EXISTS identity_document_next_attempt();
            DROP INDEX IF EXISTS identity_document_one_open;
            UPDATE identity_document SET status = 'needs_resubmission' WHERE status = 'superseded';
            ALTER TABLE identity_document DROP CONSTRAINT identity_document_status_check;
            ALTER TABLE identity_document
                ADD CONSTRAINT identity_document_status_check
                CHECK (status IN ('pending','verified','needs_resubmission','rejected'));
            ALTER TABLE identity_document DROP CONSTRAINT identity_document_attempt_unique;
            ALTER TABLE identity_document DROP CONSTRAINT identity_document_attempt_positive;
            ALTER TABLE identity_document DROP COLUMN attempt_number;
        SQL);
    }
};
