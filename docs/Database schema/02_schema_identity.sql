-- =====================================================================
-- Part 1b of 4: identity, staff, customers, documents, agreements
-- search_path assumed = dahab, public
-- =====================================================================
SET search_path = dahab, public;

-- ---------------------------------------------------------------------
-- 4. Staff (internal actors). Every privileged action references one.
--    Roles map to the permission matrix in the admin-roles document.
--    Founders (ceo/coo) are unrestricted and distinguished ONLY by the
--    audit log. Wallet access starts limited to ceo + finance as an
--    application permission (spec 002: no per-staff-role DB grants).
-- ---------------------------------------------------------------------
-- Changed by spec 002: a staff member's roles live in Spatie's
-- model_has_roles; `role` and `igi_has_branch` are removed. Founder status
-- is a flag no endpoint writes; exactly one row is the non-login system
-- actor used by scheduled jobs.
CREATE TABLE staff (
  staff_id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name     TEXT NOT NULL,
  email         CITEXT UNIQUE NOT NULL,
  phone         TEXT,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  -- Optional for anyone. Branch-scoped permissions only authorize records
  -- of this branch (e.g. the shared IGI branch login, which logs the branch).
  branch_id     SMALLINT REFERENCES branch(branch_id),
  -- Founders (seeded: ceo@ and coo@). Never changeable through the API.
  is_founder    BOOLEAN NOT NULL DEFAULT FALSE,
  -- The single "System" actor for scheduled jobs; cannot sign in.
  is_system     BOOLEAN NOT NULL DEFAULT FALSE,
  created_by    UUID REFERENCES staff(staff_id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT staff_system_not_founder CHECK (NOT (is_system AND is_founder))
);

-- ---------------------------------------------------------------------
-- Staff foreign keys for the settings and pricing tables of
-- 01_schema_core.sql §3 / §3b (spec 005). Added here because staff is
-- created after the core tables.
-- ---------------------------------------------------------------------
ALTER TABLE setting                        ADD FOREIGN KEY (updated_by)   REFERENCES staff(staff_id);
ALTER TABLE setting_history                ADD FOREIGN KEY (changed_by)   REFERENCES staff(staff_id);
ALTER TABLE manual_gold_price              ADD FOREIGN KEY (entered_by)   REFERENCES staff(staff_id);
ALTER TABLE manual_gold_price              ADD FOREIGN KEY (confirmed_by) REFERENCES staff(staff_id);
ALTER TABLE gold_price                     ADD FOREIGN KEY (recorded_by)  REFERENCES staff(staff_id);
ALTER TABLE karat_price_adjustment         ADD FOREIGN KEY (updated_by)   REFERENCES staff(staff_id);
ALTER TABLE karat_price_adjustment_history ADD FOREIGN KEY (changed_by)   REFERENCES staff(staff_id);
CREATE UNIQUE INDEX one_system_staff ON staff ((true)) WHERE is_system;

-- Now that staff exists, wire the settings audit FKs.
ALTER TABLE setting
  ADD CONSTRAINT setting_updated_by_fk FOREIGN KEY (updated_by) REFERENCES staff(staff_id);
ALTER TABLE setting_history
  ADD CONSTRAINT setting_hist_changed_by_fk FOREIGN KEY (changed_by) REFERENCES staff(staff_id);

-- Founder-account security: a sign-in from a new device is held until the
-- other founder confirms; either founder can freeze the other instantly.
CREATE TABLE founder_device_approval (
  approval_id   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  staff_id      UUID NOT NULL REFERENCES staff(staff_id),
  device_fingerprint TEXT NOT NULL,
  requested_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_by   UUID REFERENCES staff(staff_id),
  approved_at   TIMESTAMPTZ,
  CONSTRAINT approver_is_not_self CHECK (approved_by IS NULL OR approved_by <> staff_id)
);

CREATE TABLE account_freeze (
  freeze_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  frozen_staff_id UUID NOT NULL REFERENCES staff(staff_id),
  frozen_by     UUID NOT NULL REFERENCES staff(staff_id),
  frozen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Unfreeze needs BOTH founders: two confirmations recorded here.
  unfreeze_confirm_1 UUID REFERENCES staff(staff_id),
  unfreeze_confirm_2 UUID REFERENCES staff(staff_id),
  unfrozen_at   TIMESTAMPTZ,
  CONSTRAINT no_self_freeze CHECK (frozen_by <> frozen_staff_id)
);

-- ---------------------------------------------------------------------
-- 5. Customers. Browsing needs no account; verification is required
--    before the first sale or purchase. A foreign phone/passport is fine.
-- ---------------------------------------------------------------------
CREATE TABLE customer (
  customer_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  display_ref     TEXT UNIQUE NOT NULL,          -- e.g. "4417" shown in UI
  phone           TEXT UNIQUE NOT NULL,          -- sign-in identity
  email           CITEXT UNIQUE,
  full_name       TEXT,                          -- as on ID, once verified
  preferred_lang  TEXT NOT NULL DEFAULT 'ar' CHECK (preferred_lang IN ('ar','en')),
  is_verified     BOOLEAN NOT NULL DEFAULT FALSE,
  is_suspended    BOOLEAN NOT NULL DEFAULT FALSE,
  suspended_reason TEXT,
  suspended_by    UUID REFERENCES staff(staff_id),
  suspended_at    TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT suspended_needs_actor CHECK (
    NOT is_suspended OR (suspended_by IS NOT NULL AND suspended_reason IS NOT NULL)
  )
);

-- (spec 007) Suspension details. `status` (pending_verification | active |
-- rejected | suspended) is the lifecycle column added by migration
-- 2026_09_20_000010. A suspension remembers the state it interrupted and
-- reinstating returns to exactly that state; the staff note is never shown
-- to the customer. Reasons are the fixed list of Part 1 §4.3.
ALTER TABLE customer
  ADD COLUMN suspended_note TEXT,
  ADD COLUMN status_before_suspension TEXT
    CHECK (status_before_suspension IN ('pending_verification','active','rejected')),
  ADD CONSTRAINT customer_suspension_state CHECK (
    (status = 'suspended') = (status_before_suspension IS NOT NULL)
  ),
  ADD CONSTRAINT customer_suspended_reason_check CHECK (
    suspended_reason IS NULL OR suspended_reason IN (
      'piece_misrepresented','off_platform_dealing','repeated_disputes',
      'reported_by_users','identity_unconfirmed','customer_request','other',
      'repeated_cancellations')  -- spec 012: set only by the system (cancellation threshold)
  );

-- spec 012: seller cancellations count toward suspension.cancellations_threshold
-- from this moment (set at reinstatement, the database clock).
ALTER TABLE customer ADD COLUMN cancellations_reset_at TIMESTAMPTZ;

-- Identity documents. Photos are encrypted at rest (application-side or
-- pgcrypto); this table holds references + verification metadata, not raw
-- images in a normal column. Every VIEW of a document is logged (Part 4).
CREATE TABLE identity_document (
  document_id     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id     UUID NOT NULL REFERENCES customer(customer_id),
  doc_kind        TEXT NOT NULL CHECK (doc_kind IN ('egyptian_id','passport')),
  storage_ref     TEXT NOT NULL,                 -- encrypted object key
  status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','approved','rejected')),
  reviewed_by     UUID REFERENCES staff(staff_id),
  reviewed_at     TIMESTAMPTZ,
  -- After account closure, the image is deleted but a short "we checked
  -- it, and when" record survives for the legally required period.
  image_deleted_at TIMESTAMPTZ,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 6. Versioned legal documents. Every version a customer agreed to is
--    kept forever; a customer stays bound to the version they accepted.
-- ---------------------------------------------------------------------
CREATE TABLE legal_document (
  legal_doc_id  SMALLSERIAL PRIMARY KEY,
  code          TEXT NOT NULL,                   -- 'terms','privacy','collection_auth'...
  version       INTEGER NOT NULL,
  body_en       TEXT NOT NULL,
  body_ar       TEXT NOT NULL,
  is_material   BOOLEAN NOT NULL DEFAULT FALSE,  -- material change forces re-accept
  published_by  UUID NOT NULL REFERENCES staff(staff_id),
  published_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (code, version)
);

-- Immutable record of every acceptance. This is the evidence trail.
CREATE TABLE agreement_acceptance (
  acceptance_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id   UUID NOT NULL REFERENCES customer(customer_id),
  legal_doc_id  SMALLINT NOT NULL REFERENCES legal_document(legal_doc_id),
  -- Context of the tick: 'signup','list_piece','buy_request','payout_account',
  -- 'collection_proxy','first_sale_offer'... one row per tick.
  -- spec 014: 'collection_proxy' accepts the legal document
  -- 'collection_proxy_authorisation' (v1 seeded by the disputes migration).
  -- The acceptance a proxy was named under is collection.proxy_acceptance_id.
  context       TEXT NOT NULL,
  accepted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address    INET,
  device_fingerprint TEXT
);

-- As built by spec 010 ----------------------------------------------------
-- Both tables are created unchanged. An acceptance is evidence: append-only.
CREATE OR REPLACE FUNCTION agreement_acceptance_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'agreement acceptances are append-only';
END $$;

CREATE TRIGGER trg_agreement_acceptance_immutable
  BEFORE UPDATE OR DELETE ON agreement_acceptance
  FOR EACH ROW EXECUTE FUNCTION agreement_acceptance_immutable();

CREATE INDEX idx_agreement_acceptance_customer ON agreement_acceptance(customer_id, accepted_at);

-- A customer sees only their own acceptances (forced RLS, spec 003 pattern).
ALTER TABLE agreement_acceptance ENABLE ROW LEVEL SECURITY;
ALTER TABLE agreement_acceptance FORCE  ROW LEVEL SECURITY;
CREATE POLICY agreement_acceptance_isolation ON agreement_acceptance FOR ALL
  USING      (dahab_rls_elevated() OR customer_id = dahab_current_customer_id())
  WITH CHECK (dahab_rls_elevated() OR customer_id = dahab_current_customer_id());

-- Seed: the ownership declaration ticked when listing a piece, version 1,
-- published by the system actor (no Dashboard document management yet).
INSERT INTO legal_document (code, version, body_en, body_ar, is_material, published_by)
-- spec 011 also seeds 'deposit_agreement' version 1 (accepted with every buy
-- request, context 'buy_request'; no fixed percentage, draft for the legal clinic).
SELECT 'ownership_declaration', 1,
       'I confirm this piece is mine to sell and the details above are accurate.',
       'أقر أن القطعة دي ملكي ومن حقي أبيعها، وأن البيانات اللي فوق صحيحة.',
       FALSE, staff_id
FROM staff WHERE is_system = TRUE;

-- =====================================================================
-- Added by feature 001-auth-customer-staff
-- Credentials, MFA secrets, and per-actor device fingerprints. These
-- are extensions to the identity module required by the Sanctum-based
-- auth surface but not present in the original applied schema. Rows
-- here are read only by the sign-in service role.
-- =====================================================================

CREATE TABLE customer_password (
  customer_id         UUID PRIMARY KEY REFERENCES customer(customer_id) ON DELETE CASCADE,
  password_hash       TEXT NOT NULL,                       -- Argon2id
  password_changed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE staff_password (
  staff_id                UUID PRIMARY KEY REFERENCES staff(staff_id) ON DELETE CASCADE,
  password_hash           TEXT NOT NULL,                   -- Argon2id
  password_changed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  force_reenroll_mfa_at   TIMESTAMPTZ                      -- set on founder reset
);

CREATE TABLE staff_mfa (
  staff_id             UUID PRIMARY KEY REFERENCES staff(staff_id) ON DELETE CASCADE,
  mfa_secret_encrypted TEXT NOT NULL,                       -- encrypted at rest
  enrolled_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  recovery_codes_hash  JSONB                                -- array of Argon2id hashes
);

CREATE TABLE customer_trusted_device (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id      UUID NOT NULL REFERENCES customer(customer_id) ON DELETE CASCADE,
  fingerprint_hash TEXT NOT NULL,
  first_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_seen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (customer_id, fingerprint_hash)
);

CREATE TABLE staff_device_fingerprint (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  staff_id         UUID NOT NULL REFERENCES staff(staff_id) ON DELETE CASCADE,
  fingerprint_hash TEXT NOT NULL,
  first_seen_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_seen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (staff_id, fingerprint_hash)
);

-- =====================================================================
-- spec 017 — the customer account (specs/017-customer-account/data-model.md)
-- =====================================================================

-- Closing an account: a status of its own, final; nothing is deleted.
ALTER TABLE customer
  ADD COLUMN closed_at     TIMESTAMPTZ,
  ADD COLUMN closed_reason TEXT CHECK (closed_reason IN (
    'finished','fees_too_high','too_slow_to_sell','data_trust','something_went_wrong','other')),
  ADD COLUMN closed_note   TEXT CHECK (char_length(closed_note) <= 500),
  ADD CONSTRAINT customer_closed_shape CHECK ((closed_at IS NULL) = (closed_reason IS NULL)),
  ADD CONSTRAINT customer_closed_note_other CHECK (closed_note IS NULL OR closed_reason = 'other'),
  ADD CONSTRAINT customer_closed_status CHECK ((status = 'closed') = (closed_at IS NOT NULL));
-- customer_status_check gains 'closed'; customer_status_flags_consistent accepts any flags when closed
-- (closing clears status_before_suspension; the suspension reason and dates stay).

-- Sessions learn their device (spec 017 research R4).
ALTER TABLE personal_access_tokens ADD COLUMN device_fingerprint_hash TEXT, ADD COLUMN device_platform TEXT;
CREATE INDEX idx_pat_tokenable_device ON personal_access_tokens (tokenable_id, device_fingerprint_hash);
ALTER TABLE customer_trusted_device
  ADD COLUMN platform   TEXT CHECK (platform IN ('ios','android','web')),
  ADD COLUMN user_agent TEXT CHECK (char_length(user_agent) <= 255);

-- The in-app inbox: a third channel next to SMS and email. Written only by an
-- elevated scope (the inbox notification channel); only read_at changes (DH014).
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
-- Forced RLS: read/update own (elevated: all), insert elevated only, no delete policy.

-- Saved pieces (summary taken at save time, no media). At most setting saved.max_per_customer (200).
CREATE TABLE saved_listing (
  customer_id UUID NOT NULL REFERENCES customer(customer_id),
  listing_id  UUID NOT NULL REFERENCES listing(listing_id),
  summary     JSONB NOT NULL,
  saved_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (customer_id, listing_id)
);
-- Forced RLS: own rows only.

-- DH013: refuse_closed_customer() BEFORE INSERT on listing, buy_request, withdrawal,
-- withdrawal_confirmation, topup, dispute, payout_account, saved_listing, listing_report, and
-- refuse_closed_customer_posting() on ledger_posting for a closed customer's accounts.
