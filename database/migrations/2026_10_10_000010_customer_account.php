<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 017 — the customer account: closing an account, devices on sessions,
 * the email-change link, the pause trigger of a contact change, the in-app
 * inbox, saved pieces and listing reports (data-model.md).
 *
 * Guards: DH013 (nothing new for a closed customer), DH014 (an inbox item
 * only gets read), DH015 (a report only leaves `open`, once).
 */
return new class extends Migration
{
    /** Tables that refuse a new row for a closed customer, with the column naming the customer. */
    private const CLOSED_GUARDED = [
        'listing' => 'seller_id',
        'buy_request' => 'buyer_id',
        'withdrawal' => 'customer_id',
        'withdrawal_confirmation' => 'customer_id',
        'topup' => 'customer_id',
        'dispute' => 'raised_by',
        'payout_account' => 'customer_id',
        'saved_listing' => 'customer_id',
        'listing_report' => 'reporter_id',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- Closing an account (spec 017 FR-050, FR-051). Final in this spec.
            ALTER TABLE customer
              ADD COLUMN closed_at     TIMESTAMPTZ,
              ADD COLUMN closed_reason TEXT CHECK (closed_reason IN (
                'finished','fees_too_high','too_slow_to_sell','data_trust','something_went_wrong','other')),
              ADD COLUMN closed_note   TEXT CHECK (char_length(closed_note) <= 500),
              ADD CONSTRAINT customer_closed_shape CHECK ((closed_at IS NULL) = (closed_reason IS NULL)),
              ADD CONSTRAINT customer_closed_note_other CHECK (closed_note IS NULL OR closed_reason = 'other');

            -- `closed` is a status of its own; the legacy flags keep what they were at closing.
            ALTER TABLE customer DROP CONSTRAINT customer_status_check;
            ALTER TABLE customer ADD CONSTRAINT customer_status_check
              CHECK (status IN ('pending_verification','active','rejected','suspended','closed'));
            ALTER TABLE customer DROP CONSTRAINT customer_status_flags_consistent;
            ALTER TABLE customer ADD CONSTRAINT customer_status_flags_consistent CHECK (
                 (status = 'pending_verification' AND is_verified = FALSE AND is_suspended = FALSE)
              OR (status = 'active'               AND is_verified = TRUE  AND is_suspended = FALSE)
              OR (status = 'rejected'             AND is_verified = FALSE AND is_suspended = FALSE)
              OR (status = 'suspended'            AND is_suspended = TRUE)
              OR (status = 'closed'));
            ALTER TABLE customer ADD CONSTRAINT customer_closed_status CHECK ((status = 'closed') = (closed_at IS NOT NULL));

            -- Sessions learn their device (research R4).
            ALTER TABLE personal_access_tokens
              ADD COLUMN device_fingerprint_hash TEXT,
              ADD COLUMN device_platform TEXT;
            CREATE INDEX idx_pat_tokenable_device ON personal_access_tokens (tokenable_id, device_fingerprint_hash);

            ALTER TABLE customer_trusted_device
              ADD COLUMN platform   TEXT CHECK (platform IN ('ios','android','web')),
              ADD COLUMN user_agent TEXT CHECK (char_length(user_agent) <= 255);

            -- The email-change link (research R2).
            ALTER TABLE one_time_token DROP CONSTRAINT one_time_token_purpose_check;
            ALTER TABLE one_time_token ADD CONSTRAINT one_time_token_purpose_check CHECK (purpose IN (
              'password_reset_customer','password_reset_staff','email_verification','email_change'));

            -- What opened a withdrawal pause (research R3).
            ALTER TABLE withdrawal_pause
              ADD COLUMN trigger_kind TEXT NOT NULL DEFAULT 'payout_account'
                CHECK (trigger_kind IN ('payout_account','phone_change','email_change')),
              ADD CONSTRAINT withdrawal_pause_trigger_account
                CHECK (trigger_kind = 'payout_account' OR triggered_by_account IS NULL);

            -- Closing withdraws every piece not in a sale (research R8).
            INSERT INTO listing_transition (from_state, to_state, note) VALUES
              ('draft','withdrawn','account closed'),
              ('in_review','withdrawn','account closed'),
              ('changes_requested','withdrawn','account closed'),
              ('suspended_hold','withdrawn','account closed');
            -- A draft withdrawn at closing never went live, so it has no listed_at.
            ALTER TABLE listing DROP CONSTRAINT listing_listed_shape;
            ALTER TABLE listing ADD CONSTRAINT listing_listed_shape CHECK (
              listed_at IS NOT NULL OR state IN ('draft','in_review','changes_requested','rejected','withdrawn'));

            INSERT INTO setting (setting_key, value_numeric, unit, description) VALUES
              ('saved.max_per_customer', 200, 'count', 'Most pieces one customer may keep in Saved');

            -- The in-app inbox (research R5). Written by the inbox channel in an elevated scope.
            CREATE TABLE customer_notification (
              notification_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              type         TEXT NOT NULL CHECK (type ~ '^[a-z_]+\.[a-z_]+$'),
              params       JSONB NOT NULL DEFAULT '{}'::jsonb,
              link_kind    TEXT NOT NULL CHECK (link_kind IN ('order','listing','buy_request','wallet','withdrawal',
                             'payout_account','topup','invoice','credit_note','dispute','account','none')),
              link_id      TEXT,
              title_en     TEXT NOT NULL CHECK (char_length(title_en) <= 200),
              title_ar     TEXT NOT NULL CHECK (char_length(title_ar) <= 200),
              body_en      TEXT NOT NULL CHECK (char_length(body_en) <= 1000),
              body_ar      TEXT NOT NULL CHECK (char_length(body_ar) <= 1000),
              dedupe_key   TEXT NOT NULL,
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
              read_at      TIMESTAMPTZ,
              CONSTRAINT customer_notification_link CHECK ((link_id IS NULL) = (link_kind IN ('wallet','account','none'))),
              CONSTRAINT customer_notification_dedupe UNIQUE (customer_id, dedupe_key)
            );
            CREATE INDEX idx_customer_notification_feed ON customer_notification (customer_id, created_at DESC, notification_id DESC);
            CREATE INDEX idx_customer_notification_unread ON customer_notification (customer_id) WHERE read_at IS NULL;

            CREATE OR REPLACE FUNCTION customer_notification_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'inbox items are never deleted' USING ERRCODE = 'DH014';
              END IF;
              IF OLD.read_at IS NOT NULL
                 OR NEW.read_at IS NULL
                 OR (to_jsonb(NEW) - 'read_at') <> (to_jsonb(OLD) - 'read_at') THEN
                RAISE EXCEPTION 'an inbox item only gets read, once' USING ERRCODE = 'DH014';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_customer_notification_guard
              BEFORE UPDATE OR DELETE ON customer_notification
              FOR EACH ROW EXECUTE FUNCTION customer_notification_guard();

            ALTER TABLE customer_notification ENABLE ROW LEVEL SECURITY;
            ALTER TABLE customer_notification FORCE  ROW LEVEL SECURITY;
            CREATE POLICY customer_notification_read ON customer_notification FOR SELECT
              USING ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY customer_notification_insert ON customer_notification FOR INSERT
              WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY customer_notification_update ON customer_notification FOR UPDATE
              USING ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));

            -- Saved pieces (research R6). The summary is taken at save time, without media.
            CREATE TABLE saved_listing (
              customer_id UUID NOT NULL REFERENCES customer(customer_id),
              listing_id  UUID NOT NULL REFERENCES listing(listing_id),
              summary     JSONB NOT NULL,
              saved_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
              PRIMARY KEY (customer_id, listing_id)
            );
            CREATE INDEX idx_saved_listing_recent ON saved_listing (customer_id, saved_at DESC);

            ALTER TABLE saved_listing ENABLE ROW LEVEL SECURITY;
            ALTER TABLE saved_listing FORCE  ROW LEVEL SECURITY;
            CREATE POLICY saved_listing_isolation ON saved_listing FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()));

            -- Listing reports (research R9). The seller never sees who reported.
            CREATE SEQUENCE listing_report_no_seq;
            CREATE TABLE listing_report (
              report_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              report_no   BIGINT NOT NULL UNIQUE DEFAULT nextval('listing_report_no_seq'),
              listing_id  UUID NOT NULL REFERENCES listing(listing_id),
              reporter_id UUID NOT NULL REFERENCES customer(customer_id),
              reason      TEXT NOT NULL CHECK (reason IN ('photos_not_genuine','price_or_weight_wrong',
                            'description_mismatch','not_theirs_to_sell','off_platform_dealing','other')),
              note        TEXT CHECK (char_length(note) <= 1000),
              state       TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','dismissed','actioned','listing_gone')),
              handled_by  UUID REFERENCES staff(staff_id),
              handled_at  TIMESTAMPTZ,
              staff_note  TEXT CHECK (char_length(staff_note) <= 1000),
              created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT listing_report_handled CHECK ((state = 'open') = (handled_at IS NULL)),
              CONSTRAINT listing_report_staff CHECK (state NOT IN ('dismissed','actioned') OR handled_by IS NOT NULL),
              CONSTRAINT listing_report_dismiss_note CHECK (state <> 'dismissed' OR staff_note IS NOT NULL)
            );
            ALTER SEQUENCE listing_report_no_seq OWNED BY listing_report.report_no;
            CREATE UNIQUE INDEX uq_listing_report_open ON listing_report (listing_id, reporter_id) WHERE state = 'open';
            CREATE INDEX idx_listing_report_queue ON listing_report (state, created_at);
            CREATE INDEX idx_listing_report_reporter ON listing_report (reporter_id, created_at);

            CREATE OR REPLACE FUNCTION listing_report_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'listing reports are never deleted' USING ERRCODE = 'DH015';
              END IF;
              IF OLD.state <> 'open' OR NEW.state = 'open'
                 OR (to_jsonb(NEW) - ARRAY['state','handled_by','handled_at','staff_note'])
                    <> (to_jsonb(OLD) - ARRAY['state','handled_by','handled_at','staff_note']) THEN
                RAISE EXCEPTION 'report_not_open: report % is no longer open', OLD.report_no USING ERRCODE = 'DH015';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_listing_report_guard
              BEFORE UPDATE OR DELETE ON listing_report
              FOR EACH ROW EXECUTE FUNCTION listing_report_guard();

            ALTER TABLE listing_report ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_report FORCE  ROW LEVEL SECURITY;
            CREATE POLICY listing_report_read ON listing_report FOR SELECT
              USING ((SELECT dahab_rls_elevated()) OR reporter_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY listing_report_insert ON listing_report FOR INSERT
              WITH CHECK ((SELECT dahab_rls_elevated()) OR reporter_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY listing_report_update ON listing_report FOR UPDATE
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));

            -- Nothing new for a closed customer (research R8). The customer row is read in the
            -- system scope, then the caller scope is put back.
            CREATE OR REPLACE FUNCTION dahab_customer_is_closed(p_customer UUID) RETURNS boolean
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_closed BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT closed_at IS NOT NULL INTO v_closed FROM customer WHERE customer_id = p_customer;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN COALESCE(v_closed, FALSE);
            END $$;

            CREATE OR REPLACE FUNCTION refuse_closed_customer() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_customer UUID;
            BEGIN
              EXECUTE format('SELECT ($1).%I::uuid', TG_ARGV[0]) USING NEW INTO v_customer;
              IF v_customer IS NOT NULL AND dahab_customer_is_closed(v_customer) THEN
                RAISE EXCEPTION 'account_closed: customer % is closed', v_customer USING ERRCODE = 'DH013';
              END IF;
              RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION refuse_closed_customer_posting() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_customer UUID;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT customer_id INTO v_customer FROM account WHERE account_id = NEW.account_id;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF v_customer IS NOT NULL AND dahab_customer_is_closed(v_customer) THEN
                RAISE EXCEPTION 'account_closed: customer % is closed', v_customer USING ERRCODE = 'DH013';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_ledger_posting_not_closed
              BEFORE INSERT ON ledger_posting
              FOR EACH ROW EXECUTE FUNCTION refuse_closed_customer_posting();
            SQL);

        foreach (self::CLOSED_GUARDED as $table => $column) {
            $quoted = $table === 'order' ? '"order"' : $table;
            DB::unprepared("CREATE TRIGGER trg_{$table}_not_closed BEFORE INSERT ON {$quoted}
                FOR EACH ROW EXECUTE FUNCTION refuse_closed_customer('{$column}')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM customer WHERE closed_at IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM customer_notification)
                 OR EXISTS (SELECT 1 FROM saved_listing)
                 OR EXISTS (SELECT 1 FROM listing_report)
                 OR EXISTS (SELECT 1 FROM withdrawal_pause WHERE trigger_kind <> 'payout_account')
                 OR EXISTS (SELECT 1 FROM one_time_token WHERE purpose = 'email_change')
                 OR EXISTS (SELECT 1 FROM listing_state_change c WHERE c.to_state = 'withdrawn'
                              AND c.from_state IN ('draft','in_review','changes_requested','suspended_hold'))) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 017 data exists: this migration cannot be rolled back.');
        }

        foreach (array_keys(self::CLOSED_GUARDED) as $table) {
            if (in_array($table, ['saved_listing', 'listing_report'], true)) {
                continue;
            }
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_not_closed ON {$table}");
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_ledger_posting_not_closed ON ledger_posting;
            DROP TABLE IF EXISTS listing_report;
            DROP TABLE IF EXISTS saved_listing;
            DROP TABLE IF EXISTS customer_notification;
            DROP FUNCTION IF EXISTS refuse_closed_customer_posting();
            DROP FUNCTION IF EXISTS refuse_closed_customer();
            DROP FUNCTION IF EXISTS dahab_customer_is_closed(UUID);
            DROP FUNCTION IF EXISTS listing_report_guard();
            DROP FUNCTION IF EXISTS customer_notification_guard();
            DELETE FROM setting WHERE setting_key = 'saved.max_per_customer';
            DELETE FROM listing_transition WHERE to_state = 'withdrawn'
              AND from_state IN ('draft','in_review','changes_requested','suspended_hold');
            ALTER TABLE listing DROP CONSTRAINT listing_listed_shape;
            ALTER TABLE listing ADD CONSTRAINT listing_listed_shape CHECK (
              listed_at IS NOT NULL OR state IN ('draft','in_review','changes_requested','rejected'));
            ALTER TABLE withdrawal_pause DROP CONSTRAINT IF EXISTS withdrawal_pause_trigger_account;
            ALTER TABLE withdrawal_pause DROP COLUMN IF EXISTS trigger_kind;
            ALTER TABLE one_time_token DROP CONSTRAINT one_time_token_purpose_check;
            ALTER TABLE one_time_token ADD CONSTRAINT one_time_token_purpose_check CHECK (purpose IN (
              'password_reset_customer','password_reset_staff','email_verification'));
            ALTER TABLE customer_trusted_device DROP COLUMN IF EXISTS platform, DROP COLUMN IF EXISTS user_agent;
            DROP INDEX IF EXISTS idx_pat_tokenable_device;
            ALTER TABLE personal_access_tokens DROP COLUMN IF EXISTS device_fingerprint_hash, DROP COLUMN IF EXISTS device_platform;
            ALTER TABLE customer DROP CONSTRAINT customer_closed_status;
            ALTER TABLE customer DROP CONSTRAINT customer_status_flags_consistent;
            ALTER TABLE customer ADD CONSTRAINT customer_status_flags_consistent CHECK (
                 (status = 'pending_verification' AND is_verified = FALSE AND is_suspended = FALSE)
              OR (status = 'active'               AND is_verified = TRUE  AND is_suspended = FALSE)
              OR (status = 'rejected'             AND is_verified = FALSE AND is_suspended = FALSE)
              OR (status = 'suspended'            AND is_suspended = TRUE));
            ALTER TABLE customer DROP CONSTRAINT customer_status_check;
            ALTER TABLE customer ADD CONSTRAINT customer_status_check
              CHECK (status IN ('pending_verification','active','rejected','suspended'));
            ALTER TABLE customer DROP CONSTRAINT IF EXISTS customer_closed_note_other,
              DROP CONSTRAINT IF EXISTS customer_closed_shape,
              DROP COLUMN IF EXISTS closed_note, DROP COLUMN IF EXISTS closed_reason, DROP COLUMN IF EXISTS closed_at;
            SQL);
    }
};
