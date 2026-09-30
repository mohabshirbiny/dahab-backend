<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Versioned legal documents and the record of each acceptance (spec 010).
 * Mirrors section 6 of docs/Database schema/02_schema_identity.sql and its
 * "As built by spec 010" block: `agreement_acceptance` is append-only and
 * under forced row-level security; `ownership_declaration` v1 is seeded,
 * published by the system actor.
 *
 * Rollback drops both tables, acceptances included.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE legal_document (
              legal_doc_id  SMALLSERIAL PRIMARY KEY,
              code          TEXT NOT NULL,                   -- 'terms','privacy','collection_auth'...
              version       INTEGER NOT NULL,
              body_en       TEXT NOT NULL,
              body_ar       TEXT NOT NULL,
              is_material   BOOLEAN NOT NULL DEFAULT FALSE,  -- material change forces re-accept
              published_by  UUID NOT NULL REFERENCES staff(staff_id),
              published_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
              UNIQUE (code, version)
            );

            -- Immutable record of every acceptance. This is the evidence trail.
            CREATE TABLE agreement_acceptance (
              acceptance_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              customer_id   UUID NOT NULL REFERENCES customer(customer_id),
              legal_doc_id  SMALLINT NOT NULL REFERENCES legal_document(legal_doc_id),
              -- Context of the tick: 'signup','list_piece','buy_request','payout_account',
              -- 'collection_proxy','first_sale_offer'... one row per tick.
              context       TEXT NOT NULL,
              accepted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              ip_address    INET,
              device_fingerprint TEXT
            );

            -- As built by spec 010 ----------------------------------------------------
            -- Both tables are created unchanged. An acceptance is evidence: append-only.
            CREATE OR REPLACE FUNCTION agreement_acceptance_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'agreement acceptances are append-only';
            END $$;

            CREATE TRIGGER trg_agreement_acceptance_immutable
              BEFORE UPDATE OR DELETE ON agreement_acceptance
              FOR EACH ROW EXECUTE FUNCTION agreement_acceptance_immutable();

            CREATE INDEX idx_agreement_acceptance_customer ON agreement_acceptance(customer_id, accepted_at);

            -- A customer sees only their own acceptances (forced RLS, spec 003 pattern).
            ALTER TABLE agreement_acceptance ENABLE ROW LEVEL SECURITY;
            ALTER TABLE agreement_acceptance FORCE  ROW LEVEL SECURITY;
            CREATE POLICY agreement_acceptance_isolation ON agreement_acceptance FOR ALL
              USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
              WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

            -- Seed: the ownership declaration ticked when listing a piece, version 1,
            -- published by the system actor (no Dashboard document management yet).
            INSERT INTO legal_document (code, version, body_en, body_ar, is_material, published_by)
            SELECT 'ownership_declaration', 1,
                   'I confirm this piece is mine to sell and the details above are accurate.',
                   'أقر أن القطعة دي ملكي ومن حقي أبيعها، وأن البيانات اللي فوق صحيحة.',
                   FALSE, staff_id
            FROM staff WHERE is_system = TRUE;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS agreement_acceptance;
            DROP FUNCTION IF EXISTS agreement_acceptance_immutable();
            DROP TABLE IF EXISTS legal_document;
        SQL);
    }
};
