# Data model: Listings (spec 010)

Source: `docs/Database schema/04_schema_market.sql` §7, `01_schema_core.sql` (`listing_state`), `02_schema_identity.sql` §6, `05_schema_security.sql` §18 + RLS block. **Bold** = added or changed by this spec; the schema docs are updated first (Constitution III). Decisions: [research.md](./research.md).

## Types

`listing_state` — every value of `01_schema_core.sql` plus **`rejected`**:
`draft, in_review, changes_requested, live, reserved, accepted, at_inspection, settling, sold, withdrawn, suspended_hold, uncollected_expired, awaiting_seller_return, seller_unclaimed,` **`rejected`**.

`piece_category` exists (spec 004).

## `legal_document` (schema §6, new in the code)

| Column | Type | Notes |
|---|---|---|
| `legal_doc_id` | SMALLSERIAL PK | |
| `code` | TEXT NOT NULL | `ownership_declaration` seeded |
| `version` | INTEGER NOT NULL | UNIQUE (`code`, `version`) |
| `body_en`, `body_ar` | TEXT NOT NULL | |
| `is_material` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `published_by` | UUID NOT NULL → `staff` | the system actor for the seed |
| `published_at` | TIMESTAMPTZ NOT NULL DEFAULT now() | |

Reference data, no RLS. Seed: `ownership_declaration` v1 — EN "I confirm this piece is mine to sell and the details above are accurate." + Arabic.

## `agreement_acceptance` (schema §6, new in the code)

`acceptance_id` UUID PK · `customer_id` → customer · `legal_doc_id` → legal_document · `context` TEXT (`list_piece` here) · `accepted_at` · `ip_address` INET · `device_fingerprint` TEXT.
Append-only (trigger). **Forced RLS**: `customer_id = dahab_current_customer_id()` or elevated.

## `listing` (schema §7)

| Column | Type | Notes |
|---|---|---|
| `listing_id` | UUID PK | |
| `seller_id` | UUID NOT NULL → customer | never changes (guard) |
| `category` | piece_category NOT NULL | |
| `piece_type_id` | SMALLINT NOT NULL → piece_type | validation: enabled, same category |
| `karat_code` | SMALLINT → karat | NULL for pure diamond |
| `stated_weight_g` | NUMERIC(10,3) | NULL for pure diamond |
| `making_charge_per_g` | NUMERIC(18,4) | gold only |
| `asking_price` | NUMERIC(18,4) | diamond, gold with diamond |
| `description` | TEXT | 40–2,000 to submit |
| `state` | listing_state NOT NULL DEFAULT 'draft' | moves only per `listing_transition` |
| `active_queue_count` | INTEGER NOT NULL DEFAULT 0 | stays 0 in this spec |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT now() | |
| `listed_at` | TIMESTAMPTZ | set by the guard on the first move to `live` |
| **`state_changed_at`** | TIMESTAMPTZ NOT NULL DEFAULT now() | stamped by the guard (R6) |

Constraints: `gold_needs_karat_weight`, `queue_count_nonneg` (schema); **`listing_price_shape`**, **`listing_amounts`**, **`listing_description_len`**, **`listing_listed_shape`** (R5).
Indexes: **`idx_listing_state (state, state_changed_at)`**, **`idx_listing_seller (seller_id, created_at DESC)`**, **`idx_listing_market (listed_at DESC, listing_id) WHERE state IN ('live','reserved')`**.

## `listing_media` (schema §7)

`media_id` UUID PK · `listing_id` → listing · `kind` TEXT CHECK in (`photo`,`video`,`invoice`,`stone_certificate`) · `storage_ref` TEXT NOT NULL (a chunk-encrypted object, research R7) · `is_private` BOOLEAN NOT NULL DEFAULT FALSE (true for `invoice`) · **`mime` TEXT NOT NULL** · **`position` SMALLINT NOT NULL DEFAULT 0** · `created_at`.
Index **`idx_listing_media_listing (listing_id, kind, position)`**. Limits (application, under the listing lock): ≤ 6 photos, ≤ 1 video, ≤ 1 invoice, ≤ 1 certificate.

## `listing_branch_option` (schema §7)

(`listing_id`, `branch_id`) PK → listing, branch. At least one, each enabled at create/edit/submit.

## `listing_ownership_declaration` (schema §7)

`listing_id` PK → listing · `customer_id` → customer · `accepted_at` · `legal_doc_id` → legal_document. Written once with the listing.

## `listing_queue_seq` (schema §8)

`listing_id` PK → listing · `next_pos` INTEGER NOT NULL DEFAULT 1. Created with the listing; unused until buy requests.

## `listing_transition` (schema §18)

(`from_state`, `to_state`) PK, `note`. Seeded with every schema row **plus `('in_review','rejected')`**. Reference data, no RLS.

Moves reachable in this spec — `draft → in_review`, `changes_requested → in_review`, `in_review → live`, `in_review → changes_requested`, `in_review → rejected`, `live → withdrawn`, `live → suspended_hold`, `suspended_hold → live`. `rejected` and `withdrawn` are final: the table holds no `withdrawn → in_review` and no `withdrawn → live`. Take-down and withdrawal are reachable from `live` only (the seeded `reserved → withdrawn` waits for buy requests).

```text
draft ──submit──▶ in_review ──approve──▶ live ──withdraw / take down──▶ withdrawn (final)
                   │   ▲                  │  ▲
     request changes   │ resubmit         │  └─ reinstate ── suspended_hold
                   ▼   │                  └──── suspend ───▶ suspended_hold
             changes_requested
                   in_review ──reject──▶ rejected (final)
```

## **`listing_state_change`** (new, R4)

| Column | Type | Notes |
|---|---|---|
| `change_id` | BIGINT identity PK | |
| `listing_id` | UUID NOT NULL → listing | |
| `from_state` | listing_state | NULL = created |
| `to_state` | listing_state NOT NULL | |
| `actor_customer_id` | UUID → customer | exactly one actor (CHECK) |
| `actor_staff_id` | UUID → staff | |
| `note` | TEXT, ≤ 1000 | message / reason; required by CHECK when `to_state` is `changes_requested` or `rejected`, and for a staff move to `withdrawn` |
| `changed_at` | TIMESTAMPTZ NOT NULL DEFAULT now() | |
| `txid` | BIGINT NOT NULL DEFAULT txid_current() | ties the row to the transaction of the move (R3) |

Append-only. Index `idx_listing_state_change_listing (listing_id, changed_at)`.

## Triggers

| Trigger | On | Does |
|---|---|---|
| `trg_listing_guard` | BEFORE UPDATE OR DELETE ON listing | no delete; `seller_id`, `created_at` frozen; state move must be in `listing_transition` (SQLSTATE `DH004`); stamps `state_changed_at`; sets `listed_at` on first `live` |
| `trg_listing_change_recorded` | CONSTRAINT, AFTER INSERT OR UPDATE OF state ON listing, DEFERRABLE INITIALLY DEFERRED | a `listing_state_change` row for this listing, state and `txid_current()` must exist |
| `trg_listing_state_change_immutable` | BEFORE UPDATE OR DELETE ON listing_state_change | refuses |
| `trg_agreement_acceptance_immutable` | BEFORE UPDATE OR DELETE ON agreement_acceptance | refuses |

Not created here: `sync_listing_queue` / `trg_sync_queue` (needs `buy_request`).

## Row-level security (forced on every table below)

| Table | Policy |
|---|---|
| `listing` | `listing_isolation` FOR ALL: elevated OR `seller_id = current customer`. **`listing_market_read`** FOR SELECT: scope `market` AND `state IN ('live','reserved')` |
| `listing_media` | FOR ALL: elevated OR the listing is visible (`EXISTS` on `listing`) AND (scope ≠ `market` OR NOT `is_private`); WITH CHECK: elevated OR the listing's seller is the current customer |
| `listing_branch_option` | same, without the private test |
| `listing_ownership_declaration`, `listing_queue_seq` | elevated OR the listing's seller is the current customer (never the market) |
| `listing_state_change` | USING: elevated OR the listing's seller is the current customer. WITH CHECK: elevated OR (the listing's seller is the current customer AND `actor_customer_id = dahab_current_customer_id()` AND `actor_staff_id IS NULL`) |
| `agreement_acceptance` | elevated OR `customer_id = current customer` |

`CustomerTableIsolationTest` covers `listing.seller_id`, `listing_ownership_declaration.customer_id`, `listing_state_change.actor_customer_id`, `agreement_acceptance.customer_id`.

## Enums (PHP)

`ListingState` (15 values; `isPublic()` = live, reserved; `isEditable()` = draft, changes_requested) · `ListingMediaKind` · `ListingDecision` (approved, changes_requested, rejected, taken_down) · `UploadPurpose` +4 · `StaffPermission` +3 · `AuditEvent` +4 · `AuditCategory` +`LISTINGS`.

## Validation (per category)

| Field | gold | gold_with_diamond | diamond |
|---|---|---|---|
| `piece_type_id` | required, enabled, category matches | same | same |
| `karat_code` | required, enabled | required, enabled | prohibited |
| `stated_weight_g` | required, > 0, ≤ 3 dp, ≤ 9999.999 | same | prohibited |
| `making_charge_per_g` | required, ≥ 0, ≤ 2 dp | prohibited | prohibited |
| `asking_price` | prohibited | required, > 0, ≤ 2 dp | required, > 0, ≤ 2 dp |
| `description` | optional ≤ 2000 (40–2000 to submit) | same | same |
| `branch_option_ids` | ≥ 1, distinct, enabled | same | same |
| photos to submit | ≥ 2 | ≥ 3 | ≥ 3 |
