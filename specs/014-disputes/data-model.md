# Data model: spec 014 — disputes, proxy collection, extension requests

One migration, `2026_10_07_000010_create_disputes.php`, mirroring the updated `docs/Database schema/*.sql` (Principle III: the SQL files change first, in the same change). Money as `NUMERIC(18,4)`; times `TIMESTAMPTZ` (Cairo session).

## 1. `dispute` (05 §16, extended)

| Column | Type | Notes |
|---|---|---|
| `dispute_id` | UUID PK | |
| `dispute_no` | BIGINT NOT NULL UNIQUE | from `dispute_no_seq` |
| `dispute_ref` | TEXT UNIQUE NOT NULL | `'DSP-' \|\| dispute_no` (set by trigger) |
| `order_id` | UUID NOT NULL → `"order"` | |
| `raised_by` | UUID NOT NULL → `customer` | |
| `raised_as` | TEXT NOT NULL | `buyer` \| `seller`; CHECK matches the order's buyer/seller (trigger) |
| `reason` | TEXT NOT NULL | CHECK in (`not_as_listed`, `disagree_inspection`, `money_wrong`, `other_side_unresponsive`, `not_theirs_to_sell`, `other`); `not_theirs_to_sell` only when `raised_as = 'buyer'` (CHECK) |
| `detail` | TEXT NOT NULL | CHECK 10–2000 chars (docs: nullable → required, recorded deviation) |
| `state` | TEXT NOT NULL DEFAULT `open` | `open` \| `passed_on` \| `resolved` |
| `assigned_to` | UUID → `staff` | set on pass-on |
| `passed_on_at` | TIMESTAMPTZ | last pass-on |
| `frozen_from` | `order_state` NOT NULL | CHECK in (`at_inspection`, `weight_adjust_pending`, `awaiting_balance`, `ready_to_collect`) |
| `frozen_at` | TIMESTAMPTZ NOT NULL DEFAULT `clock_timestamp()` | |
| `outcome` | TEXT | `resume` \| `against_sale`; CHECK `(state = 'resolved') = (outcome IS NOT NULL)`; CHECK `outcome <> 'against_sale' OR frozen_from <> 'ready_to_collect'` |
| `resolution_reply` | TEXT | CHECK 10–2000 when set |
| `resolved_by` | UUID → `staff` | |
| `opened_at` / `resolved_at` | TIMESTAMPTZ | |
| `release_txn_id` | UUID → `ledger_transaction` | the deposit refund on `against_sale` |

Constraints: `resolved_needs_reply` (as in the docs) extended with `resolved_at IS NOT NULL`; UNIQUE `(order_id, raised_by)` (one per party per order); partial UNIQUE `(order_id) WHERE state <> 'resolved'`. Indexes: `(state, opened_at)` for the queue, `(assigned_to) WHERE state = 'passed_on'`, `(raised_by)` for the Customer file.

**Guard** `trg_dispute_transition` (BEFORE UPDATE): a state change must be in `dispute_transition`; a resolved row cannot change at all; SQLSTATE `DH009`.

### `dispute_transition` (new lookup, 05 §18)

`open → passed_on`, `passed_on → passed_on` (passed again), `open → resolved`, `passed_on → resolved`.

## 2. `dispute_photo` (new)

`photo_id` UUID PK · `dispute_id` → `dispute` · `storage_ref` TEXT NOT NULL · `mime` TEXT NOT NULL · `position` SMALLINT 1–5 · UNIQUE `(dispute_id, position)`. Append-only (`block_mutation()` on UPDATE/DELETE).

## 3. `dispute_change` (new, append-only history)

`change_id` BIGSERIAL PK · `dispute_id` · `kind` (`opened` \| `passed_on` \| `resolved`) · `actor_customer_id` / `actor_staff_id` (exactly one — CHECK) · `assigned_to` (pass-on) · `note` TEXT (pass-on note 10–1000; never shown to customers) · `at` DEFAULT `clock_timestamp()`. A deferred check: every dispute state change has its `dispute_change` row (as `trg_order_change_recorded`).

## 4. `compensation` (new)

| Column | Notes |
|---|---|
| `compensation_id` UUID PK | |
| `dispute_id` → `dispute` NOT NULL | this spec pays only within a resolution |
| `order_id` → `"order"` NOT NULL | |
| `customer_id` → `customer` NOT NULL | the order's buyer or seller (trigger) |
| `party` | `buyer` \| `seller` |
| `amount` | NUMERIC(18,4) CHECK `> 0` |
| `reason` | `igi_delay` \| `dahab_mistake` \| `wasted_trip` \| `dispute_settlement` \| `goodwill` |
| `note` | 10–1000 |
| `paid_by` → `staff` NOT NULL | |
| `ledger_txn_id` → `ledger_transaction` UNIQUE NOT NULL | the `compensation` entry |
| `paid_at` | DEFAULT `clock_timestamp()` |

Index `(paid_by, paid_at)` for the day cap. Append-only. Deferred check: the linked entry is `event_kind = 'compensation'`, credits exactly `amount` to this customer's `cust_available` from `external_equity`.

## 5. `order_extension_request` (new)

| Column | Notes |
|---|---|
| `request_id` UUID PK | |
| `order_id` → `"order"`, `seller_id` → `customer` | seller must be the order's seller (trigger) |
| `reason` | `travelling` \| `emergency` \| `branch_closed` \| `other` |
| `detail` | 10–1000 |
| `deadline_at_request` | the `reach_branch_deadline` when sent |
| `state` | `waiting` \| `accepted` \| `refused` \| `lapsed` |
| `requested_at` | |
| `answered_by` → `staff` | null for `lapsed` |
| `answered_at`, `answer_note` (10–1000) | required for accepted/refused (CHECK) |
| `hours_granted` | SMALLINT in (6,12,24,48); CHECK `(state = 'accepted') = (hours_granted IS NOT NULL)` |
| `extension_id` → `order_deadline_extension` | CHECK `(state = 'accepted') = (extension_id IS NOT NULL)` |

Partial UNIQUE `(order_id) WHERE state = 'waiting'`; index `(state, requested_at)`.

**Guard** `trg_extension_request_transition`: changes only along `extension_request_transition` (`waiting → accepted | refused | lapsed`), final states frozen; SQLSTATE `DH010`.

## 6. `collection` (04 §11, extended)

Existing: `is_proxy`, `proxy_name`, `proxy_phone`, `proxy_id_storage_ref`, CHECK `proxy_needs_details`. Added: `proxy_acceptance_id` → `agreement_acceptance`, `proxy_named_at`, `collected_by_proxy BOOLEAN NOT NULL DEFAULT FALSE`, `proxy_id_checked_by` → `staff`. CHECKs: `NOT is_proxy OR (proxy_phone IS NOT NULL AND proxy_acceptance_id IS NOT NULL AND proxy_named_at IS NOT NULL)`; `NOT collected_by_proxy OR (is_proxy AND proxy_id_checked_by IS NOT NULL AND collected_at IS NOT NULL)`; proxy fields cannot change once `collected_at` is set (trigger).

## 7. `order_deadline_extension` (04 §10, extended)

`which` CHECK gains `'decision'`. New nullable `dispute_id` → `dispute` (frozen time given back, R4) and `extension_request_id` → `order_extension_request` (seller's request accepted). CHECK: not both.

## 8. `order_transition` (05 §18) — three rows

`weight_adjust_pending → disputed`, `disputed → weight_adjust_pending`, `disputed → at_inspection`.

## 9. Reference data

- Legal document `collection_proxy_authorisation` v1 (EN/AR), `agreement_acceptance.context = 'collection_proxy'`.
- Permissions (Spatie, staff guard): `dispute.handle`, `order.refund`, `compensation.pay`, `compensation.uncapped`, seeded per research R7.
- Upload purposes (code): `dispute_photo`, `proxy_id`.
- Sequence `dispute_no_seq`.

## 10. Row-level security (forced)

| Table | Read (non-elevated) | Write (non-elevated) |
|---|---|---|
| `dispute` | `raised_by` = current customer | scope `order`, `raised_by` = current customer, insert only |
| `dispute_photo` | its dispute raised by the current customer | scope `order`, the dispute raised by the current customer |
| `dispute_change` | its dispute raised by the current customer | scope `order`, actor = current customer, `kind = 'opened'` |
| `compensation` | `customer_id` = current customer | elevated only |
| `order_extension_request` | `seller_id` = current customer | scope `order`, `seller_id` = current customer, insert only |

Staff use the `staff` elevation (spec 002/012). `dispute_transition`, `extension_request_transition`: no RLS (lookups).

## 11. State changes

**Order** (via `MovesOrder`, history in `order_state_change`):
- open: `at_inspection | weight_adjust_pending | awaiting_balance | ready_to_collect → disputed` (actor customer)
- resume: `disputed → frozen_from` (actor staff) + deadline give-back rows
- against sale: `disputed → cancelled_inspection` (actor staff) + `deposit_release` + seller return
- any move out of `awaiting_delivery` lapses a waiting extension request

**Dispute**: `open → passed_on → … → resolved` (final).
**Extension request**: `waiting → accepted | refused | lapsed` (final).

## 12. Money

| Event | Shape (balanced; signed lines sum to zero) | Actor |
|---|---|---|
| Against the sale (before payment) | `deposit_release`: the deposit D moves from the buyer's held to the buyer's available account (the existing spec 011 shape, `DepositLedger::release`) | resolving staff |
| Compensation | `compensation`: X from `external_equity` to the customer's available account (Part 2 §9; signs per the spec 008 convention, as top-up credits) | paying staff |

No other movement: freezing and resuming move no money. `trg_order_money` already requires `deposit_release` for `cancelled_inspection`.

## 13. Tests that truncate

Keep lists gain `dispute_transition`, `extension_request_transition` (R20).
