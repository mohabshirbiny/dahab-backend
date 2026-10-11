<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 021 (specs/021-founder-security/data-model.md §1). Mirrored in
 * docs/Database schema/05_schema_security.sql ("spec 021").
 *
 *  - `founder_device_approval` gains an expiry, the poll ref's HMAC, what the approver sees
 *    (platform, user agent, IP), a refusal, a decision note and the single-use collection;
 *    guard DH021: never deleted, identity frozen, one decision before expiry, the expiry only
 *    moves earlier, collected once and only when approved.
 *  - `account_freeze` gains the reason and the two confirmation times plus who gave slot 1;
 *    one open freeze per staff member; guard DH022: never deleted, the freezer is a founder,
 *    the freeze itself never changes, each confirmation is given once, slot 2 is a founder,
 *    an unfrozen row never changes. The target is not required to be a founder here (the
 *    action enforces "the other founder"; research R15).
 *  - `staff_notice`: Dashboard notices (spec 024 reuses it with its own kinds).
 *  - permission `founder.unfreeze_second`, given to the `ceo` role when it exists (plan Q1).
 */
return new class extends Migration
{
    private const PERMISSION = 'founder.unfreeze_second';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE founder_device_approval
              ADD COLUMN expires_at    TIMESTAMPTZ,
              ADD COLUMN ref_hash      TEXT UNIQUE,
              ADD COLUMN platform      TEXT,
              ADD COLUMN user_agent    TEXT,
              ADD COLUMN ip_address    INET,
              ADD COLUMN refused_by    UUID REFERENCES staff(staff_id),
              ADD COLUMN refused_at    TIMESTAMPTZ,
              ADD COLUMN decision_note TEXT,
              ADD COLUMN consumed_at   TIMESTAMPTZ;

            UPDATE founder_device_approval SET expires_at = requested_at + interval '15 minutes' WHERE expires_at IS NULL;
            ALTER TABLE founder_device_approval ALTER COLUMN expires_at SET NOT NULL;

            ALTER TABLE founder_device_approval
              ADD CONSTRAINT refuser_is_not_self     CHECK (refused_by IS NULL OR refused_by <> staff_id),
              ADD CONSTRAINT approved_pair           CHECK ((approved_by IS NULL) = (approved_at IS NULL)),
              ADD CONSTRAINT refused_pair            CHECK ((refused_by IS NULL) = (refused_at IS NULL)),
              ADD CONSTRAINT one_decision            CHECK (approved_at IS NULL OR refused_at IS NULL),
              ADD CONSTRAINT consumed_after_approval CHECK (consumed_at IS NULL OR approved_at IS NOT NULL);

            CREATE INDEX idx_fda_staff_pending ON founder_device_approval(staff_id, device_fingerprint)
              WHERE approved_at IS NULL AND refused_at IS NULL;
            CREATE INDEX idx_fda_requested ON founder_device_approval(requested_at DESC);

            CREATE OR REPLACE FUNCTION founder_device_approval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'a founder device approval is never deleted' USING ERRCODE = 'DH021';
              END IF;

              IF NEW.staff_id IS DISTINCT FROM OLD.staff_id
                 OR NEW.device_fingerprint IS DISTINCT FROM OLD.device_fingerprint
                 OR NEW.requested_at IS DISTINCT FROM OLD.requested_at
                 OR NEW.ref_hash IS DISTINCT FROM OLD.ref_hash THEN
                RAISE EXCEPTION 'the request of a founder device approval never changes' USING ERRCODE = 'DH021';
              END IF;

              IF OLD.approved_at IS NOT NULL OR OLD.refused_at IS NOT NULL THEN
                IF NEW.approved_by IS DISTINCT FROM OLD.approved_by OR NEW.approved_at IS DISTINCT FROM OLD.approved_at
                   OR NEW.refused_by IS DISTINCT FROM OLD.refused_by OR NEW.refused_at IS DISTINCT FROM OLD.refused_at
                   OR NEW.decision_note IS DISTINCT FROM OLD.decision_note THEN
                  RAISE EXCEPTION 'a founder device approval is decided once' USING ERRCODE = 'DH021';
                END IF;
              ELSIF NEW.approved_at IS NOT NULL OR NEW.refused_at IS NOT NULL THEN
                IF now() >= OLD.expires_at THEN
                  RAISE EXCEPTION 'a founder device approval is decided before it expires' USING ERRCODE = 'DH021';
                END IF;
              ELSIF NEW.decision_note IS DISTINCT FROM OLD.decision_note THEN
                RAISE EXCEPTION 'a decision note comes with the decision' USING ERRCODE = 'DH021';
              END IF;

              IF NEW.expires_at > OLD.expires_at THEN
                RAISE EXCEPTION 'the expiry of a founder device approval only moves earlier' USING ERRCODE = 'DH021';
              END IF;

              IF OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at THEN
                RAISE EXCEPTION 'a founder device approval is collected once' USING ERRCODE = 'DH021';
              END IF;

              IF OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL AND NEW.approved_at IS NULL THEN
                RAISE EXCEPTION 'only an approved founder device approval is collected' USING ERRCODE = 'DH021';
              END IF;

              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_founder_device_approval_guard
              BEFORE UPDATE OR DELETE ON founder_device_approval
              FOR EACH ROW EXECUTE FUNCTION founder_device_approval_guard();

            ALTER TABLE account_freeze
              ADD COLUMN reason                  TEXT,
              ADD COLUMN unfreeze_confirm_1_at   TIMESTAMPTZ,
              ADD COLUMN unfreeze_confirm_1_kind TEXT CHECK (unfreeze_confirm_1_kind IN ('frozen_founder','second_approver')),
              ADD COLUMN unfreeze_confirm_2_at   TIMESTAMPTZ;

            UPDATE account_freeze SET reason = '(recorded before spec 021)' WHERE reason IS NULL;
            ALTER TABLE account_freeze ALTER COLUMN reason SET NOT NULL;

            -- Rows from before spec 021 never recorded when a confirmation was given.
            UPDATE account_freeze SET unfreeze_confirm_1_at = COALESCE(unfrozen_at, frozen_at),
                                      unfreeze_confirm_1_kind = CASE WHEN unfreeze_confirm_1 = frozen_staff_id
                                                                     THEN 'frozen_founder' ELSE 'second_approver' END
             WHERE unfreeze_confirm_1 IS NOT NULL;
            UPDATE account_freeze SET unfreeze_confirm_2_at = COALESCE(unfrozen_at, frozen_at)
             WHERE unfreeze_confirm_2 IS NOT NULL;

            ALTER TABLE account_freeze
              ADD CONSTRAINT confirm_1_complete CHECK ((unfreeze_confirm_1 IS NULL) = (unfreeze_confirm_1_at IS NULL)
                                                   AND (unfreeze_confirm_1 IS NULL) = (unfreeze_confirm_1_kind IS NULL)),
              ADD CONSTRAINT confirm_2_complete CHECK ((unfreeze_confirm_2 IS NULL) = (unfreeze_confirm_2_at IS NULL)),
              ADD CONSTRAINT confirm_2_after_1 CHECK (unfreeze_confirm_2 IS NULL OR unfreeze_confirm_1 IS NOT NULL),
              ADD CONSTRAINT confirmers_differ CHECK (unfreeze_confirm_2 IS NULL OR unfreeze_confirm_2 <> unfreeze_confirm_1),
              ADD CONSTRAINT confirm_2_not_frozen CHECK (unfreeze_confirm_2 IS NULL OR unfreeze_confirm_2 <> frozen_staff_id),
              ADD CONSTRAINT confirm_1_kind_match CHECK (unfreeze_confirm_1_kind IS DISTINCT FROM 'frozen_founder'
                                                     OR unfreeze_confirm_1 = frozen_staff_id),
              ADD CONSTRAINT second_approver_not_frozen CHECK (unfreeze_confirm_1_kind IS DISTINCT FROM 'second_approver'
                                                     OR unfreeze_confirm_1 <> frozen_staff_id),
              ADD CONSTRAINT unfrozen_iff_confirm_2 CHECK ((unfrozen_at IS NULL) = (unfreeze_confirm_2 IS NULL)),
              ADD CONSTRAINT reason_present CHECK (length(btrim(reason)) > 0);

            CREATE UNIQUE INDEX uq_account_freeze_open ON account_freeze(frozen_staff_id) WHERE unfrozen_at IS NULL;
            CREATE INDEX idx_account_freeze_frozen_at ON account_freeze(frozen_at DESC);

            CREATE OR REPLACE FUNCTION account_freeze_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'an account freeze is never deleted' USING ERRCODE = 'DH022';
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF NOT EXISTS (SELECT 1 FROM staff WHERE staff_id = NEW.frozen_by AND is_founder) THEN
                  RAISE EXCEPTION 'only a founder freezes an account' USING ERRCODE = 'DH022';
                END IF;
              ELSE
                IF OLD.unfrozen_at IS NOT NULL THEN
                  RAISE EXCEPTION 'an unfrozen account freeze never changes' USING ERRCODE = 'DH022';
                END IF;

                IF NEW.frozen_staff_id IS DISTINCT FROM OLD.frozen_staff_id
                   OR NEW.frozen_by IS DISTINCT FROM OLD.frozen_by
                   OR NEW.frozen_at IS DISTINCT FROM OLD.frozen_at
                   OR NEW.reason IS DISTINCT FROM OLD.reason THEN
                  RAISE EXCEPTION 'the freeze itself never changes' USING ERRCODE = 'DH022';
                END IF;

                IF OLD.unfreeze_confirm_1 IS NOT NULL
                   AND (NEW.unfreeze_confirm_1 IS DISTINCT FROM OLD.unfreeze_confirm_1
                        OR NEW.unfreeze_confirm_1_at IS DISTINCT FROM OLD.unfreeze_confirm_1_at
                        OR NEW.unfreeze_confirm_1_kind IS DISTINCT FROM OLD.unfreeze_confirm_1_kind) THEN
                  RAISE EXCEPTION 'the first unfreeze confirmation is given once' USING ERRCODE = 'DH022';
                END IF;
              END IF;

              IF NEW.unfreeze_confirm_2 IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM staff WHERE staff_id = NEW.unfreeze_confirm_2 AND is_founder) THEN
                RAISE EXCEPTION 'the second unfreeze confirmation comes from a founder' USING ERRCODE = 'DH022';
              END IF;

              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_account_freeze_guard
              BEFORE INSERT OR UPDATE OR DELETE ON account_freeze
              FOR EACH ROW EXECUTE FUNCTION account_freeze_guard();

            CREATE TABLE staff_notice (
              notice_id  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              staff_id   UUID NOT NULL REFERENCES staff(staff_id),
              kind       TEXT NOT NULL,
              severity   TEXT NOT NULL DEFAULT 'info' CHECK (severity IN ('info','warning','critical')),
              title      TEXT NOT NULL,
              body       TEXT NOT NULL,
              params     JSONB NOT NULL DEFAULT '{}'::jsonb,
              link       TEXT,
              audit_id   BIGINT REFERENCES audit_log(audit_id),
              dedupe_key TEXT UNIQUE,
              created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              read_at    TIMESTAMPTZ
            );
            CREATE INDEX idx_staff_notice_inbox ON staff_notice(staff_id, created_at DESC);
            CREATE INDEX idx_staff_notice_unread ON staff_notice(staff_id) WHERE read_at IS NULL;
            SQL);

        DB::statement(
            'INSERT INTO permissions (name, guard_name, created_at, updated_at)
             VALUES (?, ?, now(), now())
             ON CONFLICT (name, guard_name) DO NOTHING',
            [self::PERMISSION, 'staff'],
        );

        // Plan Q1: the CEO holds it so they can name a stand-in from the Roles page. A fresh
        // database gets it from the catalogue sync, which gives the CEO every code.
        DB::statement(
            "INSERT INTO role_has_permissions (permission_id, role_id)
             SELECT p.id, r.id
               FROM permissions p
               JOIN roles r ON r.name = 'ceo' AND r.guard_name = 'staff'
              WHERE p.name = ? AND p.guard_name = 'staff'
             ON CONFLICT DO NOTHING",
            [self::PERMISSION],
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM founder_device_approval WHERE ref_hash IS NOT NULL)
                OR EXISTS (SELECT 1 FROM account_freeze WHERE unfreeze_confirm_1_at IS NOT NULL OR unfreeze_confirm_2_at IS NOT NULL)
                OR EXISTS (SELECT 1 FROM staff_notice) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 021 data exists: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE staff_notice;

            DROP TRIGGER trg_account_freeze_guard ON account_freeze;
            DROP FUNCTION account_freeze_guard();
            DROP INDEX idx_account_freeze_frozen_at;
            DROP INDEX uq_account_freeze_open;
            ALTER TABLE account_freeze
              DROP CONSTRAINT confirm_1_complete,
              DROP CONSTRAINT confirm_2_complete,
              DROP CONSTRAINT confirm_2_after_1,
              DROP CONSTRAINT confirmers_differ,
              DROP CONSTRAINT confirm_2_not_frozen,
              DROP CONSTRAINT confirm_1_kind_match,
              DROP CONSTRAINT second_approver_not_frozen,
              DROP CONSTRAINT unfrozen_iff_confirm_2,
              DROP CONSTRAINT reason_present,
              DROP COLUMN reason,
              DROP COLUMN unfreeze_confirm_1_at,
              DROP COLUMN unfreeze_confirm_1_kind,
              DROP COLUMN unfreeze_confirm_2_at;

            DROP TRIGGER trg_founder_device_approval_guard ON founder_device_approval;
            DROP FUNCTION founder_device_approval_guard();
            DROP INDEX idx_fda_requested;
            DROP INDEX idx_fda_staff_pending;
            ALTER TABLE founder_device_approval
              DROP CONSTRAINT refuser_is_not_self,
              DROP CONSTRAINT approved_pair,
              DROP CONSTRAINT refused_pair,
              DROP CONSTRAINT one_decision,
              DROP CONSTRAINT consumed_after_approval,
              DROP COLUMN expires_at,
              DROP COLUMN ref_hash,
              DROP COLUMN platform,
              DROP COLUMN user_agent,
              DROP COLUMN ip_address,
              DROP COLUMN refused_by,
              DROP COLUMN refused_at,
              DROP COLUMN decision_note,
              DROP COLUMN consumed_at;
            SQL);

        DB::statement(
            "DELETE FROM role_has_permissions
              WHERE permission_id IN (SELECT id FROM permissions WHERE name = ? AND guard_name = 'staff')",
            [self::PERMISSION],
        );
        DB::statement("DELETE FROM permissions WHERE name = ? AND guard_name = 'staff'", [self::PERMISSION]);
    }
};
