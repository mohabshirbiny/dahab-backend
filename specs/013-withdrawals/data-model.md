# Data Model: Withdrawals and payout accounts (spec 013)

Built from `docs/Database schema/04_schema_market.sql` §12, `01_schema_core.sql`, `03_schema_ledger.sql` and `05_schema_security.sql`, updated first (Constitution III). Additions beyond the schema are marked **(new)** and recorded in the plan's deviation list.

## Enums

| Enum | Values | Change |
|---|---|---|
| `payout_account_state` | `pending_review`, `active`, `refused` **(new)**, `removing`, `removed` | `ALTER TYPE … ADD VALUE 'refused' AFTER 'active'` |
| `withdrawal_state` | `requested`, `under_review`, `on_hold_account_change`, `released`, `settled`, `rejected`, `cancelled` | unchanged; `on_hold_account_change` and `settled` unused |
| `ledger_event_kind` | … `withdrawal` … | unchanged |
| App: `PayoutRefusalReason` **(new)** | `name_mismatch`, `name_shortened`, `not_in_customer_name`, `details_invalid`, `other` | CHECK on `payout_account.refusal_reason` |
| App: `WithdrawalHoldReason` **(new)** | `name_mismatch`, `account_changed_recently`, `identity_pending`, `money_in_straight_out`, `other` | CHECK on `withdrawal.hold_reason` |
| App: `WithdrawalRejectReason` **(new)** | `account_not_in_name`, `money_in_straight_out`, `identity_unconfirmed`, `customer_request`, `other` | CHECK on `withdrawal.rejection_reason` |
| App: `PayoutAccountChangeKind` **(new)** | `added`, `verified`, `refused`, `in_use`, `removal_scheduled`, `kept`, `removed`, `request_cancelled` | CHECK on `payout_account_change.kind` |
| App: `ConfirmationState` (derived) | `sent`, `confirmed`, `used`, `expired`, `replaced` | computed from the timestamps |

## payout_account

| Column | Type | Rules |
|---|---|---|
| `payout_account_id` | UUID PK | |
| `customer_id` | UUID FK customer | owner; RLS |
| `account_name` | TEXT | 3–120, trimmed; must match the ID (checked by staff) |
| `bank_name` | TEXT | CHECK length 2–80 |
| `account_number_or_iban` | TEXT | CHECK `~ '^(EG[0-9]{27}|[0-9]{8,20})$'`; mod-97 checked in the request |
| `state` | payout_account_state | default `pending_review`; guard DH008 |
| `is_in_use` **(new)** | BOOLEAN | default false; partial UNIQUE (customer_id) WHERE is_in_use; CHECK `NOT is_in_use OR state IN ('active','removing')` — a `removing` account keeps the flag until `removed`, but new confirmations / withdrawals need `active`; a release accepts `active` or `removing` |
| `name_checked_by` | UUID FK staff | the verifier or the refuser |
| `name_checked_at` | TIMESTAMPTZ | |
| `activated_at` | TIMESTAMPTZ | set at verification |
| `refusal_reason` **(new)** | TEXT | CHECK in `PayoutRefusalReason`; CHECK `(state = 'refused') = (refusal_reason IS NOT NULL)` |
| `refusal_note` **(new)** | TEXT | staff only, never to the customer |
| `removal_requested_at` **(new)** | TIMESTAMPTZ | set on `removing` |
| `removed_at` **(new)** | TIMESTAMPTZ | set on `removed` |
| `created_at` | TIMESTAMPTZ | |

Guard (BEFORE UPDATE): the move must be in `payout_account_transition` when `state` changes; `customer_id`, `account_name`, `bank_name`, `account_number_or_iban`, `created_at` never change; no DELETE.

**payout_account_transition (new)**: `pending_review → active | refused | removed`; `active → removing | removed`; `removing → active | removed`.

Indexes: `idx_payout_customer` (schema), `idx_payout_review (created_at) WHERE state = 'pending_review'`.

## payout_account_change (new)

| Column | Type | Rules |
|---|---|---|
| `change_id` | BIGINT identity PK | keyset for *Recent changes* |
| `customer_id` | UUID FK customer | RLS |
| `payout_account_id` | UUID FK payout_account | |
| `kind` | TEXT | CHECK in `PayoutAccountChangeKind` |
| `actor_customer_id` / `actor_staff_id` | UUID | CHECK exactly one |
| `pause_id` | UUID FK withdrawal_pause NULL | on an `in_use` change that opened a pause |
| `created_at` | TIMESTAMPTZ | |

Append-only (`block_mutation()` on UPDATE/DELETE). Index `(customer_id, change_id DESC)`.

## withdrawal_pause

As in the schema (`pause_id`, `customer_id`, `opened_at`, `pause_until`, `triggered_by_account`, `pause_window_valid`) plus:

| Column | Type | Rules |
|---|---|---|
| `ended_notified_at` **(new)** | TIMESTAMPTZ | stamped by `withdrawals:sweep` once `pause_until` has passed |

Open pause: `pause_until > now()`. Index `idx_pause_customer_until` (schema) and `(pause_until) WHERE ended_notified_at IS NULL`.

## withdrawal

| Column | Type | Rules |
|---|---|---|
| `withdrawal_id` | UUID PK | |
| `withdrawal_no` **(new)** | BIGINT identity UNIQUE | shown `WD-{n}` |
| `customer_id` | UUID FK | RLS |
| `payout_account_id` | UUID FK | the account in use at submit |
| `amount` | NUMERIC(18,4) | CHECK > 0, at most 2 decimals in practice |
| `state` | withdrawal_state | guard DH007 |
| `hold_txn_id` **(new)** | UUID FK ledger_transaction UNIQUE | the available → held entry |
| `release_txn_id` | UUID FK UNIQUE | held → bank |
| `return_txn_id` **(new)** | UUID FK UNIQUE | held → available |
| `reviewed_by` | UUID FK staff | who took it / released / rejected |
| `review_started_at` **(new)** | TIMESTAMPTZ | |
| `held_at`, `held_by`, `hold_reason`, `hold_message`, `hold_note` **(new)** | | all null, or reason/message/by/at set (CHECK `withdrawal_hold_shape`); CHECK `withdrawal_hold_state`: `held_at IS NULL OR state IN ('under_review','rejected','cancelled')` — the hold record stays on a withdrawal that is later rejected or cancelled (analysis A1). **"On hold" means `state = 'under_review' AND held_at IS NOT NULL`**; *unhold* clears the five columns (the audit log keeps the hold); a held withdrawal is never released |
| `rejection_reason`, `rejection_note` **(new)** | TEXT | CHECK `(state = 'rejected') = (rejection_reason IS NOT NULL)` |
| `bank_txn_number`, `transfer_reference`, `value_date` **(new)** | TEXT, TEXT, DATE | CHECK `state <> 'released' OR (bank_txn_number IS NOT NULL AND release_txn_id IS NOT NULL AND released_at IS NOT NULL)` |
| `cancelled_by_change` **(new)** | BOOLEAN | default false |
| `requested_at`, `released_at`, `settled_at` | TIMESTAMPTZ | schema |
| `ended_at` **(new)** | TIMESTAMPTZ | released / rejected / cancelled |

Guard (BEFORE UPDATE): the move is in `withdrawal_transition`; `customer_id`, `payout_account_id`, `amount`, `withdrawal_no`, `requested_at` never change; each `*_txn_id` is set once; no DELETE.

Deferred money check (`trg_withdrawal_money`, AFTER INSERT OR UPDATE OF state): `requested|under_review` → `hold_txn_id`; `released` → `release_txn_id`; `rejected|cancelled` → `return_txn_id` and `release_txn_id IS NULL`.

**withdrawal_transition** (schema rows plus **(new)** `requested → rejected`): `requested → under_review | cancelled | rejected | on_hold_account_change`, `under_review → released | rejected | cancelled`, `on_hold_account_change → under_review`, `released → settled`.

Indexes: `idx_withdrawal_customer_state` (schema), `idx_withdrawal_queue (requested_at, withdrawal_id) WHERE state IN ('requested','under_review')`, `(payout_account_id, state)`.

## withdrawal_confirmation (new)

| Column | Type | Rules |
|---|---|---|
| `confirmation_id` | UUID PK | |
| `customer_id` | UUID FK | RLS |
| `payout_account_id` | UUID FK | |
| `amount` | NUMERIC(18,4) | > 0 |
| `token_hash` | TEXT UNIQUE | HMAC-SHA256 of the link token |
| `expires_at` | TIMESTAMPTZ | created + 30 min (`config/dahab-withdrawals.php`) |
| `confirmed_at`, `used_at`, `replaced_at` | TIMESTAMPTZ | CHECK `used_at IS NULL OR confirmed_at IS NOT NULL` |
| `withdrawal_id` | UUID FK withdrawal NULL UNIQUE | set with `used_at` |
| `created_at` | TIMESTAMPTZ | |

Partial UNIQUE (customer_id) WHERE `used_at IS NULL AND replaced_at IS NULL` — one open confirmation per customer.

## Ledger

- `ledger_transaction.withdrawal_id` → FK `withdrawal(withdrawal_id)` DEFERRABLE INITIALLY DEFERRED.
- Lines per R4. History `reference` for `withdrawal` rows: `WD-{n}`.

## Legal document

`payout_account_declaration` v1 — EN: "I confirm this account is mine, that the details are correct, and that the name matches my identity document." AR: the terms draft text. Acceptance stored in `agreement_acceptance` with `context = 'payout_account'`.

## Settings / config

- Setting `withdrawal.account_change_pause_hours` (exists, operations group, integer ≥ 0).
- Config `config/dahab-withdrawals.php`: `confirmation_ttl_minutes` (30), `confirm_url` (env `CUSTOMER_APP_URL` + `/#/withdraw-confirm`), `recent_change_days` (30, for the signal), `export_cap` (50,000).

## Permissions

| Code | Label | Seeded to |
|---|---|---|
| `withdrawal.release` | Release a withdrawal | CEO, Finance |
| `payout_account.verify` | Verify a payout bank account | CEO, Finance, Verification |

## Audit events (new)

`payout_account.added`, `.verified`, `.refused`, `.in_use_changed`, `.removal_scheduled`, `.kept`, `.removed`; `withdrawal.email_confirmed`, `.requested`, `.cancelled`, `.taken_for_review`, `.held`, `.unheld`, `.released`, `.rejected`, `.list_exported`, `.pause_ended` — category `money` (payout accounts in `accounts`).
