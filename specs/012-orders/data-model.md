# Data model: Orders (spec 012)

One migration, `database/migrations/2026_10_05_000010_create_orders_lifecycle.php`, built from `docs/Database schema/04_schema_market.sql` §9–§11 after the docs are updated (research R3). The money amounts are `NUMERIC(18,4)`, the weights `NUMERIC(10,3)`. Every new table has forced RLS: elevated scopes, or a party to the order (research R2).

## Order state machine (`order_transition`)

| From | To | Moved by | Effects in the same transaction |
|---|---|---|---|
| `awaiting_delivery` | `at_inspection` | staff `order.receive` (branch-scoped) | listing `accepted → at_inspection` |
| `awaiting_delivery` | `cancelled_seller` | seller (verified), or the sweep at `reach_branch_deadline` | `deposit_release`; `seller_cancellation`; listing `accepted → withdrawn`; audit `order.seller_cancelled` (seller path); the threshold is applied later by the sweep's suspension pass (`repeated_cancellations`, system actor) |
| `awaiting_delivery` | `cancelled_staff` | staff `order.cancel` (spec 011) | `deposit_release`; listing `→ live \| withdrawn` |
| `at_inspection` | `inspection_passed` → `awaiting_balance` | an inspection result (`pass`) | listing `at_inspection → settling`; `balance_due_deadline` |
| `at_inspection` | `weight_adjust_pending` | result `weight_adjust` / `stone_regrade` | listing `→ settling`; `decision_due_deadline` (weight now; regrade once priced) |
| `at_inspection` | `cancelled_inspection` | result `karat_cancel` / `fake_cancel` | `deposit_release`; seller suspended (`piece_misrepresented`); listing `at_inspection → awaiting_seller_return`; `seller_return` (no compensation) |
| `weight_adjust_pending` | `awaiting_balance` | the buyer accepts | `settlement_decision`; `balance_due_deadline` |
| `weight_adjust_pending` | `cancelled_inspection` | the buyer declines, or the sweep at `decision_due_deadline`, or a correction to karat/fake | `deposit_release`; listing `settling → awaiting_seller_return`; `seller_return` (no compensation); seller suspended only for karat/fake |
| `awaiting_balance` | `weight_adjust_pending` | **new** — a corrected result needs approval | `decision_due_deadline` |
| `awaiting_balance` | `cancelled_inspection` | **new** — a corrected result is karat/fake | as `at_inspection → cancelled_inspection` (listing from `settling`) |
| `awaiting_balance` | `ready_to_collect` | the buyer pays (verified) | `balance_payment` settlement; `collection`; listing `settling → sold`; `collect_deadline` |
| `awaiting_balance` | `cancelled_buyer_nopay` | the sweep at `balance_due_deadline` | `deposit_forfeit`; listing `settling → awaiting_seller_return`; `seller_return` with compensation |
| `ready_to_collect` | `completed` | staff `order.handover` with the buyer's code | listing `uncollected_expired → sold` if needed; no money |

Final states: `completed`, `cancelled_seller`, `cancelled_staff`, `cancelled_inspection`, `cancelled_buyer_nopay`. The `disputed` rows exist and are never reached (disputes are out of scope).

The guard `assert_order_transition()` is redefined with SQLSTATE **`DH006`** (→ 409 `illegal_order_transition`). It also freezes `locked_seller_unit_rate` and, once set, the settlement figures and `*_txn_id`.

## Listing moves used (all existing rows of `listing_transition`)

`accepted → at_inspection | withdrawn` · `at_inspection → settling | awaiting_seller_return` · `settling → sold | awaiting_seller_return` · `sold → uncollected_expired` · `uncollected_expired → sold` · `awaiting_seller_return → live | withdrawn | seller_unclaimed` · `seller_unclaimed → withdrawn`. Each move writes `listing_state_change` with its actor through `MovesListing`.

**Relist after a return**: `awaiting_seller_return → live`. When an inspection result exists, a gold or gold-with-diamond listing takes the measured `karat_code` / `stated_weight_g`. The spec 010 listing guard gets one exception for exactly this move (research R13), and the history note records the old and the new figures.

## `"order"` — additions

| Column | Type | Notes |
|---|---|---|
| `locked_seller_unit_rate` | NUMERIC(18,4) NULL | `sellers_get` (gold) / `mid` (gold-with-diamond) at acceptance; NULL for pure diamond. Set by `AcceptBuyRequestAction`; frozen. Existing rows are backfilled by the migration |
| `decision_due_deadline` | TIMESTAMPTZ NULL | set while `weight_adjust_pending` with a price to decide on |
| `proposed_price` · `proposed_by` (staff) · `proposed_at` | NUMERIC(18,4) / UUID / TIMESTAMPTZ NULL | stone regrade (R11); all three are set together (CHECK) |
| `final_weight_g` | NUMERIC(10,3) NULL | the measured weight used at settlement |
| `final_buyer_total` · `final_seller_gross` · `commission_amount` · `vat_amount` · `spread_amount` · `seller_proceeds` · `balance_amount` | NUMERIC(18,4) NULL | stored at pay-balance; all null or all set (CHECK `order_settlement_shape`); `spread_amount` may be negative |
| `settlement_txn_id` · `forfeit_txn_id` · `release_txn_id` | UUID NULL → `ledger_transaction` | the money of each ending |
| `reach_reminder_sent_at` · `balance_reminder_sent_at` | TIMESTAMPTZ NULL | reminders, once each (cleared when the deadline moves) |

The existing columns used: `balance_due_deadline`, `collect_deadline`, `completed_at`, `reach_branch_deadline`, `branch_id` (updated by a branch change; `trg_order_branch_subset` re-checks it).

## New tables

### `order_state_change` (new; Principle I)

`change_id` UUID PK · `order_id` → order · `from_state` order_state NULL · `to_state` order_state · `actor_customer_id` / `actor_staff_id` (exactly one, CHECK) · `note` TEXT NULL · `txid` BIGINT DEFAULT `txid_current()` · `created_at`. Append-only (`block_mutation`). A deferred `trg_order_change_recorded` refuses a commit where an order's state changed with no row in that transaction.

### From the schema (verbatim, plus the noted additions)

- **`order_branch_change`**: `from_branch`, `to_branch`, `changed_by` (staff), `extended_to`, `reason` (10–1000, **NOT NULL** here), `changed_at`.
- **`order_deadline_extension`**: `which` ∈ reach_branch | balance | collect, `old_deadline`, `new_deadline` (`extension_moves_forward`), `granted_by`, `reason` (NOT NULL here), `granted_at`.
- **`seller_cancellation`**: `order_id` (UNIQUE here — one per order), `seller_id`, `cancelled_at`, + `by_sweep BOOLEAN NOT NULL` (an explicit cancel or a missed deadline).
- **`inspection_result`**: the schema columns, plus `is_counterfeit BOOLEAN NOT NULL DEFAULT false` and `stone_below_claim BOOLEAN NOT NULL DEFAULT false` (the inspector's flags that drive `fake_cancel` / `stone_regrade`). CHECKs `karat_rule` and `karat_mismatch_forces_cancel`. `inspection_no_update`. A unique partial index makes at most one result supersede a given result. `inspected_by` is the staff member, `branch_id` the order's branch.
- **`settlement_decision`**: the schema columns, plus UNIQUE (`inspection_id`).
- **`collection`**: the schema columns, plus `code_encrypted TEXT NOT NULL`, `failed_attempts SMALLINT NOT NULL DEFAULT 0`, `locked_until TIMESTAMPTZ`. The proxy columns stay unused.
- **`seller_return`**: the schema columns, plus `code_encrypted`, `failed_attempts`, `locked_until`, and `relisted_at TIMESTAMPTZ` (CHECK: not both `collected_at` and `relisted_at`). `return_deadline` is calendar weeks (Part 3 §1.3; the schema comment is corrected).

## `customer` additions

- `cancellations_reset_at TIMESTAMPTZ NULL`, set by `ReinstateCustomerAction`.
- `customer_suspended_reason_check` gains `repeated_cancellations`.

## Ledger

- New unique partial indexes on `ledger_transaction(order_id)`: one for `event_kind = 'balance_payment'`, one for `event_kind = 'deposit_forfeit'`.
- `deposit_release_allowed()`: a release on an accepted request is allowed when its order is `cancelled_staff`, `cancelled_seller` or `cancelled_inspection`.
- Deferred `trg_order_money` (on `"order"` AFTER UPDATE OF state; reads under its own `ledger` scope): `cancelled_seller` / `cancelled_inspection` need the request's `deposit_release`; `cancelled_buyer_nopay` needs a `deposit_forfeit` for the order; `ready_to_collect` needs a `balance_payment` for the order. Otherwise `DH006`.
- The entries:

| Event | Lines |
|---|---|
| `deposit_release` (seller cancel, sweep, inspection cancel, decline) | buyer held −d · buyer available +d |
| `deposit_forfeit` (no-pay) | buyer held −d · seller available +round½↑(d × share%) · `dahab_commission` +rest |
| `balance_payment` (settlement, R7) | buyer available −balance · buyer held −d · [buyer available +excess] · escrow +T · escrow −T · seller available +proceeds · `dahab_commission` +c · `vat_payable` +v · `dahab_spread` ±s |

## Settings read (all existing, read live)

`deadline.buyer_pay_days` (10) · `deadline.collect_weeks` (3) · `deadline.seller_return_weeks` (3) · `inspection.weight_tolerance_pct` (1.5) · `suspension.cancellations_threshold` (2) · `deposit.seller_forfeit_share_pct` (50) · `commission.gold_pct` · `commission.stone_pct` · `commission.minimum_egp` · `vat.pct`.

**Configuration (not settings)**: `config/dahab-orders.php` holds the reminder lead times (3 working hours, 24 h), the near-expiry window (6 h), the handover attempt limit (5) and the lock (15 min).

## Enums (PHP)

- `OrderState` (exists): add the helpers `isOpen()`, `group()`.
- `OrderEvent`: new (notifications).
- `InspectionOutcome`: new.
- `DeadlineKind`: new.
- `SuspendedReason`: + `REPEATED_CANCELLATIONS`.
- `StaffPermission`: + 8 codes.
- `AuditEvent`: + `order.received`, `order.branch_changed`, `order.deadline_extended`, `order.price_proposed`, `order.handed_over`, `order.handover_failed`, `order.return_handed_over`, `inspection.result_recorded`, and the customer-actor events `order.seller_cancelled`, `order.decided`, `order.paid`, `order.relisted` (analysis C1).
- `AuditCategory`: `orders` (exists).

## RLS (research R2)

- New tables: `FORCE ROW LEVEL SECURITY`; policy `*_isolation` FOR ALL — elevated, or `EXISTS (order o WHERE o.order_id = <table>.order_id AND (o.seller_id = me OR o.buyer_id = me))`. Writes by customers happen only in scope `order`.
- New `order`-scope policies on `buy_request` (SELECT), `listing` (SELECT, UPDATE to `sold` / `awaiting_seller_return` for the buyer of an order on it), `listing_state_change` (INSERT/SELECT own actor), `customer` (SELECT of the other party of an order).
- The build tests are extended: `CustomerTableIsolationTest` (the new tables), `ElevationTest` (the `order` scope is pushed only from `app/Actions/Orders/Customer/*`; no Action under `app/Actions/Orders/Customer/` calls `DatabaseActor::elevate`; each Action that pushes the `order` scope writes its audit event).
