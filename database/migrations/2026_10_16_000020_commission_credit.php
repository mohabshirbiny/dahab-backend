<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 023 M2 — commission credits, the code on a listing, referral, the cost figures stored on
 * the order and the `promo` scope's policies (specs/023-first-sale-and-referral/data-model.md §2).
 *
 * A commission credit is a promise of a lower commission on a later sale. It is not wallet money:
 * no ledger account, never withdrawable; it is spent only by lowering a settlement's commission.
 * Counters and status move forward only, each move has its event in the same transaction
 * (SQLSTATE DH024). A credit waiver stored on an order has exactly one matching `spent` event
 * (deferred, DH026). A listing's code may change only while no buyer is in line (DH027).
 *
 * Deviations from the planning document, both to keep the cross-customer facts integer or
 * boolean answers behind a function (research R26): `promo_code_value_since` (the monthly cap)
 * and `referral_same_person` (the friend is not the inviter under another account).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE referral_code (
              customer_id  UUID PRIMARY KEY REFERENCES customer(customer_id),
              code         TEXT NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9]{8}$'),
              shares_count INT NOT NULL DEFAULT 0 CHECK (shares_count >= 0),
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE referral (
              referral_id        UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              inviter_id         UUID NOT NULL REFERENCES customer(customer_id),
              friend_id          UUID NOT NULL REFERENCES customer(customer_id),
              code               TEXT NOT NULL,
              listing_id         UUID REFERENCES listing(listing_id),
              device_fingerprint TEXT,
              id_key             TEXT,
              state              TEXT NOT NULL DEFAULT 'joined' CHECK (state IN ('joined','rewarded','void')),
              joined_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
              reward_order_id    UUID REFERENCES "order"(order_id),
              rewarded_at        TIMESTAMPTZ,
              void_reason        TEXT,
              friend_credit_id   UUID,
              inviter_credit_id  UUID,
              CONSTRAINT referral_not_self CHECK (inviter_id <> friend_id),
              CONSTRAINT referral_one_per_friend UNIQUE (friend_id),
              CONSTRAINT referral_rewarded_shape CHECK (state <> 'rewarded'
                OR (reward_order_id IS NOT NULL AND rewarded_at IS NOT NULL AND inviter_credit_id IS NOT NULL)),
              CONSTRAINT referral_void_shape CHECK ((state = 'void') = (void_reason IS NOT NULL))
            );
            CREATE INDEX idx_referral_inviter ON referral (inviter_id, state);
            CREATE INDEX idx_referral_device ON referral (device_fingerprint) WHERE device_fingerprint IS NOT NULL;

            CREATE TABLE commission_credit (
              credit_id       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              customer_id     UUID NOT NULL REFERENCES customer(customer_id),
              source          TEXT NOT NULL CHECK (source IN ('promo_code','referral_friend','referral_inviter')),
              kind            TEXT NOT NULL CHECK (kind IN ('waive','percent','amount')),
              percent_off     NUMERIC(7,4),
              amount_egp      NUMERIC(18,4),
              amount_left_egp NUMERIC(18,4),
              uses_total      SMALLINT,
              uses_left       SMALLINT,
              listing_id      UUID REFERENCES listing(listing_id),
              promo_code      TEXT REFERENCES promo_code(code),
              referral_id     UUID REFERENCES referral(referral_id),
              status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','exhausted','expired','voided')),
              granted_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
              expires_at      TIMESTAMPTZ,
              ended_at        TIMESTAMPTZ,
              CONSTRAINT credit_percent_shape CHECK ((kind = 'percent') = (percent_off IS NOT NULL)),
              CONSTRAINT credit_percent_range CHECK (percent_off IS NULL OR (percent_off > 0 AND percent_off < 100)),
              CONSTRAINT credit_amount_shape CHECK ((kind = 'amount') = (amount_egp IS NOT NULL AND amount_left_egp IS NOT NULL)),
              CONSTRAINT credit_amount_range CHECK (amount_egp IS NULL OR (amount_egp > 0 AND amount_left_egp >= 0 AND amount_left_egp <= amount_egp)),
              CONSTRAINT credit_uses_shape CHECK ((kind <> 'amount') = (uses_total IS NOT NULL AND uses_left IS NOT NULL)),
              CONSTRAINT credit_uses_range CHECK (uses_total IS NULL OR (uses_total >= 1 AND uses_left >= 0 AND uses_left <= uses_total)),
              CONSTRAINT credit_source_ref CHECK (
                (source = 'promo_code') = (promo_code IS NOT NULL)
                AND ((source <> 'promo_code') = (referral_id IS NOT NULL))
                AND (source <> 'referral_inviter' OR listing_id IS NULL)
                AND (source NOT IN ('promo_code','referral_friend') OR listing_id IS NOT NULL)),
              CONSTRAINT credit_ended_pair CHECK ((status = 'active') = (ended_at IS NULL)),
              CONSTRAINT credit_status_counters CHECK (
                (status <> 'active' OR (kind = 'amount' AND amount_left_egp > 0) OR (kind <> 'amount' AND uses_left > 0))
                AND (status <> 'exhausted' OR (kind = 'amount' AND amount_left_egp = 0) OR (kind <> 'amount' AND uses_left = 0)))
            );
            CREATE INDEX idx_commission_credit_customer ON commission_credit (customer_id, status);
            CREATE INDEX idx_commission_credit_listing ON commission_credit (listing_id) WHERE listing_id IS NOT NULL;
            CREATE INDEX idx_commission_credit_code ON commission_credit (promo_code, granted_at) WHERE promo_code IS NOT NULL;
            CREATE INDEX idx_commission_credit_expiry ON commission_credit (expires_at)
              WHERE status = 'active' AND expires_at IS NOT NULL;
            CREATE UNIQUE INDEX uq_commission_credit_listing_code ON commission_credit (listing_id, promo_code)
              WHERE promo_code IS NOT NULL AND status IN ('active','exhausted');

            ALTER TABLE referral
              ADD CONSTRAINT referral_friend_credit_fk FOREIGN KEY (friend_credit_id) REFERENCES commission_credit(credit_id),
              ADD CONSTRAINT referral_inviter_credit_fk FOREIGN KEY (inviter_credit_id) REFERENCES commission_credit(credit_id);
            ALTER TABLE promo_code_use
              ADD CONSTRAINT promo_code_use_credit_fk FOREIGN KEY (credit_id) REFERENCES commission_credit(credit_id);

            CREATE TABLE commission_credit_event (
              event_id                BIGSERIAL PRIMARY KEY,
              credit_id               UUID NOT NULL REFERENCES commission_credit(credit_id),
              kind                    TEXT NOT NULL CHECK (kind IN ('granted','spent','expired','voided')),
              order_id                UUID REFERENCES "order"(order_id),
              ordinary_commission_egp NUMERIC(18,4),
              charged_commission_egp  NUMERIC(18,4),
              reduction_egp           NUMERIC(18,4),
              amount_left_after_egp   NUMERIC(18,4),
              uses_left_after         SMALLINT,
              reason                  TEXT,
              actor_customer_id       UUID REFERENCES customer(customer_id),
              actor_staff_id          UUID REFERENCES staff(staff_id),
              created_at              TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
              txid                    BIGINT NOT NULL DEFAULT txid_current(),
              CONSTRAINT credit_event_one_actor CHECK (num_nonnulls(actor_customer_id, actor_staff_id) = 1),
              CONSTRAINT credit_event_spent_shape CHECK ((kind = 'spent') = (order_id IS NOT NULL
                AND ordinary_commission_egp IS NOT NULL AND charged_commission_egp IS NOT NULL AND reduction_egp IS NOT NULL)),
              CONSTRAINT credit_event_reduction CHECK (kind <> 'spent'
                OR (reduction_egp > 0 AND reduction_egp = ordinary_commission_egp - charged_commission_egp))
            );
            CREATE INDEX idx_commission_credit_event_credit ON commission_credit_event (credit_id, event_id);
            CREATE INDEX idx_commission_credit_event_order ON commission_credit_event (order_id) WHERE order_id IS NOT NULL;

            CREATE TABLE listing_promo (
              listing_id          UUID PRIMARY KEY REFERENCES listing(listing_id),
              promo_code          TEXT REFERENCES promo_code(code),
              referral_inviter_id UUID REFERENCES customer(customer_id),
              -- NULL for a code that gives no commission (a first-sale code that only pays the advance).
              credit_id           UUID REFERENCES commission_credit(credit_id),
              applied_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT listing_promo_one_source CHECK (num_nonnulls(promo_code, referral_inviter_id) = 1)
            );
            SQL);

        DB::unprepared(<<<'SQL'
            -- DH024: a credit's counters and status move forward only, never deleted.
            CREATE OR REPLACE FUNCTION commission_credit_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'commission credits are never deleted' USING ERRCODE = 'DH024';
              END IF;
              IF NEW.credit_id IS DISTINCT FROM OLD.credit_id OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                 OR NEW.source IS DISTINCT FROM OLD.source OR NEW.kind IS DISTINCT FROM OLD.kind
                 OR NEW.percent_off IS DISTINCT FROM OLD.percent_off OR NEW.amount_egp IS DISTINCT FROM OLD.amount_egp
                 OR NEW.uses_total IS DISTINCT FROM OLD.uses_total OR NEW.listing_id IS DISTINCT FROM OLD.listing_id
                 OR NEW.promo_code IS DISTINCT FROM OLD.promo_code OR NEW.referral_id IS DISTINCT FROM OLD.referral_id
                 OR NEW.granted_at IS DISTINCT FROM OLD.granted_at OR NEW.expires_at IS DISTINCT FROM OLD.expires_at THEN
                RAISE EXCEPTION 'only the counters and the status of a commission credit change' USING ERRCODE = 'DH024';
              END IF;
              IF OLD.status <> 'active' THEN
                RAISE EXCEPTION 'a commission credit that has ended never moves again' USING ERRCODE = 'DH024';
              END IF;
              IF NEW.uses_left > OLD.uses_left OR NEW.amount_left_egp > OLD.amount_left_egp THEN
                RAISE EXCEPTION 'a commission credit is never topped up' USING ERRCODE = 'DH024';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_commission_credit_guard BEFORE UPDATE OR DELETE ON commission_credit
              FOR EACH ROW EXECUTE FUNCTION commission_credit_guard();

            CREATE OR REPLACE FUNCTION commission_credit_event_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'commission credit events are append-only' USING ERRCODE = 'DH024';
            END $$;
            CREATE TRIGGER trg_commission_credit_event_immutable BEFORE UPDATE OR DELETE ON commission_credit_event
              FOR EACH ROW EXECUTE FUNCTION commission_credit_event_immutable();

            -- A counter or status that moved has its event in the same transaction.
            CREATE OR REPLACE FUNCTION commission_credit_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_ok BOOLEAN;
            BEGIN
              IF NEW.uses_left IS NOT DISTINCT FROM OLD.uses_left
                 AND NEW.amount_left_egp IS NOT DISTINCT FROM OLD.amount_left_egp
                 AND NEW.status IS NOT DISTINCT FROM OLD.status THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT EXISTS (SELECT 1 FROM commission_credit_event e
                              WHERE e.credit_id = NEW.credit_id AND e.txid = txid_current()
                                AND e.kind = CASE NEW.status WHEN 'expired' THEN 'expired' WHEN 'voided' THEN 'voided' ELSE 'spent' END)
                INTO v_ok;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF NOT v_ok THEN
                RAISE EXCEPTION 'commission credit % changed without its event', NEW.credit_id USING ERRCODE = 'DH024';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_commission_credit_recorded
              AFTER UPDATE ON commission_credit
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION commission_credit_recorded();

            -- Referral: forward only (joined -> rewarded | void), never deleted.
            CREATE OR REPLACE FUNCTION referral_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'referrals are never deleted' USING ERRCODE = 'DH024';
              END IF;
              IF NEW.referral_id IS DISTINCT FROM OLD.referral_id OR NEW.inviter_id IS DISTINCT FROM OLD.inviter_id
                 OR NEW.friend_id IS DISTINCT FROM OLD.friend_id OR NEW.code IS DISTINCT FROM OLD.code
                 OR NEW.joined_at IS DISTINCT FROM OLD.joined_at
                 OR NEW.device_fingerprint IS DISTINCT FROM OLD.device_fingerprint OR NEW.id_key IS DISTINCT FROM OLD.id_key
                 OR (OLD.listing_id IS NOT NULL AND NEW.listing_id IS DISTINCT FROM OLD.listing_id)
                 OR (OLD.friend_credit_id IS NOT NULL AND NEW.friend_credit_id IS DISTINCT FROM OLD.friend_credit_id)
                 OR (OLD.inviter_credit_id IS NOT NULL AND NEW.inviter_credit_id IS DISTINCT FROM OLD.inviter_credit_id) THEN
                RAISE EXCEPTION 'a referral keeps who, which code and when' USING ERRCODE = 'DH024';
              END IF;
              IF OLD.state <> 'joined' AND NEW.state IS DISTINCT FROM OLD.state THEN
                RAISE EXCEPTION 'a referral that has ended never moves again' USING ERRCODE = 'DH024';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_referral_guard BEFORE UPDATE OR DELETE ON referral
              FOR EACH ROW EXECUTE FUNCTION referral_guard();

            -- DH027: the code of a listing changes only while no buyer is in line.
            CREATE OR REPLACE FUNCTION listing_promo_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_listing UUID := COALESCE(NEW.listing_id, OLD.listing_id);
              v_state TEXT;
              v_line BOOLEAN;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT l.state::text INTO v_state FROM listing l WHERE l.listing_id = v_listing;
              SELECT EXISTS (SELECT 1 FROM buy_request b WHERE b.listing_id = v_listing AND b.state IN ('queued','accepted'))
                INTO v_line;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF v_state IS NULL OR v_state NOT IN ('draft','changes_requested','in_review','live') OR v_line THEN
                RAISE EXCEPTION 'the code of listing % cannot change now', v_listing USING ERRCODE = 'DH027';
              END IF;
              RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER trg_listing_promo_guard BEFORE INSERT OR UPDATE OR DELETE ON listing_promo
              FOR EACH ROW EXECUTE FUNCTION listing_promo_guard();
            SQL);

        DB::unprepared(<<<'SQL'
            -- The cost figures of a settlement, stored on the order (research R17, R4). All NULL for
            -- orders settled before this spec.
            ALTER TABLE "order"
              ADD COLUMN commission_ordinary_amount   NUMERIC(18,4),
              ADD COLUMN commission_given_up          NUMERIC(18,4),
              ADD COLUMN commission_waiver_kind       TEXT,
              ADD COLUMN commission_waiver_credit_id  UUID REFERENCES commission_credit(credit_id),
              ADD COLUMN commission_floor_egp         NUMERIC(18,4),
              ADD COLUMN commission_floor_applied     BOOLEAN,
              ADD COLUMN spread_given_up              NUMERIC(18,4),
              ADD COLUMN settled_at                   TIMESTAMPTZ,
              ADD CONSTRAINT order_cost_kind CHECK (commission_waiver_kind IS NULL
                OR commission_waiver_kind IN ('none','free_relist','market_maker','credit')),
              ADD CONSTRAINT order_cost_shape CHECK (
                num_nonnulls(commission_ordinary_amount, commission_given_up, commission_waiver_kind,
                             commission_floor_egp, commission_floor_applied, spread_given_up, settled_at) IN (0, 7)
                AND (commission_ordinary_amount IS NULL OR final_buyer_total IS NOT NULL)),
              ADD CONSTRAINT order_cost_given_up CHECK (commission_given_up IS NULL OR commission_given_up >= 0),
              ADD CONSTRAINT order_cost_sum CHECK (commission_ordinary_amount IS NULL
                OR commission_amount + commission_given_up = commission_ordinary_amount),
              ADD CONSTRAINT order_cost_credit CHECK (commission_waiver_kind IS NULL
                OR (commission_waiver_kind = 'credit') = (commission_waiver_credit_id IS NOT NULL)),
              ADD CONSTRAINT order_cost_none CHECK (commission_waiver_kind IS DISTINCT FROM 'none' OR commission_given_up = 0),
              -- The minimum commission is never broken where a floor applies (research R4).
              ADD CONSTRAINT order_cost_floor CHECK (commission_floor_applied IS NOT TRUE OR commission_amount >= commission_floor_egp),
              ADD CONSTRAINT order_cost_spread CHECK (spread_given_up IS NULL OR spread_given_up >= 0);
            CREATE INDEX idx_order_market_maker_cost ON "order" (settled_at) WHERE commission_waiver_kind = 'market_maker';

            -- The figures never change once stored.
            CREATE OR REPLACE FUNCTION order_cost_frozen() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.commission_ordinary_amount IS NOT NULL AND (
                   NEW.commission_ordinary_amount IS DISTINCT FROM OLD.commission_ordinary_amount
                   OR NEW.commission_given_up IS DISTINCT FROM OLD.commission_given_up
                   OR NEW.commission_waiver_kind IS DISTINCT FROM OLD.commission_waiver_kind
                   OR NEW.commission_waiver_credit_id IS DISTINCT FROM OLD.commission_waiver_credit_id
                   OR NEW.commission_floor_egp IS DISTINCT FROM OLD.commission_floor_egp
                   OR NEW.commission_floor_applied IS DISTINCT FROM OLD.commission_floor_applied
                   OR NEW.spread_given_up IS DISTINCT FROM OLD.spread_given_up
                   OR NEW.settled_at IS DISTINCT FROM OLD.settled_at) THEN
                RAISE EXCEPTION 'order % cost figures cannot change', OLD.order_id USING ERRCODE = 'DH026';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_order_cost_frozen BEFORE UPDATE ON "order"
              FOR EACH ROW EXECUTE FUNCTION order_cost_frozen();

            -- DH026: a credit waiver has exactly one matching spent event (deferred).
            CREATE OR REPLACE FUNCTION order_waiver_reconciled() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_n INT;
            BEGIN
              IF NEW.commission_waiver_kind IS DISTINCT FROM 'credit' THEN
                RETURN NULL;
              END IF;
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT count(*) INTO v_n FROM commission_credit_event e
               WHERE e.order_id = NEW.order_id AND e.kind = 'spent'
                 AND e.credit_id = NEW.commission_waiver_credit_id
                 AND e.reduction_egp = NEW.commission_given_up;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              IF v_n <> 1 THEN
                RAISE EXCEPTION 'order % credit waiver has % matching spent events', NEW.order_id, v_n USING ERRCODE = 'DH026';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER trg_order_waiver_reconciled
              AFTER INSERT OR UPDATE ON "order"
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION order_waiver_reconciled();
            SQL);

        DB::unprepared(<<<'SQL'
            -- Cross-customer facts as plain answers (the listing_price_locked scope-switch pattern).
            CREATE OR REPLACE FUNCTION promo_code_id_uses(p_code TEXT, p_id_key TEXT, p_since TIMESTAMPTZ) RETURNS integer
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_n INTEGER;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT count(*)::int INTO v_n
                FROM promo_code_use u LEFT JOIN commission_credit c ON c.credit_id = u.credit_id
               WHERE u.code = p_code AND u.allowed AND u.id_key = p_id_key
                 AND ((u.credit_id IS NOT NULL AND c.status <> 'voided')
                      OR (u.credit_id IS NULL AND u.listing_id IS NOT NULL AND EXISTS
                            (SELECT 1 FROM listing_promo lp WHERE lp.listing_id = u.listing_id AND lp.promo_code = u.code)))
                 AND (p_since IS NULL OR u.used_at >= p_since);
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_n;
            END $$;

            -- EGP of transaction value counted against a code's monthly cap (non-voided uses).
            CREATE OR REPLACE FUNCTION promo_code_value_since(p_code TEXT, p_since TIMESTAMPTZ) RETURNS numeric
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_sum NUMERIC;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT COALESCE(sum(u.amount_egp), 0) INTO v_sum
                FROM promo_code_use u LEFT JOIN commission_credit c ON c.credit_id = u.credit_id
               WHERE u.code = p_code AND u.allowed AND u.used_at >= p_since
                 AND ((u.credit_id IS NOT NULL AND c.status <> 'voided')
                      OR (u.credit_id IS NULL AND u.listing_id IS NOT NULL AND EXISTS
                            (SELECT 1 FROM listing_promo lp WHERE lp.listing_id = u.listing_id AND lp.promo_code = u.code)));
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_sum;
            END $$;

            CREATE OR REPLACE FUNCTION referral_device_count(p_device TEXT) RETURNS integer
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_n INTEGER;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT count(*)::int INTO v_n FROM referral WHERE device_fingerprint = p_device AND state <> 'void';
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_n;
            END $$;

            CREATE OR REPLACE FUNCTION referral_inviter_count(p_inviter UUID) RETURNS integer
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_n INTEGER;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT count(*)::int INTO v_n FROM referral WHERE inviter_id = p_inviter AND state <> 'void';
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_n;
            END $$;

            -- A friend resolves a code by value, never by reading `referral_code`.
            CREATE OR REPLACE FUNCTION referral_lookup(p_code TEXT) RETURNS uuid
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_id UUID;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT customer_id INTO v_id FROM referral_code WHERE code = p_code;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN v_id;
            END $$;

            -- Is `p_id_key` the key of this customer (the same person under another account)?
            CREATE OR REPLACE FUNCTION referral_same_person(p_customer UUID, p_id_key TEXT) RETURNS boolean
            LANGUAGE plpgsql AS $$
            DECLARE
              prev_scope TEXT := COALESCE(current_setting('app.rls_scope', true), '');
              v_hash TEXT;
            BEGIN
              PERFORM set_config('app.rls_scope', 'system', true);
              SELECT d.id_number_hash INTO v_hash FROM identity_document d
               WHERE d.customer_id = p_customer AND d.status = 'verified' AND d.id_number_hash IS NOT NULL
               ORDER BY d.reviewed_at DESC NULLS LAST LIMIT 1;
              PERFORM set_config('app.rls_scope', prev_scope, true);
              RETURN p_id_key = COALESCE(v_hash, 'account:' || p_customer::text);
            END $$;
            SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE referral_code            ENABLE ROW LEVEL SECURITY;
            ALTER TABLE referral_code            FORCE  ROW LEVEL SECURITY;
            ALTER TABLE referral                 ENABLE ROW LEVEL SECURITY;
            ALTER TABLE referral                 FORCE  ROW LEVEL SECURITY;
            ALTER TABLE commission_credit        ENABLE ROW LEVEL SECURITY;
            ALTER TABLE commission_credit        FORCE  ROW LEVEL SECURITY;
            ALTER TABLE commission_credit_event  ENABLE ROW LEVEL SECURITY;
            ALTER TABLE commission_credit_event  FORCE  ROW LEVEL SECURITY;
            ALTER TABLE listing_promo            ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_promo            FORCE  ROW LEVEL SECURITY;

            -- Staff (elevated): everything.
            CREATE POLICY referral_code_staff ON referral_code FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY referral_staff ON referral FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY commission_credit_staff ON commission_credit FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY commission_credit_event_staff ON commission_credit_event FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));
            CREATE POLICY listing_promo_staff ON listing_promo FOR ALL
              USING ((SELECT dahab_rls_elevated())) WITH CHECK ((SELECT dahab_rls_elevated()));

            -- The owner reads their own rows.
            CREATE POLICY referral_code_owner ON referral_code FOR SELECT
              USING (customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY referral_parties ON referral FOR SELECT
              USING (inviter_id = (SELECT dahab_current_customer_id()) OR friend_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY commission_credit_owner ON commission_credit FOR SELECT
              USING (customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY commission_credit_event_follow ON commission_credit_event FOR SELECT
              USING (EXISTS (SELECT 1 FROM commission_credit c WHERE c.credit_id = commission_credit_event.credit_id));
            CREATE POLICY listing_promo_owner ON listing_promo FOR SELECT
              USING (EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_promo.listing_id
                               AND l.seller_id = (SELECT dahab_current_customer_id())));

            -- The `promo` scope (research R26): the caller's own code, credit and referral rows, nothing else.
            CREATE POLICY referral_code_promo_write ON referral_code FOR ALL
              USING ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY referral_promo_write ON referral FOR ALL
              USING ((SELECT dahab_rls_scope()) = 'promo' AND friend_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND friend_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY commission_credit_promo_write ON commission_credit FOR ALL
              USING ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND customer_id = (SELECT dahab_current_customer_id()));
            CREATE POLICY commission_credit_event_promo_insert ON commission_credit_event FOR INSERT
              WITH CHECK ((SELECT dahab_rls_scope()) = 'promo'
                          AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
                          AND EXISTS (SELECT 1 FROM commission_credit c WHERE c.credit_id = commission_credit_event.credit_id));
            CREATE POLICY listing_promo_promo_write ON listing_promo FOR ALL
              USING ((SELECT dahab_rls_scope()) = 'promo' AND EXISTS (SELECT 1 FROM listing l
                       WHERE l.listing_id = listing_promo.listing_id AND l.seller_id = (SELECT dahab_current_customer_id())))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'promo' AND EXISTS (SELECT 1 FROM listing l
                       WHERE l.listing_id = listing_promo.listing_id AND l.seller_id = (SELECT dahab_current_customer_id())));

            -- The `order` scope: the buyer settling an order spends the credit of that order's seller.
            -- The inner SELECT on "order" is filtered by order_isolation: the caller's own orders only.
            CREATE POLICY commission_credit_order_spend ON commission_credit FOR ALL
              USING ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.seller_id = commission_credit.customer_id))
              WITH CHECK ((SELECT dahab_rls_scope()) = 'order'
                     AND EXISTS (SELECT 1 FROM "order" o WHERE o.seller_id = commission_credit.customer_id));
            CREATE POLICY commission_credit_event_order_insert ON commission_credit_event FOR INSERT
              WITH CHECK ((SELECT dahab_rls_scope()) = 'order'
                          AND actor_customer_id = (SELECT dahab_current_customer_id()) AND actor_staff_id IS NULL
                          AND EXISTS (SELECT 1 FROM commission_credit c WHERE c.credit_id = commission_credit_event.credit_id));

            -- The `promo` scope reads the codes and writes the use log (it is not an elevation).
            DROP POLICY promo_code_staff ON promo_code;
            CREATE POLICY promo_code_staff ON promo_code FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','promo'))
              WITH CHECK ((SELECT dahab_rls_elevated()));
            DROP POLICY promo_code_use_staff ON promo_code_use;
            CREATE POLICY promo_code_use_staff ON promo_code_use FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','promo'))
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) IN ('queue','promo'));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT set_config('app.rls_scope', 'maintenance', true)");
        $used = DB::selectOne(<<<'SQL'
            SELECT (EXISTS (SELECT 1 FROM commission_credit) OR EXISTS (SELECT 1 FROM referral)
                 OR EXISTS (SELECT 1 FROM referral_code) OR EXISTS (SELECT 1 FROM listing_promo)
                 OR EXISTS (SELECT 1 FROM "order" WHERE commission_ordinary_amount IS NOT NULL)) AS used
            SQL)->used;
        if ($used) {
            throw new RuntimeException('Spec 023 commission credits, referrals or settlement cost figures exist: this migration cannot be rolled back.');
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY promo_code_staff ON promo_code;
            CREATE POLICY promo_code_staff ON promo_code FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'queue')
              WITH CHECK ((SELECT dahab_rls_elevated()));
            DROP POLICY promo_code_use_staff ON promo_code_use;
            CREATE POLICY promo_code_use_staff ON promo_code_use FOR ALL
              USING ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'queue')
              WITH CHECK ((SELECT dahab_rls_elevated()) OR (SELECT dahab_rls_scope()) = 'queue');

            DROP TRIGGER IF EXISTS trg_order_waiver_reconciled ON "order";
            DROP FUNCTION IF EXISTS order_waiver_reconciled();
            DROP TRIGGER IF EXISTS trg_order_cost_frozen ON "order";
            DROP FUNCTION IF EXISTS order_cost_frozen();
            DROP INDEX IF EXISTS idx_order_market_maker_cost;
            ALTER TABLE "order"
              DROP CONSTRAINT order_cost_spread, DROP CONSTRAINT order_cost_floor, DROP CONSTRAINT order_cost_none,
              DROP CONSTRAINT order_cost_credit, DROP CONSTRAINT order_cost_sum, DROP CONSTRAINT order_cost_given_up,
              DROP CONSTRAINT order_cost_shape, DROP CONSTRAINT order_cost_kind,
              DROP COLUMN settled_at, DROP COLUMN spread_given_up, DROP COLUMN commission_floor_applied,
              DROP COLUMN commission_floor_egp, DROP COLUMN commission_waiver_credit_id, DROP COLUMN commission_waiver_kind,
              DROP COLUMN commission_given_up, DROP COLUMN commission_ordinary_amount;

            ALTER TABLE promo_code_use DROP CONSTRAINT promo_code_use_credit_fk;
            DROP TABLE listing_promo;
            DROP TABLE commission_credit_event;
            ALTER TABLE referral DROP CONSTRAINT referral_friend_credit_fk, DROP CONSTRAINT referral_inviter_credit_fk;
            DROP TABLE commission_credit;
            DROP TABLE referral;
            DROP TABLE referral_code;

            DROP FUNCTION IF EXISTS referral_same_person(UUID, TEXT);
            DROP FUNCTION IF EXISTS referral_lookup(TEXT);
            DROP FUNCTION IF EXISTS referral_inviter_count(UUID);
            DROP FUNCTION IF EXISTS referral_device_count(TEXT);
            DROP FUNCTION IF EXISTS promo_code_value_since(TEXT, TIMESTAMPTZ);
            DROP FUNCTION IF EXISTS promo_code_id_uses(TEXT, TEXT, TIMESTAMPTZ);
            DROP FUNCTION IF EXISTS listing_promo_guard();
            DROP FUNCTION IF EXISTS referral_guard();
            DROP FUNCTION IF EXISTS commission_credit_recorded();
            DROP FUNCTION IF EXISTS commission_credit_event_immutable();
            DROP FUNCTION IF EXISTS commission_credit_guard();
            SQL);
    }
};
