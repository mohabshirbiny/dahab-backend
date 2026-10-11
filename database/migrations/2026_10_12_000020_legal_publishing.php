<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 019, legal publishing (specs/019-app-content/data-model.md §2).
 *
 * A published legal version is evidence of what a customer agreed to, so it never changes:
 * the guard `legal_document_immutable` refuses UPDATE and DELETE (SQLSTATE DH018). A
 * correction is a new version. The index serves "the live version of a code".
 *
 * `agreement_acceptance.context` gains two documented values, `first_acceptance` (D2) and
 * `material_reaccept` (Terms §13); there is no column change and no stored "must accept"
 * flag — the requirement is computed (research R8).
 *
 * The permission `legal.publish` is not a row here: it is a `StaffPermission` code with no
 * seed role (CEO only, D1), created by the catalogue sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE INDEX idx_legal_document_code_version ON legal_document (code, version DESC);

            CREATE OR REPLACE FUNCTION legal_document_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              -- The only exception: an earlier migration's own rollback may remove the first version
              -- of a declaration it seeded, if nobody has accepted it.
              IF TG_OP = 'DELETE' AND OLD.version = 1
                 AND OLD.code IN ('ownership_declaration','deposit_agreement','payout_account_declaration','collection_proxy_authorisation')
                 AND NOT EXISTS (SELECT 1 FROM agreement_acceptance a WHERE a.legal_doc_id = OLD.legal_doc_id) THEN
                RETURN OLD;
              END IF;
              RAISE EXCEPTION 'a published legal version never changes (%, v%)', OLD.code, OLD.version
                USING ERRCODE = 'DH018';
            END $$;
            CREATE TRIGGER trg_legal_document_immutable BEFORE UPDATE OR DELETE ON legal_document
              FOR EACH ROW EXECUTE FUNCTION legal_document_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Anything published after the seeded first versions is a customer-facing record (R16).
        $used = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
              SELECT 1 FROM legal_document
               WHERE version > 1 OR code NOT IN
                     ('ownership_declaration','deposit_agreement','payout_account_declaration','collection_proxy_authorisation')
            ) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 019 legal versions exist: this migration cannot be rolled back.');
        }

        // The trigger goes first, so earlier migrations' down() can delete their seed rows.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_legal_document_immutable ON legal_document;
            DROP FUNCTION IF EXISTS legal_document_immutable();
            DROP INDEX IF EXISTS idx_legal_document_code_version;
            SQL);
    }
};
