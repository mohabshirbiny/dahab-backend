<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Listings (spec 010). Mirrors docs/Database schema: `listing_state`
 * (01_schema_core.sql, with `rejected`), section 7 of 04_schema_market.sql
 * (listing, media, branch options, ownership declaration, the history table
 * `listing_state_change`) plus `listing_queue_seq`, and from
 * 05_schema_security.sql the allowed moves (`listing_transition`), the guard
 * triggers (SQLSTATE DH004) and the forced row-level security with the
 * read-only `market` scope policies.
 *
 * Not created here: `sync_listing_queue` / `trg_sync_queue` (they need
 * `buy_request`, a later module).
 *
 * Rollback drops every listing, its media rows and its history. No money is
 * involved (nothing here posts to the ledger); stored media objects are not
 * removed from the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // migrate:fresh drops tables but not types (same guard as the ledger migration).
        DB::unprepared('DROP TYPE IF EXISTS listing_state CASCADE');

        DB::unprepared(<<<'SQL'
            CREATE TYPE listing_state AS ENUM (
              'draft',            -- being created, not submitted
              'in_review',        -- submitted, awaiting Dahab listing review
              'changes_requested',-- sent back to the seller for a better photo/detail
              'live',             -- visible on the market, price follows the rate
              'reserved',         -- has >=1 active buy request in the queue
              'accepted',         -- seller accepted a buyer; heading to inspection
              'at_inspection',    -- piece physically at the branch, being inspected
              'settling',         -- inspection done, price/settlement resolving
              'sold',             -- completed sale
              'withdrawn',        -- taken down by seller/admin while live
              'suspended_hold',   -- frozen by a category stop / account suspension
              -- Buyer paid in full but never collected; piece waits at IGI. After the
              -- collect window a status is shown to the buyer ("window passed, Dahab is
              -- not liable"); disposition (hand over / compensate) is a manual decision
              -- taken when the buyer makes contact. RESOLVED policy (see Part 3 logic).
              'uncollected_expired',
              -- Buyer did NOT pay the balance within the pay window. The piece returns
              -- to the seller, who collects it and receives 50% of the commission as
              -- compensation for their time. Waiting for the seller to come and collect.
              'awaiting_seller_return',
              -- The returned piece sat awaiting the seller past the return window and
              -- the seller never came. Status shown ("window passed, not our liability");
              -- Dahab then hands it over or compensates. Manual, like uncollected_expired.
              'seller_unclaimed',
              -- spec 010: rejected by the reviewer. Final, like 'withdrawn': neither has
              -- an outgoing move; the piece is sold again only as a new listing.
              'rejected'
            );

            CREATE TABLE listing_transition (
              from_state   listing_state NOT NULL,
              to_state     listing_state NOT NULL,
              note         TEXT,
              PRIMARY KEY (from_state, to_state)
            );

            INSERT INTO listing_transition (from_state, to_state, note) VALUES
              ('draft','in_review','submitted for listing review'),
              ('in_review','changes_requested','reviewer asked for a better photo/detail'),
              ('changes_requested','in_review','resubmitted'),
              ('in_review','live','approved and published'),
              ('in_review','rejected','rejected by the reviewer (spec 010); final'),
              ('live','reserved','first buy request queued'),
              ('reserved','live','queue emptied (all requests released)'),
              ('reserved','accepted','seller accepted the first in the queue'),
              ('accepted','at_inspection','piece delivered to branch'),
              ('at_inspection','settling','inspection recorded'),
              ('settling','sold','completed'),
              ('settling','live','sale fell through; back on the market'),
              -- withdrawn is FINAL (spec 010): no withdrawn -> in_review, no withdrawn -> live.
              ('live','withdrawn','seller/admin took it down'),
              ('reserved','withdrawn','taken down (no locked price affected)'),
              ('live','suspended_hold','category paused / account suspended'),
              ('reserved','suspended_hold','category paused'),
              ('suspended_hold','live','category reopened'),
              -- Buyer paid in full but never collected within the collect window. The
              -- piece stays at IGI; a status is shown to the buyer. Disposition (hand
              -- over / compensate) is a manual decision when the buyer makes contact.
              ('sold','uncollected_expired','buyer paid, never collected, collect window passed'),
              ('uncollected_expired','sold','buyer made contact and collected'),
              -- Buyer did NOT pay the balance in time: the piece returns to the seller,
              -- who collects it and receives 50% of the deposit as compensation. The
              -- listing tracks the physical return of the piece to the seller.
              ('settling','awaiting_seller_return','buyer did not pay; piece returns to the seller'),
              ('accepted','awaiting_seller_return','buyer did not pay before/at delivery; piece returns to the seller'),
              ('at_inspection','awaiting_seller_return','buyer did not pay; piece returns to the seller'),
              ('awaiting_seller_return','withdrawn','seller collected the returned piece'),
              ('awaiting_seller_return','live','seller chose to relist instead of collecting'),
              -- Seller never came for the returned piece within the seller-return window.
              ('awaiting_seller_return','seller_unclaimed','seller-return window passed; seller never came'),
              ('seller_unclaimed','withdrawn','seller finally collected / Dahab handed over'),
              ('seller_unclaimed','live','relisted after contact');
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE listing (
              listing_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              seller_id      UUID NOT NULL REFERENCES customer(customer_id),
              category       piece_category NOT NULL,
              piece_type_id  SMALLINT NOT NULL REFERENCES piece_type(piece_type_id),
              karat_code     SMALLINT REFERENCES karat(karat_code),   -- NULL for pure diamond
              stated_weight_g NUMERIC(10,3),                           -- NULL for pure diamond
              making_charge_per_g NUMERIC(18,4),                       -- gold making charge
              asking_price   NUMERIC(18,4),                            -- stones / whole-piece ask
              description    TEXT,
              state          listing_state NOT NULL DEFAULT 'draft',
              -- Denormalised current queue depth for fast display; kept in step with
              -- buy_request via trigger. Source of truth is the buy_request rows.
              active_queue_count INTEGER NOT NULL DEFAULT 0,
              created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
              listed_at      TIMESTAMPTZ,                              -- when it FIRST went live (set by listing_guard)
              -- spec 010: when the state last changed (stamped by listing_guard). Orders
              -- the review queue "oldest first" and gives the waiting time.
              state_changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT gold_needs_karat_weight CHECK (
                category = 'diamond'
                OR (karat_code IS NOT NULL AND stated_weight_g IS NOT NULL)
              ),
              CONSTRAINT queue_count_nonneg CHECK (active_queue_count >= 0),
              -- spec 010: gold is priced by making charge, stones by one asking price.
              CONSTRAINT listing_price_shape CHECK (
                (category = 'gold' AND making_charge_per_g IS NOT NULL AND asking_price IS NULL)
                OR (category <> 'gold' AND asking_price IS NOT NULL AND making_charge_per_g IS NULL)
              ),
              -- spec 010: positive weight; money people type has at most 2 decimals.
              CONSTRAINT listing_amounts CHECK (
                (stated_weight_g IS NULL OR stated_weight_g > 0)
                AND (making_charge_per_g IS NULL
                     OR (making_charge_per_g >= 0 AND making_charge_per_g = round(making_charge_per_g, 2)))
                AND (asking_price IS NULL OR (asking_price > 0 AND asking_price = round(asking_price, 2)))
              ),
              CONSTRAINT listing_description_len CHECK (description IS NULL OR char_length(description) <= 2000),
              -- spec 010: anything that has been on the market knows when it went live.
              CONSTRAINT listing_listed_shape CHECK (
                listed_at IS NOT NULL OR state IN ('draft','in_review','changes_requested','rejected')
              )
            );

            CREATE INDEX idx_listing_state  ON listing(state, state_changed_at);
            CREATE INDEX idx_listing_seller ON listing(seller_id, created_at DESC);
            -- spec 010: the public market page (newest first).
            CREATE INDEX idx_listing_market ON listing(listed_at DESC, listing_id)
              WHERE state IN ('live','reserved');

            -- Photos / video / uploaded original invoice / uploaded stone certificate.
            -- Only the invoice is private; the stone certificate is public once the
            -- listing is live (spec 010). storage_ref names a chunk-encrypted object on
            -- the private disk; mime is what it is served as; position orders the photos.
            CREATE TABLE listing_media (
              media_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              listing_id   UUID NOT NULL REFERENCES listing(listing_id),
              kind         TEXT NOT NULL CHECK (kind IN
                             ('photo','video','invoice','stone_certificate')),
              storage_ref  TEXT NOT NULL,
              is_private   BOOLEAN NOT NULL DEFAULT FALSE,   -- invoice stays private pre-sale
              mime         TEXT NOT NULL,                    -- spec 010
              position     SMALLINT NOT NULL DEFAULT 0,      -- spec 010
              created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE INDEX idx_listing_media_listing ON listing_media(listing_id, kind, position);

            -- Branches the seller named at listing (the willing set). The final
            -- branch chosen at acceptance MUST be one of these.
            CREATE TABLE listing_branch_option (
              listing_id  UUID NOT NULL REFERENCES listing(listing_id),
              branch_id   SMALLINT NOT NULL REFERENCES branch(branch_id),
              PRIMARY KEY (listing_id, branch_id)
            );

            -- Ownership declaration accepted at listing (evidence trail).
            -- (Additionally recorded in agreement_acceptance; this ties it to a piece.)
            CREATE TABLE listing_ownership_declaration (
              listing_id   UUID PRIMARY KEY REFERENCES listing(listing_id),
              customer_id  UUID NOT NULL REFERENCES customer(customer_id),
              accepted_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
              legal_doc_id SMALLINT NOT NULL REFERENCES legal_document(legal_doc_id)
            );

            -- Listing history (added by spec 010). One permanent row per state change:
            -- who moved the listing, from what to what, and the message or reason
            -- (the reviewer's "changes needed" text, a rejection or take-down reason,
            -- 'account_suspended' / 'account_reinstated' for holds). from_state NULL is
            -- the creation. listing_transition (Part 4) is the table of ALLOWED moves;
            -- this is the record of the moves that happened. txid ties the row to the
            -- transaction of the move: trg_listing_change_recorded refuses a commit that
            -- moved a listing without one.
            CREATE TABLE listing_state_change (
              change_id    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              listing_id   UUID NOT NULL REFERENCES listing(listing_id),
              from_state   listing_state,
              to_state     listing_state NOT NULL,
              actor_customer_id UUID REFERENCES customer(customer_id),
              actor_staff_id    UUID REFERENCES staff(staff_id),
              note         TEXT CHECK (char_length(note) <= 1000),
              changed_at   TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),  -- the moment of the move, not of the transaction
              txid         BIGINT NOT NULL DEFAULT txid_current(),
              CONSTRAINT listing_change_one_actor CHECK (
                (actor_customer_id IS NULL) <> (actor_staff_id IS NULL)
              ),
              -- A send-back, a rejection and a staff take-down always say why.
              CONSTRAINT listing_change_note_required CHECK (
                note IS NOT NULL OR NOT (
                  to_state IN ('changes_requested','rejected')
                  OR (to_state = 'withdrawn' AND actor_staff_id IS NOT NULL)
                )
              )
            );

            CREATE INDEX idx_listing_state_change_listing ON listing_state_change(listing_id, changed_at);

            CREATE OR REPLACE FUNCTION listing_state_change_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'listing history is append-only' USING ERRCODE = 'DH004';
            END $$;

            CREATE TRIGGER trg_listing_state_change_immutable
              BEFORE UPDATE OR DELETE ON listing_state_change
              FOR EACH ROW EXECUTE FUNCTION listing_state_change_immutable();
            -- Per-listing monotonic queue position. A dedicated table of counters
            -- avoids gaps-vs-reuse ambiguity and races under concurrency.
            CREATE TABLE listing_queue_seq (
              listing_id UUID PRIMARY KEY REFERENCES listing(listing_id),
              next_pos   INTEGER NOT NULL DEFAULT 1
            );
            SQL);

        DB::unprepared(<<<'SQL'
            -- Listing guards (added by spec 010) ------------------------------------
            -- A listing is born a draft, is never deleted, keeps its seller, and changes
            -- state only along listing_transition. SQLSTATE DH004 -> 409
            -- illegal_listing_transition. The guard also stamps state_changed_at and
            -- sets listed_at the first time the listing goes live (the wall clock, so
            -- two moves in one transaction keep their order). `withdrawn` and
            -- `rejected` have no outgoing row: both are final.
            CREATE OR REPLACE FUNCTION listing_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'listings are never deleted' USING ERRCODE = 'DH004';
              END IF;
              IF TG_OP = 'INSERT' THEN
                IF NEW.state <> 'draft' THEN
                  RAISE EXCEPTION 'a listing starts as a draft, not %', NEW.state USING ERRCODE = 'DH004';
                END IF;
                RETURN NEW;
              END IF;
              IF NEW.seller_id IS DISTINCT FROM OLD.seller_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'listing % identity columns cannot change', OLD.listing_id USING ERRCODE = 'DH004';
              END IF;
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM listing_transition
                               WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'illegal listing transition % -> %', OLD.state, NEW.state USING ERRCODE = 'DH004';
                END IF;
                NEW.state_changed_at := clock_timestamp();
                IF NEW.state = 'live' AND NEW.listed_at IS NULL THEN
                  NEW.listed_at := clock_timestamp();
                END IF;
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_listing_guard
              BEFORE INSERT OR UPDATE OR DELETE ON listing
              FOR EACH ROW EXECUTE FUNCTION listing_guard();

            -- Every move is recorded: at commit, the creation and each state change must
            -- have its listing_state_change row written in the same transaction.
            CREATE OR REPLACE FUNCTION listing_change_recorded() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'UPDATE' AND NEW.state IS NOT DISTINCT FROM OLD.state THEN
                RETURN NULL;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM listing_state_change c
                             WHERE c.listing_id = NEW.listing_id
                               AND c.to_state = NEW.state
                               AND c.txid = txid_current()) THEN
                RAISE EXCEPTION 'listing % moved to % without a history row', NEW.listing_id, NEW.state
                  USING ERRCODE = 'DH004';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER trg_listing_change_recorded
              AFTER INSERT OR UPDATE OF state ON listing
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION listing_change_recorded();
            SQL);

        DB::unprepared(<<<'SQL'
            -- Listings (added by spec 010) --------------------------------------------
            -- A seller sees and changes only their own listings and what hangs off them;
            -- staff act in the elevated 'staff' scope.
            --
            -- PUBLIC MARKET (product-owner decision, spec 010 — replaces the earlier
            -- "dedicated view" note): there is NO view and no separate low-privilege
            -- role. The unauthenticated market request runs in the read-only scope
            -- 'market' (not an elevation; it carries no customer id):
            --     public market -> 'market' scope -> listing -> public response Resource
            -- In that scope the engine returns only listings in state live/reserved,
            -- their branch options and their NON-private media, and accepts no write
            -- (the scope has SELECT policies only). Column privacy (seller_id) is the
            -- job of the market Resources, guarded by MarketLeakTest / MarketScopeTest.
            ALTER TABLE listing                       ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing                       FORCE  ROW LEVEL SECURITY;
            ALTER TABLE listing_media                 ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_media                 FORCE  ROW LEVEL SECURITY;
            ALTER TABLE listing_branch_option         ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_branch_option         FORCE  ROW LEVEL SECURITY;
            ALTER TABLE listing_ownership_declaration ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_ownership_declaration FORCE  ROW LEVEL SECURITY;
            ALTER TABLE listing_state_change          ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_state_change          FORCE  ROW LEVEL SECURITY;
            ALTER TABLE listing_queue_seq             ENABLE ROW LEVEL SECURITY;
            ALTER TABLE listing_queue_seq             FORCE  ROW LEVEL SECURITY;

            CREATE POLICY listing_isolation ON listing FOR ALL
              USING      (dahab_rls_elevated() OR seller_id = dahab_current_customer_id())
              WITH CHECK (dahab_rls_elevated() OR seller_id = dahab_current_customer_id());
            CREATE POLICY listing_market_read ON listing FOR SELECT
              USING (dahab_rls_scope() = 'market' AND state IN ('live','reserved'));

            -- Child rows follow their listing. The inner SELECT on listing is itself
            -- filtered by the listing policies above.
            CREATE POLICY listing_media_isolation ON listing_media FOR ALL
              USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_media.listing_id AND l.seller_id = dahab_current_customer_id()))
              WITH CHECK (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_media.listing_id AND l.seller_id = dahab_current_customer_id()));
            CREATE POLICY listing_media_market_read ON listing_media FOR SELECT
              USING (dahab_rls_scope() = 'market' AND NOT is_private
                     AND EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_media.listing_id));

            CREATE POLICY listing_branch_option_isolation ON listing_branch_option FOR ALL
              USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_branch_option.listing_id AND l.seller_id = dahab_current_customer_id()))
              WITH CHECK (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_branch_option.listing_id AND l.seller_id = dahab_current_customer_id()));
            CREATE POLICY listing_branch_option_market_read ON listing_branch_option FOR SELECT
              USING (dahab_rls_scope() = 'market'
                     AND EXISTS (SELECT 1 FROM listing l WHERE l.listing_id = listing_branch_option.listing_id));

            -- Never visible to the market.
            CREATE POLICY listing_ownership_declaration_isolation ON listing_ownership_declaration FOR ALL
              USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
              WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

            CREATE POLICY listing_queue_seq_isolation ON listing_queue_seq FOR ALL
              USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_queue_seq.listing_id AND l.seller_id = dahab_current_customer_id()))
              WITH CHECK (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_queue_seq.listing_id AND l.seller_id = dahab_current_customer_id()));

            -- A seller reads the history of their own listing; a row written in the
            -- customer scope must name that customer as the actor, never staff.
            CREATE POLICY listing_state_change_isolation ON listing_state_change FOR ALL
              USING      (dahab_rls_elevated() OR EXISTS (SELECT 1 FROM listing l
                            WHERE l.listing_id = listing_state_change.listing_id AND l.seller_id = dahab_current_customer_id()))
              WITH CHECK (dahab_rls_elevated() OR (
                            actor_customer_id = dahab_current_customer_id() AND actor_staff_id IS NULL
                            AND EXISTS (SELECT 1 FROM listing l
                              WHERE l.listing_id = listing_state_change.listing_id AND l.seller_id = dahab_current_customer_id())));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS listing_state_change;
            DROP TABLE IF EXISTS listing_queue_seq;
            DROP TABLE IF EXISTS listing_ownership_declaration;
            DROP TABLE IF EXISTS listing_branch_option;
            DROP TABLE IF EXISTS listing_media;
            DROP TABLE IF EXISTS listing;
            DROP TABLE IF EXISTS listing_transition;
            DROP FUNCTION IF EXISTS listing_change_recorded();
            DROP FUNCTION IF EXISTS listing_guard();
            DROP FUNCTION IF EXISTS listing_state_change_immutable();
            DROP TYPE IF EXISTS listing_state;
        SQL);
    }
};
