# Data model — spec 019

Requirements for the migrations; each migration mirrors `docs/Database schema/*.sql` (updated in the same change, marked "Changed by spec 019") and has a `down()` that **refuses to run while data it would destroy exists** (research R16). Content tables hold no customer data, so they get no RLS; they are written only through staff actions (elevated scope) and the sync command (system actor). One migration per phase; each seeds only its own permission codes (research R9).

## 1. App text, FAQ and notification templates — `2026_10_12_000010_app_content.php` (Phase 1)

### `app_text_key` (registry)

| Column | Type | Rule |
|---|---|---|
| `text_key` | TEXT PK | `^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,5}$` (Q1: `<screen>.<part>.<purpose>`) |
| `kind` | TEXT | `app` \| `faq` \| `notification` |
| `area` | TEXT | Home, Browse, Orders, Selling, Wallet, Account, Help, Auth, Shared, Notifications |
| `description` | TEXT | where it appears |
| `default_en`, `default_ar` | TEXT NOT NULL | the code's strings (R1); for `faq` the seed draft's |
| `default_changed_at` | TIMESTAMPTZ NULL | set by the sync when a default changes (drives *default changed since this override*) |
| `placeholders` | TEXT[] NOT NULL DEFAULT '{}' | allowed `{names}` (R5) |
| `required_placeholders` | TEXT[] NOT NULL DEFAULT '{}' | `{code}`, `{link}` (FR-042) |
| `read_only` | BOOLEAN NOT NULL DEFAULT FALSE | TRUE for templates carrying a code or link (D7) |
| `max_length` | INTEGER NOT NULL | max(2 × default length, 40) (A6) |
| `inbox` | BOOLEAN NOT NULL DEFAULT TRUE | notifications only; FALSE for codes/links |
| `position` | INTEGER NULL | FAQ order |
| `retired_at` | TIMESTAMPTZ NULL | key gone from the manifest; never deleted |
| `synced_at` | TIMESTAMPTZ NOT NULL | |

FAQ entries are two keys each (`help.faq.<slug>.question`, `.answer`) plus `position`; hiding is `app_text_version.is_hidden` on the answer.

### `app_text_version`

| Column | Type | Rule |
|---|---|---|
| `version_id` | BIGSERIAL PK | |
| `text_key` | TEXT FK → `app_text_key` | |
| `version` | INTEGER | per key; `UNIQUE(text_key, version)`; a discarded draft may leave a gap |
| `state` | TEXT | `draft` \| `published` \| `superseded` |
| `is_default_marker` | BOOLEAN NOT NULL DEFAULT FALSE | revert to default: texts empty, ends the override (R2) |
| `text_en`, `text_ar` | TEXT NOT NULL DEFAULT '' | CHECK: both non-empty unless `is_default_marker` |
| `is_hidden` | BOOLEAN NOT NULL DEFAULT FALSE | FAQ only |
| `draft_version` | INTEGER NOT NULL DEFAULT 1 | optimistic check while a draft (`content_draft_changed`) |
| `edited_by` | UUID FK staff NOT NULL | Principle I |
| `edited_at` | TIMESTAMPTZ NOT NULL | |
| `published_by` | UUID FK staff NULL | NOT NULL when state ≠ draft (CHECK) |
| `published_at` | TIMESTAMPTZ NULL | NOT NULL when state ≠ draft (CHECK) |
| `bundle_version` | INTEGER FK → `app_text_bundle` NULL | set at publish |

Indexes: partial unique `(text_key) WHERE state = 'draft'`; partial unique `(text_key) WHERE state = 'published'`.
Trigger `app_text_version_guard` (SQLSTATE `DH017` `content_history_immutable`): a `draft` row may be updated (staying a draft, or moving to `published`) or deleted; a `published` row may only change `state` to `superseded`; a `superseded` row never changes; DELETE of a non-draft row is refused. INSERT is refused for a key with `read_only = TRUE` (D7).

### `app_text_bundle`

`bundle_version SERIAL PK`, `published_by UUID NOT NULL`, `published_at TIMESTAMPTZ NOT NULL DEFAULT now()`, `key_count INTEGER NOT NULL`, `kinds TEXT[] NOT NULL`. Immutable (same trigger family).

### Publish transaction

`pg_advisory_xact_lock(hashtext('app_text_publish'))` → lock all drafts → validate each (R5) → insert bundle → previous published of each key → `superseded` → drafts → `published` with `published_by/at`, `bundle_version` → audit `content.published` (per key: version replaced, before, after) → commit. Nothing to publish → `422 content_nothing_to_publish`.

### Bundle version tag

Not stored: `"<max bundle_version>-<sha1 of the current values of the setting placeholders>"` (R3).

### Permission

`content.edit` seeded to COO and Operations (CEO holds every code).

### Rollback

`down()` refuses when any `app_text_version` row has `state <> 'draft'`.

## 2. Legal publishing — `2026_10_12_000020_legal_publishing.php` (Phase 2)

- `legal_document`: unchanged columns. New trigger `legal_document_immutable` (UPDATE/DELETE refused, SQLSTATE `DH018`). Index `(code, version DESC)`.
- `agreement_acceptance.context`: documented values gain `signup` (already in the schema comment), `first_acceptance` (D2) and `material_reaccept`. No column change.
- No stored "needs to accept" flag (R8: computed).
- Permission `legal.publish`: no role (CEO only, D1).
- Rollback: refuses when any `legal_document` row exists beyond the migration-seeded v1 declarations; drops the trigger first so earlier migrations' seed deletes still work.

## 3. Category controls and visibility — `2026_10_12_000030_category_controls.php` (Phase 6)

- `category_control` exactly as schema §14 (`category piece_category NOT NULL`, `level IN ('stop_new_listings','pause_category','stop_everything')`, `message_en/ar`, `set_by/at`, `cleared_by/at`), plus `reason TEXT NOT NULL`, CHECK `cleared_by` and `cleared_at` both or neither, partial unique `(category, level) WHERE is_active`, trigger: only the clearing columns and `is_active` (TRUE → FALSE) may change; DELETE refused. Stop everything = one row per category written in one transaction.
- Settings: four rows, group `visibility`, unit `boolean`: `visibility.rapaport_reference` TRUE, `visibility.payout_averages` FALSE, `visibility.listing_stats` TRUE, `visibility.sharing` TRUE (the design's "Now" column). `SettingKey` + `SettingGroup::VISIBILITY`; generic `PATCH /dashboard/settings/{key}` refuses the group (`setting_not_editable_here`).
- Permissions: `category.stop_new` (COO, Operations); `category.pause`, `platform.stop_everything`, `visibility.manage` (COO) — D1.
- Rollback: refuses when any `category_control` row exists or a visibility setting differs from its default.

## 4. Market makers, staff side (6A) — `2026_10_12_000040_market_makers.php` (Phase 7)

- `promo_code` exactly as schema §15 (CHECK `kind IN ('first_sale','market_maker')`, `mm_is_tied`), plus code format `^[A-Z0-9-]{3,32}$`, `monthly_cap_egp > 0`, `deactivated_by/at` both or neither. The Action refuses kinds other than `market_maker` and tied customers not of type `market_maker`.
- `promo_code_use` exactly as schema §15; append-only; forced RLS — customers read none, staff through the elevated path.
- `market_maker_approval` exactly as schema §15; append-only; `UNIQUE(listing_id)`.
- Permissions: `promo.manage`, `market_maker.approve` (Finance).
- Rollback: refuses when any promo code, use or approval exists.

## 4B. Market makers, purchases (6B) — **[SIGN-OFF Finance] — not written before the sign-off**

Designed only (research R11): `buy_request.promo_code TEXT NULL FK`; `promo_code_use.buy_request_id`, `amount_egp`, `device_fingerprint`; the settlement allocation of the spread to the dealer's `cust_available`; any ledger event kind Finance asks for. Schema docs change in the same migration.

## 5. People to watch — `2026_10_12_000050_people_to_watch.php` (Phase 8)

- Permission `people_to_watch.view` (COO; CEO holds every code) — D1. No table.

## 6. Entities touched, not changed

`customer.customer_type` (`market_maker` exists), `setting` (new keys only), `listing`, `order`, `buy_request` (until 6B), `customer_notification` (inbox rows written by the existing channel), `staff` (`email`, `is_founder` read for D5).
