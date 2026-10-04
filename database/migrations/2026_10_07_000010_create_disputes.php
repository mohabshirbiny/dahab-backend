<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Disputes and freeze, proxy collection, the seller's request for more time
 * (spec 014). Mirrors docs/Database schema: 05_schema_security.sql §16
 * (`dispute` as built, `dispute_photo`, `dispute_change`, `compensation`),
 * §18 (three `order_transition` rows, `dispute_transition`,
 * `extension_request_transition`, the guards on SQLSTATE DH009 / DH010) and
 * §19 (forced row-level security, each row readable only by the customer it
 * belongs to — analysis C2); 04_schema_market.sql §10
 * (`order_deadline_extension.which` + `decision`, `dispute_id`,
 * `extension_request_id`; the new `order_extension_request`) and §11 (the
 * proxy columns of `collection`); 02_schema_identity.sql (the legal document
 * `collection_proxy_authorisation`).
 *
 * ROLLBACK: down() refuses once any dispute, compensation or request for more
 * time exists (a compensation is tied to an append-only ledger entry; disputes
 * froze orders). Otherwise it drops everything in reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            -- 05 §16: the dispute, its photos, its history, compensation.
            DROP SEQUENCE IF EXISTS dispute_no_seq;
            CREATE SEQUENCE dispute_no_seq;

            CREATE TABLE dispute (
              dispute_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              dispute_no   BIGINT UNIQUE NOT NULL DEFAULT nextval('dispute_no_seq'),
              dispute_ref  TEXT UNIQUE NOT NULL,
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              raised_by    UUID NOT NULL REFERENCES customer(customer_id),
              raised_as    TEXT NOT NULL CHECK (raised_as IN ('buyer','seller')),
              reason       TEXT NOT NULL CHECK (reason IN ('not_as_listed','disagree_inspection','money_wrong',
                             'other_side_unresponsive','not_theirs_to_sell','other')),
              detail       TEXT NOT NULL CHECK (char_length(detail) BETWEEN 10 AND 2000),
              state        TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','passed_on','resolved')),
              assigned_to  UUID REFERENCES staff(staff_id),
              passed_on_at TIMESTAMPTZ,
              frozen_from  order_state NOT NULL CHECK (frozen_from IN
                             ('at_inspection','weight_adjust_pending','awaiting_balance','ready_to_collect')),
              frozen_at    TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              outcome      TEXT CHECK (outcome IN ('resume','against_sale')),
              resolution_reply TEXT CHECK (resolution_reply IS NULL OR char_length(resolution_reply) BETWEEN 10 AND 2000),
              resolved_by  UUID REFERENCES staff(staff_id),
              opened_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
              resolved_at  TIMESTAMPTZ,
              release_txn_id UUID REFERENCES ledger_transaction(ledger_txn_id),
              CONSTRAINT resolved_needs_reply CHECK (
                state <> 'resolved' OR (resolution_reply IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL)),
              CONSTRAINT dispute_outcome_when_resolved CHECK ((state = 'resolved') = (outcome IS NOT NULL)),
              CONSTRAINT dispute_against_sale_before_payment CHECK (
                outcome IS DISTINCT FROM 'against_sale' OR frozen_from <> 'ready_to_collect'),
              CONSTRAINT dispute_buyer_only_reason CHECK (reason <> 'not_theirs_to_sell' OR raised_as = 'buyer'),
              CONSTRAINT dispute_passed_on_shape CHECK (state <> 'passed_on' OR (assigned_to IS NOT NULL AND passed_on_at IS NOT NULL)),
              CONSTRAINT dispute_one_per_party UNIQUE (order_id, raised_by)
            );
            CREATE UNIQUE INDEX uq_dispute_one_unresolved ON dispute(order_id) WHERE state <> 'resolved';
            CREATE INDEX idx_dispute_queue    ON dispute(state, opened_at, dispute_id);
            CREATE INDEX idx_dispute_assigned ON dispute(assigned_to) WHERE state = 'passed_on';
            CREATE INDEX idx_dispute_raiser   ON dispute(raised_by);
            -- Owned by the column, so migrate:fresh (which drops tables) drops it too.
            ALTER SEQUENCE dispute_no_seq OWNED BY dispute.dispute_no;

            CREATE TABLE dispute_photo (
              photo_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              dispute_id   UUID NOT NULL REFERENCES dispute(dispute_id),
              storage_ref  TEXT NOT NULL,
              mime         TEXT NOT NULL,
              position     SMALLINT NOT NULL CHECK (position BETWEEN 1 AND 5),
              UNIQUE (dispute_id, position)
            );
            CREATE TRIGGER trg_dispute_photo_immutable BEFORE UPDATE OR DELETE ON dispute_photo
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            CREATE TABLE dispute_change (
              change_id    BIGSERIAL PRIMARY KEY,
              dispute_id   UUID NOT NULL REFERENCES dispute(dispute_id),
              kind         TEXT NOT NULL CHECK (kind IN ('opened','passed_on','resolved')),
              actor_customer_id UUID REFERENCES customer(customer_id),
              actor_staff_id    UUID REFERENCES staff(staff_id),
              assigned_to  UUID REFERENCES staff(staff_id),
              note         TEXT CHECK (note IS NULL OR char_length(note) BETWEEN 10 AND 2000),
              at           TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              txid         BIGINT NOT NULL DEFAULT txid_current(),
              CONSTRAINT dispute_change_one_actor CHECK ((actor_customer_id IS NULL) <> (actor_staff_id IS NULL))
            );
            CREATE INDEX idx_dispute_change_dispute ON dispute_change(dispute_id, change_id);
            CREATE TRIGGER trg_dispute_change_immutable BEFORE UPDATE OR DELETE ON dispute_change
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            CREATE TABLE compensation (
              compensation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              dispute_id   UUID NOT NULL REFERENCES dispute(dispute_id),
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              party        TEXT NOT NULL CHECK (party IN ('buyer','seller')),
              amount       NUMERIC(18,4) NOT NULL CHECK (amount > 0),
              reason       TEXT NOT NULL CHECK (reason IN ('igi_delay','dahab_mistake','wasted_trip','dispute_settlement','goodwill')),
              note         TEXT NOT NULL CHECK (char_length(note) BETWEEN 10 AND 1000),
              paid_by      UUID NOT NULL REFERENCES staff(staff_id),
              ledger_txn_id UUID NOT NULL UNIQUE REFERENCES ledger_transaction(ledger_txn_id),
              paid_at      TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
            );
            CREATE INDEX idx_compensation_payer_day ON compensation(paid_by, paid_at);
            CREATE INDEX idx_compensation_dispute ON compensation(dispute_id);
            CREATE TRIGGER trg_compensation_immutable BEFORE UPDATE OR DELETE ON compensation
              FOR EACH ROW EXECUTE FUNCTION block_mutation();

            -- 05 §18: the two small machines.
            CREATE TABLE dispute_transition (
              from_state TEXT NOT NULL,
              to_state   TEXT NOT NULL,
              note       TEXT,
              PRIMARY KEY (from_state, to_state)
            );
            INSERT INTO dispute_transition (from_state, to_state, note) VALUES
              ('open','passed_on','passed to a named colleague'),
              ('passed_on','passed_on','passed on again'),
              ('open','resolved','resolved with a reply'),
              ('passed_on','resolved','resolved with a reply');

            CREATE TABLE extension_request_transition (
              from_state TEXT NOT NULL,
              to_state   TEXT NOT NULL,
              note       TEXT,
              PRIMARY KEY (from_state, to_state)
            );
            INSERT INTO extension_request_transition (from_state, to_state, note) VALUES
              ('waiting','accepted','staff extended the reach-branch deadline'),
              ('waiting','refused','staff refused'),
              ('waiting','lapsed','the order left awaiting_delivery unanswered');

            -- 04 §10: the seller's request for more time; the extension's causes.
            ALTER TABLE order_deadline_extension DROP CONSTRAINT order_deadline_extension_which_check;
            ALTER TABLE order_deadline_extension
              ADD CONSTRAINT order_deadline_extension_which_check CHECK (which IN ('reach_branch','balance','collect','decision')),
              ADD COLUMN dispute_id UUID REFERENCES dispute(dispute_id),
              ADD COLUMN extension_request_id UUID;

            CREATE TABLE order_extension_request (
              request_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id     UUID NOT NULL REFERENCES "order"(order_id),
              seller_id    UUID NOT NULL REFERENCES customer(customer_id),
              reason       TEXT NOT NULL CHECK (reason IN ('travelling','emergency','branch_closed','other')),
              detail       TEXT NOT NULL CHECK (char_length(detail) BETWEEN 10 AND 1000),
              deadline_at_request TIMESTAMPTZ NOT NULL,
              state        TEXT NOT NULL DEFAULT 'waiting' CHECK (state IN ('waiting','accepted','refused','lapsed')),
              requested_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              answered_by  UUID REFERENCES staff(staff_id),
              answered_at  TIMESTAMPTZ,
              answer_note  TEXT CHECK (answer_note IS NULL OR char_length(answer_note) BETWEEN 10 AND 1000),
              hours_granted SMALLINT CHECK (hours_granted IN (6,12,24,48)),
              extension_id UUID REFERENCES order_deadline_extension(extension_id),
              CONSTRAINT extension_request_hours CHECK ((state = 'accepted') = (hours_granted IS NOT NULL)),
              CONSTRAINT extension_request_link  CHECK ((state = 'accepted') = (extension_id IS NOT NULL)),
              CONSTRAINT extension_request_answer CHECK (
                state NOT IN ('accepted','refused') OR (answered_by IS NOT NULL AND answered_at IS NOT NULL AND answer_note IS NOT NULL))
            );
            CREATE UNIQUE INDEX uq_extension_request_waiting ON order_extension_request(order_id) WHERE state = 'waiting';
            CREATE INDEX idx_extension_request_queue ON order_extension_request(state, requested_at, request_id);
            CREATE INDEX idx_extension_request_order ON order_extension_request(order_id, requested_at);

            ALTER TABLE order_deadline_extension
              ADD CONSTRAINT order_deadline_extension_request_fk
                FOREIGN KEY (extension_request_id) REFERENCES order_extension_request(request_id),
              ADD CONSTRAINT extension_single_cause CHECK (dispute_id IS NULL OR extension_request_id IS NULL);

            -- 04 §11: the proxy is named before the counter.
            ALTER TABLE collection
              ADD COLUMN proxy_acceptance_id UUID REFERENCES agreement_acceptance(acceptance_id),
              ADD COLUMN proxy_named_at TIMESTAMPTZ,
              ADD COLUMN collected_by_proxy BOOLEAN NOT NULL DEFAULT FALSE,
              ADD COLUMN proxy_id_checked_by UUID REFERENCES staff(staff_id),
              ADD CONSTRAINT proxy_named_with_acceptance CHECK (
                NOT is_proxy OR (proxy_phone IS NOT NULL AND proxy_acceptance_id IS NOT NULL AND proxy_named_at IS NOT NULL)),
              ADD CONSTRAINT proxy_collection_checked CHECK (
                NOT collected_by_proxy OR (is_proxy AND proxy_id_checked_by IS NOT NULL AND collected_at IS NOT NULL));

            -- 05 §18: the three moves the schema lacked (Clarification Q1).
            INSERT INTO order_transition (from_state, to_state, note) VALUES
              ('weight_adjust_pending','disputed','dispute opened (spec 014)'),
              ('disputed','weight_adjust_pending','dispute resolved, resume (spec 014)'),
              ('disputed','at_inspection','dispute resolved, resume (spec 014)');
            SQL);

        DB::unprepared(<<<'SQL'
            -- Guards (05 §18, spec 014). DH009 -> 409 illegal_dispute_transition,
            -- DH010 -> 409 illegal_extension_request_transition.
            CREATE OR REPLACE FUNCTION dispute_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_buyer UUID;
              v_seller UUID;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'disputes are never deleted' USING ERRCODE = 'DH009';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.state <> 'open' THEN
                  RAISE EXCEPTION 'a dispute opens as open, not %', NEW.state USING ERRCODE = 'DH009';
                END IF;
                SELECT o.buyer_id, o.seller_id INTO v_buyer, v_seller FROM "order" o WHERE o.order_id = NEW.order_id;
                IF NOT FOUND
                   OR (NEW.raised_as = 'buyer' AND v_buyer IS DISTINCT FROM NEW.raised_by)
                   OR (NEW.raised_as = 'seller' AND v_seller IS DISTINCT FROM NEW.raised_by) THEN
                  RAISE EXCEPTION 'dispute raiser is not the order''s %', NEW.raised_as USING ERRCODE = 'DH009';
                END IF;
                NEW.dispute_ref := 'DSP-' || NEW.dispute_no;
                RETURN NEW;
              END IF;
              IF OLD.state = 'resolved' THEN
                RAISE EXCEPTION 'dispute % is resolved and final', OLD.dispute_ref USING ERRCODE = 'DH009';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state
                 AND NOT EXISTS (SELECT 1 FROM dispute_transition t WHERE t.from_state = OLD.state AND t.to_state = NEW.state) THEN
                RAISE EXCEPTION 'illegal dispute transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH009';
              END IF;
              IF NEW.dispute_no IS DISTINCT FROM OLD.dispute_no OR NEW.dispute_ref IS DISTINCT FROM OLD.dispute_ref
                 OR NEW.order_id IS DISTINCT FROM OLD.order_id OR NEW.raised_by IS DISTINCT FROM OLD.raised_by
                 OR NEW.raised_as IS DISTINCT FROM OLD.raised_as OR NEW.reason IS DISTINCT FROM OLD.reason
                 OR NEW.detail IS DISTINCT FROM OLD.detail OR NEW.frozen_from IS DISTINCT FROM OLD.frozen_from
                 OR NEW.frozen_at IS DISTINCT FROM OLD.frozen_at OR NEW.opened_at IS DISTINCT FROM OLD.opened_at THEN
                RAISE EXCEPTION 'dispute % identity columns cannot change', OLD.dispute_ref USING ERRCODE = 'DH009';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_dispute_guard BEFORE INSERT OR UPDATE OR DELETE ON dispute
              FOR EACH ROW EXECUTE FUNCTION dispute_guard();

            -- Every opening, pass-on and resolution has its history row, written in
            -- the same transaction (checked at commit).
            CREATE OR REPLACE FUNCTION dispute_change_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_kind TEXT;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                v_kind := 'opened';
              ELSIF NEW.state = 'resolved' AND OLD.state <> 'resolved' THEN
                v_kind := 'resolved';
              ELSIF NEW.assigned_to IS DISTINCT FROM OLD.assigned_to OR NEW.passed_on_at IS DISTINCT FROM OLD.passed_on_at THEN
                v_kind := 'passed_on';
              ELSE
                RETURN NULL;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM dispute_change c WHERE c.dispute_id = NEW.dispute_id
                               AND c.kind = v_kind AND c.txid = txid_current()) THEN
                RAISE EXCEPTION 'dispute % % without its history row', NEW.dispute_ref, v_kind USING ERRCODE = 'DH009';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_dispute_change_recorded
              AFTER INSERT OR UPDATE ON dispute
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION dispute_change_recorded();

            -- A compensation row names its own 'compensation' entry, crediting exactly
            -- that amount to that customer's available account; the customer is the
            -- party it says.
            CREATE OR REPLACE FUNCTION compensation_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
              v_party UUID;
            BEGIN
              SELECT CASE NEW.party WHEN 'buyer' THEN o.buyer_id ELSE o.seller_id END INTO v_party
                FROM "order" o WHERE o.order_id = NEW.order_id;
              IF v_party IS DISTINCT FROM NEW.customer_id THEN
                RAISE EXCEPTION 'compensation % is not paid to the order''s %', NEW.compensation_id, NEW.party USING ERRCODE = 'DH009';
              END IF;
              PERFORM set_config('app.rls_scope', 'ledger', true);
              SELECT EXISTS (
                SELECT 1 FROM ledger_transaction t
                  JOIN ledger_posting p ON p.ledger_txn_id = t.ledger_txn_id
                  JOIN account a ON a.account_id = p.account_id
                 WHERE t.ledger_txn_id = NEW.ledger_txn_id AND t.event_kind = 'compensation'
                   AND t.order_id = NEW.order_id
                   AND a.customer_id = NEW.customer_id AND a.kind = 'cust_available' AND p.amount = NEW.amount) INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'compensation % has no matching ledger entry', NEW.compensation_id USING ERRCODE = 'DH009';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_compensation_recorded
              AFTER INSERT ON compensation
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION compensation_recorded();

            CREATE OR REPLACE FUNCTION extension_request_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'requests for more time are never deleted' USING ERRCODE = 'DH010';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.state <> 'waiting' THEN
                  RAISE EXCEPTION 'a request for more time starts waiting, not %', NEW.state USING ERRCODE = 'DH010';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = NEW.order_id AND o.seller_id = NEW.seller_id) THEN
                  RAISE EXCEPTION 'only the order''s seller asks for more time' USING ERRCODE = 'DH010';
                END IF;
                RETURN NEW;
              END IF;
              IF OLD.state <> 'waiting' THEN
                RAISE EXCEPTION 'request % is % and final', OLD.request_id, OLD.state USING ERRCODE = 'DH010';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state
                 AND NOT EXISTS (SELECT 1 FROM extension_request_transition t WHERE t.from_state = OLD.state AND t.to_state = NEW.state) THEN
                RAISE EXCEPTION 'illegal request transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH010';
              END IF;
              IF NEW.order_id IS DISTINCT FROM OLD.order_id OR NEW.seller_id IS DISTINCT FROM OLD.seller_id
                 OR NEW.reason IS DISTINCT FROM OLD.reason OR NEW.detail IS DISTINCT FROM OLD.detail
                 OR NEW.deadline_at_request IS DISTINCT FROM OLD.deadline_at_request OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                RAISE EXCEPTION 'request % identity columns cannot change', OLD.request_id USING ERRCODE = 'DH010';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_extension_request_guard BEFORE INSERT OR UPDATE OR DELETE ON order_extension_request
              FOR EACH ROW EXECUTE FUNCTION extension_request_guard();

            -- The proxy cannot be changed once the piece is collected.
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
            CREATE TRIGGER trg_collection_proxy_frozen BEFORE UPDATE ON collection
              FOR EACH ROW EXECUTE FUNCTION collection_proxy_frozen();
            SQL);

        DB::unprepared(<<<'SQL'
            -- Row-level security (05 §19, spec 014 FR-029, analysis C2): each row is
            -- readable only by the customer it belongs to — the raiser, the customer
            -- paid, the seller who asked — never by the other party of the order.
            -- Customers write only in the non-elevated 'order' scope.
            ALTER TABLE dispute                 ENABLE ROW LEVEL SECURITY;
            ALTER TABLE dispute                 FORCE  ROW LEVEL SECURITY;
            ALTER TABLE dispute_photo           ENABLE ROW LEVEL SECURITY;
            ALTER TABLE dispute_photo           FORCE  ROW LEVEL SECURITY;
            ALTER TABLE dispute_change          ENABLE ROW LEVEL SECURITY;
            ALTER TABLE dispute_change          FORCE  ROW LEVEL SECURITY;
            ALTER TABLE compensation            ENABLE ROW LEVEL SECURITY;
            ALTER TABLE compensation            FORCE  ROW LEVEL SECURITY;
            ALTER TABLE order_extension_request ENABLE ROW LEVEL SECURITY;
            ALTER TABLE order_extension_request FORCE  ROW LEVEL SECURITY;

            CREATE POLICY dispute_isolation ON dispute FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR raised_by = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order' AND raised_by = (SELECT dahab_current_customer_id())
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = dispute.order_id)));
            CREATE POLICY dispute_photo_isolation ON dispute_photo FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR EXISTS (SELECT 1 FROM dispute d
                            WHERE d.dispute_id = dispute_photo.dispute_id AND d.raised_by = (SELECT dahab_current_customer_id())))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order' AND EXISTS (SELECT 1 FROM dispute d
                            WHERE d.dispute_id = dispute_photo.dispute_id AND d.raised_by = (SELECT dahab_current_customer_id()))));
            CREATE POLICY dispute_change_isolation ON dispute_change FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR EXISTS (SELECT 1 FROM dispute d
                            WHERE d.dispute_id = dispute_change.dispute_id AND d.raised_by = (SELECT dahab_current_customer_id())))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order' AND kind = 'opened' AND actor_staff_id IS NULL
                     AND actor_customer_id = (SELECT dahab_current_customer_id())
                     AND EXISTS (SELECT 1 FROM dispute d
                            WHERE d.dispute_id = dispute_change.dispute_id AND d.raised_by = (SELECT dahab_current_customer_id()))));
            CREATE POLICY compensation_isolation ON compensation FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY order_extension_request_isolation ON order_extension_request FOR ALL
              USING      ((SELECT dahab_rls_elevated()) OR seller_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (
                     (SELECT dahab_rls_scope()) = 'order' AND seller_id = (SELECT dahab_current_customer_id())
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.order_id = order_extension_request.order_id)));

            -- 02: the proxy authorisation (acceptance context 'collection_proxy'; the
            -- prototype's tick and warning, pending the legal clinic's wording).
            INSERT INTO legal_document (code, version, body_en, body_ar, is_material, published_by)
            SELECT 'collection_proxy_authorisation', 1,
                   'I authorise this person to collect the piece on my behalf, and I take responsibility for that choice. Dahab does not verify the relationship between us; the piece is handed over once their ID and the code match.',
                   'أفوض الشخص ده باستلام القطعة نيابة عني، وأتحمل مسؤولية الاختيار ده. دهب مش بتتحقق من صلة القرابة بينا، والقطعة بتتسلم بمجرد تطابق بطاقته مع الكود.',
                   FALSE, staff_id
            FROM staff WHERE is_system = TRUE;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $used = DB::selectOne('SELECT (EXISTS (SELECT 1 FROM dispute) OR EXISTS (SELECT 1 FROM compensation)
            OR EXISTS (SELECT 1 FROM order_extension_request)) AS used')->used;
        if ($used) {
            throw new RuntimeException('Disputes, compensation or requests for more time exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DELETE FROM legal_document WHERE code = 'collection_proxy_authorisation'
              AND NOT EXISTS (SELECT 1 FROM agreement_acceptance a WHERE a.legal_doc_id = legal_document.legal_doc_id);
            DELETE FROM order_transition WHERE (from_state, to_state) IN (
              ('weight_adjust_pending','disputed'), ('disputed','weight_adjust_pending'), ('disputed','at_inspection'));
            DROP TRIGGER IF EXISTS trg_collection_proxy_frozen ON collection;
            DROP FUNCTION IF EXISTS collection_proxy_frozen();
            ALTER TABLE collection
              DROP CONSTRAINT IF EXISTS proxy_collection_checked,
              DROP CONSTRAINT IF EXISTS proxy_named_with_acceptance,
              DROP COLUMN IF EXISTS proxy_id_checked_by,
              DROP COLUMN IF EXISTS collected_by_proxy,
              DROP COLUMN IF EXISTS proxy_named_at,
              DROP COLUMN IF EXISTS proxy_acceptance_id;
            ALTER TABLE order_deadline_extension
              DROP CONSTRAINT IF EXISTS extension_single_cause,
              DROP CONSTRAINT IF EXISTS order_deadline_extension_request_fk;
            DROP TABLE IF EXISTS order_extension_request;
            ALTER TABLE order_deadline_extension
              DROP COLUMN IF EXISTS extension_request_id,
              DROP COLUMN IF EXISTS dispute_id,
              DROP CONSTRAINT IF EXISTS order_deadline_extension_which_check;
            ALTER TABLE order_deadline_extension
              ADD CONSTRAINT order_deadline_extension_which_check CHECK (which IN ('reach_branch','balance','collect'));
            DROP TABLE IF EXISTS extension_request_transition;
            DROP TABLE IF EXISTS dispute_transition;
            DROP TABLE IF EXISTS compensation;
            DROP TABLE IF EXISTS dispute_change;
            DROP TABLE IF EXISTS dispute_photo;
            DROP TABLE IF EXISTS dispute;
            DROP SEQUENCE IF EXISTS dispute_no_seq;
            DROP FUNCTION IF EXISTS extension_request_guard();
            DROP FUNCTION IF EXISTS compensation_recorded();
            DROP FUNCTION IF EXISTS dispute_change_recorded();
            DROP FUNCTION IF EXISTS dispute_guard();
            SQL);
    }
};
