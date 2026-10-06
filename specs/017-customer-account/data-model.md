# Data model — spec 017

One migration; mirrored in `docs/Database schema/02_schema_identity.sql` (customer, devices, tokens, inbox, saved), `04_schema_market.sql` (report, listing transitions, pause) and `00_schema_full.sql`. Comments without apostrophes; no `?` jsonb operator.

## Changed tables

| Table | Change |
|---|---|
| `customer` | `closed_at TIMESTAMPTZ`, `closed_reason TEXT CHECK (closed_reason IN ('finished','fees_too_high','too_slow_to_sell','data_trust','something_went_wrong','other'))`, `closed_note TEXT CHECK (char_length(closed_note) <= 500)`; `CHECK ((closed_at IS NULL) = (closed_reason IS NULL))`, `CHECK (closed_note IS NULL OR closed_reason = 'other')` |
| `personal_access_tokens` | `device_fingerprint_hash TEXT NULL`, `device_platform TEXT NULL` (+ index on `(tokenable_id, device_fingerprint_hash)`) |
| `customer_trusted_device` | `platform TEXT NULL CHECK (platform IN ('ios','android','web'))`, `user_agent TEXT NULL CHECK (char_length(user_agent) <= 255)` |
| `one_time_token` | purpose CHECK + `'email_change'` |
| `withdrawal_pause` | `trigger_kind TEXT NOT NULL DEFAULT 'payout_account' CHECK (trigger_kind IN ('payout_account','phone_change','email_change'))`; `CHECK (trigger_kind = 'payout_account' OR triggered_by_account IS NULL)` (a contact change names no account; older pauses keep theirs) |
| `listing_transition` | + `('draft','withdrawn','account closed')`, `('in_review','withdrawn','account closed')`, `('changes_requested','withdrawn','account closed')`, `('suspended_hold','withdrawn','account closed')` |

| `setting` | new row `saved.max_per_customer` = 200 (operations, integer 1–1000), inserted by the migration as spec 011 did (history starts with the first change) |

## New tables

### `customer_notification` (inbox)

| Column | Type / rule |
|---|---|
| `notification_id` | UUID PK |
| `customer_id` | UUID NOT NULL → customer |
| `type` | TEXT NOT NULL, `^[a-z_]+\.[a-z_]+$` (e.g. `order.ready_to_collect`) |
| `params` | JSONB NOT NULL DEFAULT `{}` |
| `link_kind` | TEXT NOT NULL CHECK IN (`order, listing, buy_request, wallet, withdrawal, payout_account, topup, invoice, credit_note, dispute, account, none`) |
| `link_id` | TEXT NULL (`NULL` iff kind in `wallet, account, none`) |
| `title_en`, `title_ar` | TEXT NOT NULL ≤ 200 |
| `body_en`, `body_ar` | TEXT NOT NULL ≤ 1000 |
| `dedupe_key` | TEXT NOT NULL; `UNIQUE (customer_id, dedupe_key)` |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT now() |
| `read_at` | TIMESTAMPTZ NULL; only `NULL → value` (trigger: no other column may change, no delete — DH014) |

Index `(customer_id, created_at DESC, notification_id DESC)`, partial `(customer_id) WHERE read_at IS NULL`. Forced RLS: customer reads/updates own; insert elevated only; staff read elevated.

### `saved_listing`

`customer_id` UUID → customer, `listing_id` UUID → listing, `summary` JSONB NOT NULL (piece type, karat, stated weight at save time), `saved_at` TIMESTAMPTZ; PK `(customer_id, listing_id)`. Forced RLS: own rows only.

### `listing_report`

| Column | Type / rule |
|---|---|
| `report_id` | UUID PK |
| `report_no` | BIGINT NOT NULL UNIQUE DEFAULT nextval(`listing_report_no_seq`) → `RPT-n` |
| `listing_id` | UUID NOT NULL → listing |
| `reporter_id` | UUID NOT NULL → customer |
| `reason` | TEXT CHECK IN (`photos_not_genuine, price_or_weight_wrong, description_mismatch, not_theirs_to_sell, off_platform_dealing, other`) |
| `note` | TEXT NULL ≤ 1000 |
| `state` | TEXT NOT NULL DEFAULT `open` CHECK IN (`open, dismissed, actioned, listing_gone`) |
| `handled_by` | UUID NULL → staff (required for `dismissed`/`actioned`) |
| `handled_at` | TIMESTAMPTZ NULL (required iff state ≠ open) |
| `staff_note` | TEXT NULL ≤ 1000 (required for `dismissed`) |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT now() |

Partial unique `(listing_id, reporter_id) WHERE state = 'open'`; index `(state, created_at)`. Guard: only `open → {dismissed, actioned, listing_gone}`, no delete, no other column change (DH015 `illegal_report_transition`). Forced RLS: reporter inserts and reads own; staff/system elevated.

## Guards

- `refuse_closed_customer()` (DH013, R8) BEFORE INSERT on the tables of R8 (`ledger_posting`: when the account belongs to a closed customer).
- DH014 inbox immutability, DH015 report transitions. Mapped in `bootstrap/app.php`.

## State

- Customer status (derived): `closed` (closed_at set) ▸ `suspended` ▸ `rejected` ▸ `pending_verification` ▸ `active`. `closed` is final in this spec.
- Report: `open → dismissed | actioned | listing_gone` (final).

## Rollback

`down()` refuses once a closed customer, an inbox item, a saved piece, a report or a non-payout pause exists; otherwise drops in reverse order (LedgerSchemaTest rollback order updated if it walks this migration).
