-- =====================================================================
-- Part 1b of 4: identity, staff, customers, documents, agreements
-- search_path assumed = dahab, public
-- =====================================================================
SET search_path = dahab, public;

-- ---------------------------------------------------------------------
-- 4. Staff (internal actors). Every privileged action references one.
--    Roles map to the permission matrix in the admin-roles document.
--    Founders (ceo/coo) are unrestricted and distinguished ONLY by the
--    audit log. Wallet access is limited to ceo + finance (enforced in
--    Part 4 via RLS + grants, not by a column here).
-- ---------------------------------------------------------------------
CREATE TABLE staff (
  staff_id      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  role          staff_role NOT NULL,
  full_name     TEXT NOT NULL,
  email         CITEXT UNIQUE NOT NULL,
  phone         TEXT,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  -- The IGI branch account is a single shared login by agreement; it is
  -- tied to a branch so the log records the branch, not an individual.
  branch_id     SMALLINT REFERENCES branch(branch_id),
  created_by    UUID REFERENCES staff(staff_id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT igi_has_branch CHECK (
    (role = 'igi_branch') = (branch_id IS NOT NULL)
  )
);

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
  context       TEXT NOT NULL,
  accepted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address    INET,
  device_fingerprint TEXT
);

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
