<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 019, phases 6 and 7 (specs/019-app-content/data-model.md §1).
 *
 * D7: a message that carries a one-time code or a link may be edited by a holder of the
 * permission `content.edit_secure` (no seeded role). The database guard used to refuse any new
 * version of a read-only key; that rule is now the application's (SaveDraftAction), because
 * it depends on who is asking. Everything else in the guard — history is immutable (DH017)
 * — is unchanged.
 *
 * FAQ order: a reorder is a change like any other, so it waits for *Publish changes*.
 * `pending_position` holds the order a draft reorder asked for; publishing moves it into
 * `position` and the bundle serves `position`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE app_text_key ADD COLUMN pending_position INTEGER;

            CREATE OR REPLACE FUNCTION app_text_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
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
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE app_text_key DROP COLUMN IF EXISTS pending_position;

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
            SQL);
    }
};
