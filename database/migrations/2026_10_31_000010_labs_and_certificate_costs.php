<?php

use App\Support\DatabaseActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Labs (docs/features/labs-and-certificate-costs.md): a lab holds its three certificate costs and
 * its gold inspection cost, and every branch belongs to one. IGI becomes the first lab with the
 * values of the certificate.* settings, which end here; every branch, listing and order so far is
 * IGI's. A listing names the lab that inspects it and the lab behind its certificate; an order
 * keeps its lab and what inspection found. Nothing posted changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE lab (
              lab_id BIGSERIAL PRIMARY KEY,
              name_en TEXT NOT NULL,
              name_ar TEXT NOT NULL,
              certificate_own_cost_egp NUMERIC(18,4) NOT NULL DEFAULT 0 CHECK (certificate_own_cost_egp >= 0),
              certificate_other_cost_egp NUMERIC(18,4) NOT NULL DEFAULT 0 CHECK (certificate_other_cost_egp >= 0),
              certificate_none_cost_egp NUMERIC(18,4) NOT NULL DEFAULT 0 CHECK (certificate_none_cost_egp >= 0),
              gold_inspection_cost_egp NUMERIC(18,4) NOT NULL DEFAULT 0 CHECK (gold_inspection_cost_egp >= 0),
              is_enabled BOOLEAN NOT NULL DEFAULT TRUE
            );

            INSERT INTO lab (name_en, name_ar, certificate_own_cost_egp, certificate_other_cost_egp, certificate_none_cost_egp)
            VALUES ('IGI', 'IGI',
              COALESCE((SELECT value_numeric FROM setting WHERE setting_key = 'certificate.igi_cost_egp'), 0),
              COALESCE((SELECT value_numeric FROM setting WHERE setting_key = 'certificate.other_lab_cost_egp'), 0),
              COALESCE((SELECT value_numeric FROM setting WHERE setting_key = 'certificate.none_cost_egp'), 0));

            ALTER TABLE branch ADD COLUMN lab_id BIGINT REFERENCES lab (lab_id);
            UPDATE branch SET lab_id = (SELECT MIN(lab_id) FROM lab);
            ALTER TABLE branch ALTER COLUMN lab_id SET NOT NULL;
            CREATE INDEX idx_branch_lab ON branch (lab_id);

            ALTER TABLE listing DROP CONSTRAINT IF EXISTS listing_certificate_kind_check;
            ALTER TABLE listing
              ADD COLUMN lab_id BIGINT REFERENCES lab (lab_id),
              ADD COLUMN certificate_lab_id BIGINT REFERENCES lab (lab_id),
              ADD COLUMN certificate_other_lab_name TEXT;
            CREATE INDEX idx_listing_lab ON listing (lab_id);

            ALTER TABLE "order"
              ADD COLUMN lab_id BIGINT REFERENCES lab (lab_id),
              ADD COLUMN gold_inspection_cost NUMERIC(18,4) CHECK (gold_inspection_cost IS NULL OR gold_inspection_cost >= 0),
              ADD COLUMN certificate_kind_found TEXT CHECK (certificate_kind_found IN ('lab', 'other_lab', 'none'));
            CREATE INDEX idx_order_lab ON "order" (lab_id);

            ALTER TABLE inspection_result
              ADD COLUMN certificate_kind_found TEXT CHECK (certificate_kind_found IN ('lab', 'other_lab', 'none')),
              ADD COLUMN certificate_lab_id_found BIGINT REFERENCES lab (lab_id);
        SQL);

        DatabaseActor::elevate('maintenance', function () {
            DB::update(<<<'SQL'
                UPDATE listing SET lab_id = (SELECT MIN(lab_id) FROM lab),
                  certificate_lab_id = CASE WHEN certificate_kind = 'igi' THEN (SELECT MIN(lab_id) FROM lab) END,
                  certificate_kind = CASE WHEN certificate_kind = 'igi' THEN 'lab' ELSE certificate_kind END
            SQL);
            DB::update('UPDATE "order" SET lab_id = (SELECT MIN(lab_id) FROM lab)');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE listing ADD CONSTRAINT listing_certificate_kind_check
              CHECK (certificate_kind IN ('lab', 'other_lab', 'none'));
            DELETE FROM setting WHERE setting_key IN
              ('certificate.igi_cost_egp', 'certificate.other_lab_cost_egp', 'certificate.none_cost_egp');
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO setting (setting_key, value_numeric, unit, description)
            SELECT v.k, v.n, 'egp', v.d FROM (SELECT * FROM lab ORDER BY lab_id LIMIT 1) l, LATERAL (VALUES
              ('certificate.igi_cost_egp', l.certificate_own_cost_egp, 'IGI certificate cost when the seller brings an IGI certificate'),
              ('certificate.other_lab_cost_egp', l.certificate_other_cost_egp, 'IGI certificate cost when the seller brings another lab''s certificate'),
              ('certificate.none_cost_egp', l.certificate_none_cost_egp, 'IGI certificate cost when the seller brings no certificate')
            ) AS v(k, n, d);
            ALTER TABLE listing DROP CONSTRAINT IF EXISTS listing_certificate_kind_check;
        SQL);

        DatabaseActor::elevate('maintenance', fn () => DB::update(
            "UPDATE listing SET certificate_kind = 'igi' WHERE certificate_kind = 'lab'",
        ));

        DB::unprepared(<<<'SQL'
            ALTER TABLE listing ADD CONSTRAINT listing_certificate_kind_check CHECK (certificate_kind IN ('igi', 'other_lab', 'none'));
            ALTER TABLE inspection_result DROP COLUMN certificate_lab_id_found, DROP COLUMN certificate_kind_found;
            ALTER TABLE "order" DROP COLUMN certificate_kind_found, DROP COLUMN gold_inspection_cost, DROP COLUMN lab_id;
            ALTER TABLE listing DROP COLUMN certificate_other_lab_name, DROP COLUMN certificate_lab_id, DROP COLUMN lab_id;
            ALTER TABLE branch DROP COLUMN lab_id;
            DROP TABLE lab;
        SQL);
    }
};
