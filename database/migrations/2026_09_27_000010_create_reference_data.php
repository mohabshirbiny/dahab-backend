<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reference data (spec 004): the operator-editable tables of
 * docs/Database schema/01_schema_core.sql §2 — karats, piece types,
 * branches with weekly hours and full-day closures — and the karat and
 * piece-type seed rows every environment needs (the "data not code" rule:
 * no karat, piece type or branch is ever a literal in code).
 *
 * Branches, hours and holidays are real operating data entered from the
 * Dashboard; LocalReferenceSeeder provides sample ones for development.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `migrate:fresh` drops tables but not types, so clear a leftover one first
        // (same as the identity baseline does for its enum).
        DB::statement('DROP TYPE IF EXISTS piece_category CASCADE');
        DB::statement("CREATE TYPE piece_category AS ENUM ('gold', 'diamond', 'gold_with_diamond')");

        DB::statement('
            CREATE TABLE karat (
                karat_code   SMALLINT PRIMARY KEY,
                purity_ratio NUMERIC(6,5) NOT NULL,
                is_enabled   BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order   SMALLINT NOT NULL,
                CONSTRAINT karat_purity_range CHECK (purity_ratio > 0 AND purity_ratio <= 1),
                CONSTRAINT karat_code_range CHECK (karat_code BETWEEN 1 AND 24)
            )
        ');

        DB::statement('
            CREATE TABLE piece_type (
                piece_type_id  SMALLSERIAL PRIMARY KEY,
                category       piece_category NOT NULL,
                name_en        TEXT NOT NULL,
                name_ar        TEXT NOT NULL,
                typical_min_g  NUMERIC(10,3),
                typical_max_g  NUMERIC(10,3),
                is_enabled     BOOLEAN NOT NULL DEFAULT TRUE,
                UNIQUE (category, name_en),
                CONSTRAINT piece_type_weight_order CHECK (
                    typical_min_g IS NULL OR typical_max_g IS NULL OR typical_min_g <= typical_max_g
                )
            )
        ');

        DB::statement("
            CREATE TABLE branch (
                branch_id    SMALLSERIAL PRIMARY KEY,
                name_en      TEXT NOT NULL,
                name_ar      TEXT NOT NULL,
                address_en   TEXT NOT NULL,
                address_ar   TEXT NOT NULL,
                timezone     TEXT NOT NULL DEFAULT 'Africa/Cairo',
                is_enabled   BOOLEAN NOT NULL DEFAULT TRUE
            )
        ");

        DB::statement('
            CREATE TABLE branch_hours (
                branch_id   SMALLINT NOT NULL REFERENCES branch(branch_id),
                dow         SMALLINT NOT NULL CHECK (dow BETWEEN 0 AND 6),
                opens_at    TIME NOT NULL,
                closes_at   TIME NOT NULL,
                PRIMARY KEY (branch_id, dow, opens_at),
                CONSTRAINT branch_hours_order CHECK (closes_at > opens_at)
            )
        ');

        DB::statement('
            CREATE TABLE branch_closure (
                closure_id   SERIAL PRIMARY KEY,
                branch_id    SMALLINT REFERENCES branch(branch_id),
                closure_date DATE NOT NULL,
                reason_en    TEXT,
                reason_ar    TEXT,
                UNIQUE (branch_id, closure_date)
            )
        ');

        DB::statement('
            INSERT INTO karat (karat_code, purity_ratio, is_enabled, sort_order) VALUES
              (24, 0.99900, TRUE,  1),
              (22, 0.91600, FALSE, 2),
              (21, 0.87500, TRUE,  3),
              (20, 0.83300, FALSE, 4),
              (18, 0.75000, TRUE,  5)
        ');

        DB::statement("
            INSERT INTO piece_type (category, name_en, name_ar, typical_min_g, typical_max_g) VALUES
              ('gold', 'Ring', 'خاتم', 3, 5),
              ('gold', 'Earrings', 'حلق', 3, 6),
              ('gold', 'Chain', 'سلسلة', 8, 15),
              ('gold', 'Bangle', 'غويشة', 15, 30),
              ('gold', 'Pendant', 'دلاية', NULL, NULL),
              ('gold', 'Other', 'أخرى', NULL, NULL),
              ('diamond', 'Ring', 'خاتم', NULL, NULL),
              ('diamond', 'Earrings', 'حلق', NULL, NULL),
              ('diamond', 'Pendant', 'دلاية', NULL, NULL),
              ('diamond', 'Bridal set', 'طقم عروسة', NULL, NULL),
              ('diamond', 'Bracelet', 'أسورة', NULL, NULL),
              ('diamond', 'Other', 'أخرى', NULL, NULL),
              ('gold_with_diamond', 'Ring', 'خاتم', NULL, NULL),
              ('gold_with_diamond', 'Earrings', 'حلق', NULL, NULL),
              ('gold_with_diamond', 'Pendant', 'دلاية', NULL, NULL),
              ('gold_with_diamond', 'Bridal set', 'طقم عروسة', NULL, NULL),
              ('gold_with_diamond', 'Bracelet', 'أسورة', NULL, NULL),
              ('gold_with_diamond', 'Other', 'أخرى', NULL, NULL)
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS branch_closure');
        DB::statement('DROP TABLE IF EXISTS branch_hours');
        DB::statement('DROP TABLE IF EXISTS branch');
        DB::statement('DROP TABLE IF EXISTS piece_type');
        DB::statement('DROP TABLE IF EXISTS karat');
        DB::statement('DROP TYPE IF EXISTS piece_category');
    }
};
