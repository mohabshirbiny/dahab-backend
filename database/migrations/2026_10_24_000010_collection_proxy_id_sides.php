<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Handover ID check (docs/features/handover-id-check.md): the proxy's ID is uploaded like the
 * sign-up one — its kind, the front (or passport photo page) in `proxy_id_storage_ref`, and the
 * back here. An Egyptian ID needs its back; rows named before keep a null kind. Both columns join
 * the spec 014 freeze once the piece is collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE collection
              ADD COLUMN proxy_id_kind TEXT CHECK (proxy_id_kind IN ('egyptian_id', 'passport')),
              ADD COLUMN proxy_id_back_storage_ref TEXT,
              ADD CONSTRAINT proxy_egyptian_id_has_back CHECK (
                proxy_id_kind IS DISTINCT FROM 'egyptian_id' OR proxy_id_back_storage_ref IS NOT NULL);

            CREATE OR REPLACE FUNCTION collection_proxy_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.collected_at IS NOT NULL AND (
                   NEW.is_proxy IS DISTINCT FROM OLD.is_proxy OR NEW.proxy_name IS DISTINCT FROM OLD.proxy_name
                   OR NEW.proxy_phone IS DISTINCT FROM OLD.proxy_phone OR NEW.proxy_id_storage_ref IS DISTINCT FROM OLD.proxy_id_storage_ref
                   OR NEW.proxy_id_kind IS DISTINCT FROM OLD.proxy_id_kind
                   OR NEW.proxy_id_back_storage_ref IS DISTINCT FROM OLD.proxy_id_back_storage_ref
                   OR NEW.proxy_acceptance_id IS DISTINCT FROM OLD.proxy_acceptance_id
                   OR NEW.collected_by_proxy IS DISTINCT FROM OLD.collected_by_proxy
                   OR NEW.proxy_id_checked_by IS DISTINCT FROM OLD.proxy_id_checked_by) THEN
                RAISE EXCEPTION 'the proxy of a collected piece cannot change' USING ERRCODE = 'DH006';
              END IF;
              RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION collection_proxy_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.collected_at IS NOT NULL AND (
                   NEW.is_proxy IS DISTINCT FROM OLD.is_proxy OR NEW.proxy_name IS DISTINCT FROM OLD.proxy_name
                   OR NEW.proxy_phone IS DISTINCT FROM OLD.proxy_phone OR NEW.proxy_id_storage_ref IS DISTINCT FROM OLD.proxy_id_storage_ref
                   OR NEW.proxy_acceptance_id IS DISTINCT FROM OLD.proxy_acceptance_id
                   OR NEW.collected_by_proxy IS DISTINCT FROM OLD.collected_by_proxy
                   OR NEW.proxy_id_checked_by IS DISTINCT FROM OLD.proxy_id_checked_by) THEN
                RAISE EXCEPTION 'the proxy of a collected piece cannot change' USING ERRCODE = 'DH006';
              END IF;
              RETURN NEW;
            END $$;

            ALTER TABLE collection
              DROP CONSTRAINT IF EXISTS proxy_egyptian_id_has_back,
              DROP COLUMN IF EXISTS proxy_id_back_storage_ref,
              DROP COLUMN IF EXISTS proxy_id_kind;
            SQL);
    }
};
