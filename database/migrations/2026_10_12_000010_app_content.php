<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 019, first release — app text (specs/019-app-content/data-model.md §1).
 *
 * A registry of the customer app's text keys, their versions (one draft per key, then
 * published, then superseded) and one row per *Publish changes*. The content tables hold no
 * customer data, so they have no row-level security; they are written by staff actions and
 * by the `app-texts:sync` command (system actor) only.
 *
 * Guard: DH017 (`content_history_immutable`) — a published version never changes except to
 * become superseded; a superseded one never changes; history is never deleted; a bundle
 * row never changes; a read-only key (a template carrying a code or a link, D7) takes no
 * new version.
 *
 * The permission `content.edit` is not created here: permission rows follow the
 * `StaffPermission` catalogue (SyncPermissionCatalogueAction gives a new code to the CEO
 * and its seed roles the first time it appears), as for every code since spec 002.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- One row per publish: the bundle the customer app downloads (R3). Numbered by the
            -- publish itself (highest + 1, under its advisory lock), so numbers are consecutive.
            CREATE TABLE app_text_bundle (
              bundle_version INTEGER PRIMARY KEY CHECK (bundle_version > 0),
              published_by   UUID NOT NULL REFERENCES staff(staff_id),
              published_at   TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              key_count      INTEGER NOT NULL CHECK (key_count > 0),
              kinds          TEXT[] NOT NULL
            );

            -- The registry: one row per text the apps show or send. Keys are append-only (Q1):
            -- a key leaving the source gets `retired_at`, it is never deleted.
            CREATE TABLE app_text_key (
              text_key              TEXT PRIMARY KEY
                                    CHECK (text_key ~ '^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,5}$'),
              kind                  TEXT NOT NULL CHECK (kind IN ('app','faq','notification')),
              area                  TEXT NOT NULL,
              description           TEXT NOT NULL,
              default_en            TEXT NOT NULL,
              default_ar            TEXT NOT NULL,
              default_changed_at    TIMESTAMPTZ,
              placeholders          TEXT[] NOT NULL DEFAULT '{}',
              required_placeholders TEXT[] NOT NULL DEFAULT '{}',
              read_only             BOOLEAN NOT NULL DEFAULT FALSE,
              max_length            INTEGER NOT NULL CHECK (max_length > 0),
              inbox                 BOOLEAN NOT NULL DEFAULT TRUE,
              position              INTEGER,
              retired_at            TIMESTAMPTZ,
              synced_at             TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_app_text_key_list ON app_text_key (kind, area, text_key);

            -- Versions. A discarded draft is deleted, so `version` may skip a number.
            CREATE TABLE app_text_version (
              version_id        BIGSERIAL PRIMARY KEY,
              text_key          TEXT NOT NULL REFERENCES app_text_key(text_key),
              version           INTEGER NOT NULL CHECK (version > 0),
              state             TEXT NOT NULL CHECK (state IN ('draft','published','superseded')),
              is_default_marker BOOLEAN NOT NULL DEFAULT FALSE,
              text_en           TEXT NOT NULL DEFAULT '',
              text_ar           TEXT NOT NULL DEFAULT '',
              is_hidden         BOOLEAN NOT NULL DEFAULT FALSE,
              draft_version     INTEGER NOT NULL DEFAULT 1,
              edited_by         UUID NOT NULL REFERENCES staff(staff_id),
              edited_at         TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              published_by      UUID REFERENCES staff(staff_id),
              published_at      TIMESTAMPTZ,
              bundle_version    INTEGER REFERENCES app_text_bundle(bundle_version),
              CONSTRAINT app_text_version_unique UNIQUE (text_key, version),
              -- Both languages, unless this version only ends the override (R2).
              CONSTRAINT app_text_version_texts CHECK (
                (is_default_marker AND text_en = '' AND text_ar = '')
                OR (NOT is_default_marker AND char_length(btrim(text_en)) > 0 AND char_length(btrim(text_ar)) > 0)),
              -- Who published and when is recorded once a version leaves draft (Principle I).
              CONSTRAINT app_text_version_published CHECK (
                (state = 'draft' AND published_by IS NULL AND published_at IS NULL AND bundle_version IS NULL)
                OR (state <> 'draft' AND published_by IS NOT NULL AND published_at IS NOT NULL AND bundle_version IS NOT NULL))
            );
            CREATE UNIQUE INDEX uq_app_text_one_draft     ON app_text_version (text_key) WHERE state = 'draft';
            CREATE UNIQUE INDEX uq_app_text_one_published ON app_text_version (text_key) WHERE state = 'published';
            CREATE INDEX idx_app_text_version_history ON app_text_version (text_key, version DESC);
            CREATE INDEX idx_app_text_version_bundle  ON app_text_version (bundle_version) WHERE bundle_version IS NOT NULL;

            -- History is immutable (DH017). A draft may be edited or discarded and may become
            -- published; a published row may only become superseded; a superseded row never
            -- changes; nothing that was published is deleted; a key that is read-only takes no
            -- new version at all (D7).
            CREATE OR REPLACE FUNCTION app_text_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_read_only BOOLEAN;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                SELECT read_only INTO v_read_only FROM app_text_key WHERE text_key = NEW.text_key;
                IF v_read_only THEN
                  RAISE EXCEPTION 'text key % is read-only', NEW.text_key USING ERRCODE = 'DH017';
                END IF;
                IF NEW.state <> 'draft' THEN
                  RAISE EXCEPTION 'a text version starts as a draft, not %', NEW.state USING ERRCODE = 'DH017';
                END IF;
                RETURN NEW;
              END IF;

              IF TG_OP = 'DELETE' THEN
                IF OLD.state <> 'draft' THEN
                  RAISE EXCEPTION 'published text history is never deleted (% v%)', OLD.text_key, OLD.version USING ERRCODE = 'DH017';
                END IF;
                RETURN OLD;
              END IF;

              IF NEW.version_id IS DISTINCT FROM OLD.version_id OR NEW.text_key IS DISTINCT FROM OLD.text_key
                 OR NEW.version IS DISTINCT FROM OLD.version THEN
                RAISE EXCEPTION 'text version identity cannot change (% v%)', OLD.text_key, OLD.version USING ERRCODE = 'DH017';
              END IF;

              IF OLD.state = 'superseded' THEN
                RAISE EXCEPTION 'a superseded text version never changes (% v%)', OLD.text_key, OLD.version USING ERRCODE = 'DH017';
              END IF;

              IF OLD.state = 'published' THEN
                IF NEW.state <> 'superseded'
                   OR NEW.is_default_marker IS DISTINCT FROM OLD.is_default_marker
                   OR NEW.text_en IS DISTINCT FROM OLD.text_en OR NEW.text_ar IS DISTINCT FROM OLD.text_ar
                   OR NEW.is_hidden IS DISTINCT FROM OLD.is_hidden
                   OR NEW.edited_by IS DISTINCT FROM OLD.edited_by OR NEW.edited_at IS DISTINCT FROM OLD.edited_at
                   OR NEW.published_by IS DISTINCT FROM OLD.published_by OR NEW.published_at IS DISTINCT FROM OLD.published_at
                   OR NEW.bundle_version IS DISTINCT FROM OLD.bundle_version THEN
                  RAISE EXCEPTION 'a published text version only becomes superseded (% v%)', OLD.text_key, OLD.version USING ERRCODE = 'DH017';
                END IF;
                RETURN NEW;
              END IF;

              -- OLD.state = 'draft'
              IF NEW.state = 'superseded' THEN
                RAISE EXCEPTION 'a draft is published or discarded, not superseded (% v%)', OLD.text_key, OLD.version USING ERRCODE = 'DH017';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_app_text_version_guard BEFORE INSERT OR UPDATE OR DELETE ON app_text_version
              FOR EACH ROW EXECUTE FUNCTION app_text_version_guard();

            CREATE OR REPLACE FUNCTION app_text_bundle_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'a published bundle never changes' USING ERRCODE = 'DH017';
            END $$;
            CREATE TRIGGER trg_app_text_bundle_immutable BEFORE UPDATE OR DELETE ON app_text_bundle
              FOR EACH ROW EXECUTE FUNCTION app_text_bundle_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Published history is the point of this table: rolling back would destroy it (R16).
        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM app_text_version WHERE state <> 'draft')
                 OR EXISTS (SELECT 1 FROM app_text_bundle)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 019 app text has published versions: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_app_text_bundle_immutable ON app_text_bundle;
            DROP TRIGGER IF EXISTS trg_app_text_version_guard ON app_text_version;
            DROP TABLE IF EXISTS app_text_version;
            DROP TABLE IF EXISTS app_text_key;
            DROP TABLE IF EXISTS app_text_bundle;
            DROP FUNCTION IF EXISTS app_text_bundle_immutable();
            DROP FUNCTION IF EXISTS app_text_version_guard();
            SQL);
    }
};
