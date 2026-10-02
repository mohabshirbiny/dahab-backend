# Research: Orders (spec 012)

The engineering choices behind the plan, each written as **Decision / Rationale / Alternatives**. The product decisions are in the spec's Clarifications (Session 2026-10-01).

## R1 — Where the endpoints live

**Decision**: the endpoints follow the surfaces specs 010–011 set up, not the Technical Spec's bare paths.

| Technical Spec | Built as |
|---|---|
| `POST /orders/{id}/seller-cancel` | `POST /customer/me/orders/{order}/cancel` (seller) |
| `POST /orders/{id}/settlement-decision` | `POST /customer/me/orders/{order}/decision` `{ accept }` (buyer) |
| `POST /orders/{id}/pay-balance` | `POST /customer/me/orders/{order}/pay-balance` (buyer) |
| — (clarification: relist a returned piece) | `POST /customer/me/orders/{order}/relist` (seller, trade gate) |
| — | `GET /customer/me/orders`, `GET /customer/me/orders/{order}` (both parties) |
| `GET /igi/orders` | `GET /dashboard/inspections/work-list` (branch-scoped, no prices) |
| `POST /igi/orders/{id}/receive` | `POST /dashboard/orders/{order}/receive` |
| `POST /igi/orders/{id}/inspection-result` | `POST /dashboard/orders/{order}/inspection-results` |
| `POST /igi/orders/{id}/handover` | `POST /dashboard/orders/{order}/handover` (buyer's code) · `POST /dashboard/orders/{order}/seller-return/handover` (seller's code) |
| `POST /admin/orders/{id}/change-branch` | `POST /dashboard/orders/{order}/change-branch` |
| `POST /admin/orders/{id}/extend-deadline` | `POST /dashboard/orders/{order}/extend-deadline` |
| — (clarification: stone-regrade price) | `POST /dashboard/orders/{order}/propose-price` |
| — | `GET /dashboard/orders`, `GET /dashboard/orders/{order}`, `GET /dashboard/inspections`, `GET /dashboard/buy-requests` |

**Rationale**: every customer write sits under `/customer/me/*` and every staff write under `/dashboard/*`. The IGI branch account is a Dashboard staff user (spec 002), so a separate `/igi` surface would only duplicate the authentication.

**Alternatives considered**: an `/igi/*` prefix with its own guard. Rejected: it would need a second staff guard for one role, and role names never gate code.

## R2 — Row-level security for actions that span two customers: an `order` scope

**Problem**: a seller's cancel refunds the buyer and reads the buyer's request. A buyer's payment pays the seller and moves the seller's listing. A buyer's decline moves the seller's listing too. Under plain isolation, each party sees only their own `"order"` row (`order_isolation`: seller or buyer), never the other party's request or listing.

**Decision**: a new non-elevated scope **`order`**, pushed only by the customer order Actions through `DatabaseActor::order(Closure)`. It keeps the current customer id, like `queue`. The migration adds narrow policies, each requiring an order the current customer is a party to:

- `buy_request` FOR SELECT, scope `order`: the request is the `buy_request_id` of an order where the caller is the seller or the buyer.
- `listing` FOR SELECT and FOR UPDATE, scope `order`: the listing has an order where the caller is the buyer. The UPDATE `WITH CHECK` allows only the states the buyer's actions reach: `sold` and `awaiting_seller_return`. The seller's own moves pass the existing owner policy.
- `listing_state_change` INSERT/SELECT, scope `order`: the actor customer is the caller (the same shape as the `queue` policy).
- `customer` FOR SELECT, scope `order`: `display_ref` only, for the other party of an order the caller is in. Column privacy is the Resources' job.
- New tables (`order_state_change`, `seller_cancellation`, `inspection_result`, `settlement_decision`, `collection`, `seller_return`, `order_branch_change`, `order_deadline_extension`): forced RLS with an isolation policy (elevated, or a party to the order). Customer inserts happen only in scope `order` and only for the caller's own order.
- Ledger: unchanged. The money service pushes `ledger` itself.

A customer's own reads (`GET /customer/me/orders*`) run under plain isolation; the scope is pushed only around writes. Every write is `DatabaseActor::order(fn () => DB::transaction(...))`, with the scope as the outer frame (spec 011 H1). New deferred triggers set their own read scope.

**Audited, not only documented (analysis C1)**: every customer write that pushes the `order` scope writes one `audit_log` row in the same transaction, actor = the customer: `order.seller_cancelled`, `order.decided`, `order.paid`, `order.relisted` (entity `order`, payload: order ref, from/to state, amounts moved, the counterparty's customer id). So each cross-customer touch is explicit (the scope, pushed in one place), documented (this record) and audited (the row), as Constitution II asks. A build test (`OrderScopeTest`) asserts that every Action that pushes the scope writes its audit event.

**No elevation in a customer path (analysis C2)**: a customer's cancel never suspends. The cancellation threshold is applied by the sweep's suspension pass (R14) as the system actor in its own transaction, so every request keeps exactly one actor (Constitution I).

**Recorded deviation (Constitution II)**: the same shape as spec 011's `queue` scope. `OrderScopeTest` proves its limits and `OrderLeakTest` proves column privacy.

**Alternatives considered**: reuse the `queue` scope. Rejected: its policies are about queued requests and live/reserved listings, and widening them would blur two audited deviations into one. Elevate to `system` for every customer order action. Rejected: it exposes every customer table.

## R3 — Schema, from the docs (Constitution III)

**Decision**: one migration, `2026_10_05_000010_create_orders_lifecycle.php`, built from `04_schema_market.sql` §9–§11 and the docs updated first:

- **Tables, as in the schema**: `order_branch_change`, `order_deadline_extension`, `seller_cancellation`, `inspection_result` (with `karat_rule`, `karat_mismatch_forces_cancel`, `inspection_no_update`), `settlement_decision`, `collection`, `seller_return`. `tax_invoice` is not created (out of scope).
- **New table `order_state_change`**: order id, from, to, actor (customer, staff or system), note, `txid`, created at. It mirrors `listing_state_change`: one row per move, so the timeline has named actors (Principle I). A deferred check refuses a commit that moved an order without one.
- **`"order"` additions**:
  - `locked_seller_unit_rate NUMERIC(18,4)`: `sellers_get` at acceptance for gold, `mid` for gold-with-diamond, NULL for pure diamond (R6). Existing orders are backfilled from the current price at migration time; there are only local and seed orders, and the backfill is recorded in the docblock.
  - `decision_due_deadline TIMESTAMPTZ`
  - `proposed_price NUMERIC(18,4)`, `proposed_by`, `proposed_at`
  - Settlement figures: `final_weight_g`, `final_buyer_total`, `final_seller_gross`, `commission_amount`, `vat_amount`, `spread_amount`, `seller_proceeds`, `balance_amount`
  - `settlement_txn_id`, `forfeit_txn_id`, `release_txn_id`
  - `reach_reminder_sent_at`, `balance_reminder_sent_at`
  - CHECKs: the settlement figures are all null or all set; the set must be `ready_to_collect` or `completed`.
- **`collection` additions**: `code_encrypted TEXT` (R10), `failed_attempts SMALLINT DEFAULT 0`, `locked_until TIMESTAMPTZ`. The same columns go on `seller_return`.
- **`customer.cancellations_reset_at TIMESTAMPTZ`**: set at reinstatement; the cancellation count starts after it (R8).
- **The `customer_suspended_reason_check`** gains `repeated_cancellations`.
- **New `order_transition` rows**: `awaiting_balance → weight_adjust_pending` and `awaiting_balance → cancelled_inspection` (a corrected result, R9). No new `listing_transition` rows: every listing move this spec makes already exists (`accepted → at_inspection | withdrawn`, `at_inspection → settling | awaiting_seller_return`, `settling → sold | awaiting_seller_return`, `awaiting_seller_return → live | withdrawn | seller_unclaimed`, `seller_unclaimed → withdrawn`, `sold ↔ uncollected_expired`).
- **The order guard** gets its own SQLSTATE `DH006` → `illegal_order_transition`. Spec 011 shared `DH005` with requests. The guard also freezes the new locked columns and the settlement figures once set.
- **Deferred money check `trg_order_money`** (AFTER UPDATE OF state, reading under its own scope):
  - `cancelled_seller` / `cancelled_inspection` → a `deposit_release` exists for the request.
  - `cancelled_buyer_nopay` → a `deposit_forfeit` for the order, and no release.
  - `ready_to_collect` → a `balance_payment` for the order.
- **Unique indexes**: one `balance_payment` per order, one `deposit_forfeit` per order.
- **`deposit_release_allowed()`** is extended: a release on an accepted request is allowed when its order is `cancelled_staff`, `cancelled_seller` or `cancelled_inspection`.
- **`buy_request_money_recorded()`**: unchanged. The settlement and the forfeiture take the deposit off `cust_held` under their own event kinds, and the reconciliation test covers them (R12).
- **Reversal**: `down()` refuses while any order has left `awaiting_delivery`/`cancelled_staff`, or any of the new tables has rows. Otherwise it drops in reverse (as spec 011 R20).

## R4 — Lock order

**Decision**: every order operation, in one transaction, locks in this order: the listing row, then the order row, then the request, then the collection or seller-return row. The customer accounts come last, locked by the money service. The sweeps take one order per transaction, listing first, and re-check the state and the deadline under the lock.

**Rationale**: the listing lock is already the per-piece lock (spec 011 R4). Taking it first serialises receive, cancel, sweep, decision, pay, handover and staff cancel on one piece, so "exactly one wins" follows.

## R5 — Branch scope

**Decision**: `StaffBranchScope::assertCanActAt(Staff, branchId)`. A staff member with a non-null `branch_id` (spec 004) may act only at that branch; otherwise `wrong_branch` (403, audited as a denied attempt). Unassigned staff act anywhere. It applies to receive, inspection results, the work list (filtered), and both handovers. It never reads a role name.

**Alternatives considered**: a `branch_scoped` flag on roles. Rejected: the spec 002 decision is permission data only, and the assigned branch already exists.

## R6 — Locked prices and the settlement figures

**Decision**:

- **At acceptance** (change to `AcceptBuyRequestAction`): store `locked_seller_unit_rate` from the same `PricingContext` read as the seller's "you would receive": `sellers_get` for gold, `mid` for gold-with-diamond, NULL for pure diamond.
- **New `PriceCalculator::lockedBreakdown(Piece, buyerRate, sellerRate, PricingRates)`**: the same `finish()` path, fed with locked per-gram rates instead of a `MarketPrice`. The calculator stays the single implementation of Part 3 §2.
- **`OrderSettlement::compute(Order, InspectionResult)`** produces the figures:
  - Gold: weight = the measured weight. `buyer_total = round4(buyerRate × W + making × W)`, `seller_gross = round4(sellerRate × W + making × W)`.
  - Diamond: `buyer_total = seller_gross =` the asking price, or the accepted `proposed_price` after a regrade.
  - Gold-with-diamond: the same, with `gold_value = round4(locked mid × measured W)` protected.
  - Commission, VAT and the minimum are read live from `Settings` at payment, and so is `commission_waived` (always false here).
- **Spread**: `buyer_total − seller_gross`. It may be negative. A zero spread is omitted, because `posting_nonzero` refuses zero lines. A negative spread is posted as a negative `dahab_spread` line; internal accounts may be any sign.
- **Rounding residue**: the residue (buyer_total − proceeds − commission − VAT − spread) is 0 by construction, because spread and proceeds are derived by subtraction (spec 005). The §3.5 rule therefore holds without a separate residue line, and a unit test asserts the residue is 0 for 10,000 random pieces.
- **The new price shown at a weight adjustment** is `buyer_total` on the measured weight. The settlement decision stores `old_price = locked_total_price` and `new_price`.
- **Tests**: Part 3 §3.3 and §3.4 reproduced to the piastre (`bid = ask = 5,994`, 21K adjustments ∓13.125).

## R7 — The settlement ledger

**Decision**: one ledger transaction, `event_kind = balance_payment`, tied to the order, the listing and the request, actor = the buyer, posted through `PostLedgerEntryAction` in the pay-balance transaction. The lines:

```
buyer cust_available  −balance           (only when balance > 0)
buyer cust_held       −deposit
buyer cust_available  +excess            (only when buyer_total < deposit: excess = deposit − buyer_total)
escrow                +buyer_total
escrow                −buyer_total
seller cust_available +seller_proceeds
dahab_commission      +commission        (omitted when 0)
vat_payable           +vat               (omitted when 0)
dahab_spread          ±spread            (gold only; omitted when 0)
```

`balance = max(buyer_total − deposit, 0)`. `insufficient_funds` (409) carries `amount_due`, `available` and `shortfall`. The money service locks the buyer's and the seller's accounts in `account_id` order. `seller_proceeds` must be greater than 0. If it is not (a commission minimum on a tiny piece), the payment is refused with `settlement_not_possible` (409) and staff resolve it; this is a safeguard and is expected never to fire with real pieces.

**Rationale**: Part 2 §7 and Part 3 §3.2 call it "one balanced transaction" with escrow as a pass-through. The schema has one `event_kind` per transaction, and the leg names in the docs map to account kinds.

**Alternatives considered**: two ledger transactions in one DB transaction (`balance_payment` in, `settlement_seller` out). Rejected: the docs say one, and reports read the accounts, not the kinds.

## R8 — Seller cancellations and suspension

**Decision**: `CancelOrderBySellerAction` serves both the seller's cancel and the sweep, with the actor passed in.

- Lock order: listing, then order, then request.
- `awaiting_delivery → cancelled_seller`, with an `order_state_change` row.
- `deposit_release` with `order_id`, and `order.release_txn_id` set.
- A `seller_cancellation` row, and the listing moved `accepted → withdrawn`.
- The customer path writes the audit row `order.seller_cancelled` (actor = the seller, R2). It never suspends.
- **Suspension (analysis C2)**: the sweep's first pass (R14 pass 0) selects sellers who are not suspended and whose `seller_cancellation` rows with `cancelled_at > COALESCE(customer.cancellations_reset_at, '-infinity')` number at least `suspension.cancellations_threshold` (read live). For each, in its own transaction as the system actor: lock the customer, re-count, then `SuspendSellerAction` with reason `repeated_cancellations`. It applies the spec 007/010/011 effects (`HoldListingsOfCustomerAction`), writes the audit row, and sends the existing suspension notice. A seller who cancels and then lists again gets at most one scheduler minute before the suspension lands; accepted (Clarification "automatic").
- `ReinstateCustomerAction` sets `cancellations_reset_at = now()`.

**Rationale**: "since the last reinstatement" (Clarification), without reading the audit log for business logic.

## R9 — Inspection results and corrections

**Decision**: `RecordInspectionResultAction`.

- Lock the listing, then the order. The order must be `at_inspection`; for a correction, `weight_adjust_pending` or `awaiting_balance`, with no `settlement_decision` row and no payment, and `supersedes_id` equal to the latest non-superseded result. Otherwise the result is refused with `inspection_correction_not_allowed` (409).
- Copy the stated karat and weight from the listing.
- Derive the outcome:
  - `karat_mismatch = stated IS DISTINCT FROM measured`
  - `weight_diff_pct = round4((measured − stated) / stated × 100)` (gold and gold-with-diamond)
  - `outcome`: mismatch → `karat_cancel`; counterfeit flag → `fake_cancel`; stone-below-claim flag (stone categories) → `stone_regrade`; `|diff| ≤ inspection.weight_tolerance_pct` → `pass`; otherwise `weight_adjust`. A pure diamond with no flags → `pass`.
- Insert the row, which is immutable.
- Apply the effects:
  - `pass` → `inspection_passed → awaiting_balance` (two state-change rows), `balance_due_deadline = now + buyer_pay_days` days (Cairo calendar). The listing moves `at_inspection → settling` (first result only).
  - `weight_adjust` → `weight_adjust_pending`, `decision_due_deadline = now + buyer_pay_days` days, listing `→ settling`.
  - `stone_regrade` → `weight_adjust_pending` with no deadline until a price is proposed (R11).
  - `karat_cancel` / `fake_cancel` → `cancelled_inspection`, release, `SuspendSellerAction` (`piece_misrepresented`, actor = the inspector), listing `at_inspection | settling → awaiting_seller_return`, `OpenSellerReturnAction` with no compensation.
- A correction re-runs the effects from the order's current state, along the new transitions (R3). A correction that keeps the outcome (for example a pass with a different weight) leaves the state alone and only replaces the figures. An open balance deadline keeps its date.
- Audit `inspection.result_recorded` (the actor is the inspector).

## R10 — Codes for collection and seller return

**Decision**:

- Generate 6 digits with `random_int`.
- Store `code_hash = hash_hmac('sha256', code, app key)` for the check, and `code_encrypted` (the Laravel encrypter) so the owner sees the code in their own order detail (Clarification: "shown in the buyer's app"). Part 2 §7 forbids the code only in list responses. It appears only in `GET /customer/me/orders/{order}` for that order's buyer (or, for a return, its seller).
- SMS the code after commit.
- Verify with `hash_equals`. A wrong code increments `failed_attempts`; at 5 it sets `locked_until = now + 15 min` and resets the count. A locked order answers `handover_locked` (429, `retry_after`). Every wrong attempt is audited (`order.handover_failed`).

**Deviation from the schema docs**: a new `code_encrypted` column ("store only its hash"), recorded in the docs.

**Alternatives considered**: hash only, plus a staff "resend code" action that generates a new code. Rejected: the app could not show the code, which goes against the clarification.

**Wrong-code status**: 422 `invalid_collection_code`, not Part 2's 401. A 401 makes the Dashboard's axios refresh the session and sign the staff member out (`src/api/axios.ts`). Recorded as a deviation in Part 2 §12.

## R11 — Stone regrade: the proposed price

**Decision**: `POST /dashboard/orders/{order}/propose-price` with `{ price, reason }`, permission `order.price_adjust`, audited. It is allowed once per result while the order is `weight_adjust_pending` with outcome `stone_regrade` and no decision. It sets `proposed_price`, `proposed_by` and `proposed_at`, sets `decision_due_deadline = now + buyer_pay_days` days, and tells the buyer (asked) and the seller. Until a price is proposed, the buyer's decision is refused with `price_not_set` (409). The price must be greater than 0. It may be at or above the locked price; a regrade usually lowers it, but this is not enforced.

## R12 — Reconciliation over every order

**Decision**: `OrderReconciliationTest` checks every order against its ledger transactions:

- `awaiting_delivery … weight_adjust_pending`, `awaiting_balance`: the deposit is held. There is exactly one `deposit_hold` and no release, forfeit or payment.
- `cancelled_*` except no-pay: exactly one release equal to the deposit.
- `cancelled_buyer_nopay`: exactly one forfeit, `−deposit` on held, and seller + Dahab = deposit.
- `ready_to_collect` / `completed`: exactly one `balance_payment`. Escrow nets to 0, the buyer's held −deposit, and the lines equal the stored figures.

The global `ledger_global_zero` view must also stay at 0.

## R13 — Forfeiture and the returned piece

**Decision**: `ForfeitDepositAction` (the no-pay sweep).

- Lock the listing, then the order. Re-check `awaiting_balance` and the deadline.
- `→ cancelled_buyer_nopay`.
- One `deposit_forfeit` transaction: buyer held −deposit; seller available +`round_half_up_2(deposit × share / 100)`; `dahab_commission` +the remainder. The actor is the system; it is tied to the order and the request.
- `forfeit_txn_id` is set, and the listing moves `settling → awaiting_seller_return`.
- `OpenSellerReturnAction` writes `seller_return`: the branch is the order's branch, a code, `return_deadline = now + seller_return_weeks` (calendar), and `compensation_txn_id`. Both parties are told; the seller gets the code by SMS.

The same `OpenSellerReturnAction`, with no compensation, serves `cancelled_inspection`.

The seller collects through `POST /dashboard/orders/{order}/seller-return/handover` (listing → `withdrawn`, `seller_return.collected_at`, `handover_by`), or relists through `POST /customer/me/orders/{order}/relist`:

- trade gate; the listing → `live` with an empty queue;
- a gold listing takes the measured karat and weight from the latest result, through `listing_relist_measured()` — a guard exception allowed only on `awaiting_seller_return → live` and recorded in the history note;
- the return is closed (`collected_at` stays null and a new `relisted_at` column is set);
- notify-when-free fires;
- the audit row `order.relisted` (actor = the seller).

## R14 — Sweeps and reminders

**Decision**: one command, `orders:sweep`, scheduled every minute with `withoutOverlapping()`, running as the system actor in the `staff`/`system` scope. It runs these passes, each selecting due ids and then handling one order per transaction (re-checked under the lock):

0. **Suspension**: sellers at or over the cancellation threshold since their last reinstatement and not suspended → `SuspendSellerAction` (`repeated_cancellations`, system actor), one seller per transaction (R8). It runs first and again after pass 1, so a sweep-made cancellation that reaches the threshold is acted on in the same run.
1. **Reach-branch**: `awaiting_delivery` and `reach_branch_deadline ≤ now` → R8.
2. **Unanswered adjustment**: `weight_adjust_pending` and `decision_due_deadline ≤ now` → a decline (R15), with a system actor and no decision row. The order history notes "no answer".
3. **No-pay**: `awaiting_balance` and `balance_due_deadline ≤ now` → R13.
4. **Seller return**: `seller_return` with no `collected_at`/`relisted_at` and `return_deadline ≤ now` → listing `awaiting_seller_return → seller_unclaimed`, and the seller is told.
5. **Collection**: `ready_to_collect`, `collect_deadline ≤ now`, and the listing is `sold` → listing `sold → uncollected_expired`, and the buyer is told.
6. **Reminders**:
   - `awaiting_delivery` and `reach_reminder_sent_at IS NULL` and the remaining working time ≤ 3 working hours (the resolver counts working minutes from now to the deadline) → tell the seller and stamp the column.
   - `awaiting_balance` and `balance_reminder_sent_at IS NULL` and `balance_due_deadline − now ≤ 24 h` → tell the buyer and stamp the column.

**Performance**: 1,000 due orders per pass in under a minute.

## R15 — The buyer's decision

**Decision**: `DecideAdjustmentAction` (the buyer, or the sweep).

- Lock the listing, then the order: `weight_adjust_pending`, and for a regrade a proposed price must exist.
- The latest result's outcome must be `weight_adjust` or `stone_regrade`.
- Insert `settlement_decision` (inspection id, accepted, old = `locked_total_price`, new = the computed or proposed price). The sweep's decline writes no decision row, because the schema's row means the buyer decided.
- **Accept** → `awaiting_balance`, `balance_due_deadline = now + buyer_pay_days` days.
- **Decline** → `cancelled_inspection`, release, listing `settling → awaiting_seller_return`, `OpenSellerReturnAction` with no compensation.
- The buyer's path writes the audit row `order.decided` (actor = the buyer; accept or decline, old and new price). The sweep's decline is audited as the system actor.

## R16 — Pay balance

**Decision**: `PayBalanceAction` (buyer, verified gate, idempotent).

- Lock the listing, then the order: `awaiting_balance` (else `illegal_order_transition`), and `now ≤ balance_due_deadline` (else `balance_deadline_passed`; the sweep will forfeit).
- Compute the figures (R6), then post R7.
- Store the figures and `settlement_txn_id`; the order moves `→ ready_to_collect` with `collect_deadline = now + collect_weeks` (calendar); the listing moves `settling → sold`.
- Create `collection` (R10). Write the audit row `order.paid` (actor = the buyer; payload: order ref, total, balance, proceeds, settlement txn id) — required because the action moves the seller's listing and money (R2, analysis C1).
- After commit, tell the seller (paid, with proceeds) and the buyer (the code).

**Response**: `200`, the buyer's order detail with the figures and the code.

## R17 — Receive, branch change, extensions, handover

- **Receive** (`ReceivePieceAction`, `order.receive`): branch scope (R5); `awaiting_delivery → at_inspection`; listing `accepted → at_inspection`; audit `order.received`; both told.
- **Change branch** (`ChangeOrderBranchAction`, `order.change_branch`):
  - Only from `awaiting_delivery` (else `order_not_open`).
  - The branch must be among the listing's options and enabled (`branch_not_in_options`), and different from the current one (422).
  - Write an `order_branch_change` row. With `extend_to` given, also write an `order_deadline_extension` (`reach_branch`) and move the deadline. The deadline must be in the future and later than the current one (422 `deadline_must_move_forward`).
  - Clear `reach_reminder_sent_at` when the deadline moves.
  - Audit `order.branch_changed`; both told.
- **Extend** (`ExtendOrderDeadlineAction`, `order.extend_deadline`):
  - `which` ∈ `reach_branch` (state `awaiting_delivery`), `balance` (`awaiting_balance`), `collect` (`ready_to_collect`, and the listing `sold` or `uncollected_expired`). Otherwise → `deadline_not_running` (409).
  - The new deadline must be later than the current one and than now (422).
  - Write the row, move the deadline, and reset that deadline's reminder stamp. For `collect` while the listing is `uncollected_expired`, the listing goes back to `sold`.
  - Audit `order.deadline_extended`; both told.
- **Handover** (`HandoverPieceAction`, `order.handover`):
  - Branch scope, the lock check, then the code check (R10).
  - `ready_to_collect → completed`, with `completed_at` and `collection.collected_at`/`handover_by`.
  - A listing in `uncollected_expired` returns to `sold` first. There is no ledger entry.
  - Audit `order.handed_over`; both told.

## R18 — Staff reads

- **`GET /dashboard/orders`** (`order.view`):
  - Keyset by `accepted_at DESC, order_id`.
  - Filters: `group` ∈ `waiting_seller` (awaiting_delivery), `at_igi` (at_inspection, inspection_passed), `needs_decision` (weight_adjust_pending), `waiting_balance`, `ready_to_collect`, `closed` (completed and every cancelled state), `returns` (cancelled with a seller_return not collected or relisted), `all` (default `open` = every non-final state); `past_deadline=1`; `branch_id`; `q` (an order ref, or a customer `display_ref`).
  - `meta.counts` per group.
  - Columns: ref, piece summary, seller/buyer refs, value (`locked_total_price` or `final_buyer_total`), held (deposit while held), paid, stage, the running deadline and whether it is overdue, branch.
- **`GET /dashboard/orders/{order}`** (`order.view`): the order plus a timeline. The timeline merges `order_state_change`, branch changes, extensions, inspection results (all, the superseded ones marked), the decision, the proposed price, collection/return, and the ledger transactions (kind, amount to/from each party).
- **`GET /dashboard/inspections/work-list`** (`inspection.enter`, `order.receive` or `order.handover`): orders `awaiting_delivery` (to receive), `at_inspection` (to inspect), `weight_adjust_pending` / `awaiting_balance` (to correct), `ready_to_collect` and open returns (to hand over), at the caller's branch. Inspection fields only (Part 1 §3.4), plus which actions the caller holds. No prices, no names.
- **`GET /dashboard/inspections`** (`inspection.enter` or `order.view`): results newest first. The columns follow the design: order ref, piece, seller ref, received at, entered at, stated vs measured, difference, outcome, the order's current state, superseded flag. Inspectors see the same fields, with no prices. Filters: `from`/`to` (default 30 days), `branch_id`, `outcome`.
- **`GET /dashboard/buy-requests`** (`buy_request.view`):
  - Read in the `staff` scope.
  - Keyset by `seller_reply_deadline ASC, buy_request_id`.
  - Filters: `state` (`queued` default, `accepted`, `ended`), `listing_id`, `near_expiry=1` (queued and deadline within 6 h, `config('dahab-orders.near_expiry_hours')`), `branch_id` (a listing option).
  - Item: request id, listing id/ref, piece summary, seller ref, buyer ref, `place_in_line`, `queue_length`, `locked_total_price`, `deposit_amount`, `requested_at`, `seller_reply_deadline`, `near_expiry` bool, state, `order_ref` when accepted.
  - `meta.counts` (`queued`, `near_expiry`).

## R19 — Customer reads

**Decision**: `GET /customer/me/orders?role=buyer|seller&group=open|closed&cursor`, read under plain isolation (the policy decides which rows exist), then piece summaries in the `order` scope. Each item has:

- `order_ref`, `role`, `state`, `stage` (a customer-facing group), the piece summary, branch (name, address, hours)
- the other party's `display_ref`, `locked_total_price`, `deposit_amount`, the running deadline (`deadline_kind`, `deadline_at`)
- `amount_due` (buyer, `awaiting_balance`), `seller_proceeds` (seller, once paid)
- the latest inspection in customer terms (measured vs stated, outcome, certificate, note, new price, `decision_needed`)
- `collection` (`code_available`, `collect_deadline`, `collected_at`) and `seller_return` (`return_deadline`, `can_relist`)
- `timeline[]` and `actions[]` (the moves the caller may make now: `cancel`, `decide`, `pay`, `relist`)

The detail adds the plaintext code for its owner.

## R20 — Notifications

**Decision**: `OrderNotification` uses the `OrderEvent` enum: `received`, `seller_cancelled`, `deadline_missed`, `branch_changed`, `deadline_extended`, `result_passed`, `result_adjust` (the buyer is asked), `result_regrade_pending`, `price_proposed`, `result_cancelled`, `decision_accepted`, `decision_declined`, `paid` (to the seller, with proceeds), `collection_code` (to the buyer), `forfeited`, `return_waiting` (with the code), `return_window_passed`, `collected`, `collection_window_passed`, `reach_reminder` and `balance_reminder`.

- Channels: SMS + mail, queued, `afterCommit`, in the customer's language, following `BuyRequestNotification`.
- No message goes to a customer for their own action, except `collection_code`.
- Suspension sends the existing notice.

## R21 — New error codes

| Code | HTTP | Raised by |
|---|---|---|
| `illegal_order_transition` | 409 | the order guard (SQLSTATE `DH006`) and every Action state check |
| `wrong_branch` | 403 | receive, inspection result, handover outside the assigned branch |
| `order_not_open` | 409 | change-branch outside `awaiting_delivery` |
| `deadline_not_running` | 409 | extend a deadline that does not apply in the order's state |
| `deadline_must_move_forward` | 422 | extend / change-branch with an earlier or past instant |
| `inspection_correction_not_allowed` | 409 | a correction after a decision or payment, or not superseding the latest result |
| `price_not_set` | 409 | the buyer's decision on a regrade before staff propose a price |
| `balance_deadline_passed` | 409 | pay-balance after the deadline |
| `settlement_not_possible` | 409 | pay-balance when the proceeds would be ≤ 0 (safeguard) |
| `invalid_collection_code` | 422 | handover with a wrong code (Part 2 says 401; R10) |
| `handover_locked` | 429 | five wrong codes, 15 minutes |
| `insufficient_funds` (+ `amount_due`, `available`, `shortfall`) | 409 | pay-balance |
| `branch_not_in_options` | 409 | change-branch (existing) |
| `account_suspended` | 403 | relist while suspended (existing trade gate) |

## R22 — Permissions

**Decision**: new catalogue codes in the group "Orders", seeded once and editable from the Dashboard (spec 002). The CEO holds all of them as a founder. The seeds: `order.view` (COO, Finance, Operations), `order.receive` (COO, Operations, igi_branch — the COO added during implementation so a COO can still manage the Operations role), `inspection.enter` (igi_branch), `order.price_adjust` (COO, Operations), `order.change_branch` (COO, Operations), `order.extend_deadline` (COO, Operations), `order.handover` (igi_branch), `buy_request.view` (COO, Operations). `order.cancel` is unchanged.

**Rationale**: these are the Part 1 §4.1 rows. `order.view` for Finance is a read of amounts on orders, not a wallet, so it is not wallet-narrowed. `order.price_adjust` is not in the matrix, so it follows "Cancel an order".

**Dashboard gating**: by permission strings only.

## R23 — Local seeder

**Decision**: `LocalOrderSeeder` (local only, after `LocalBuyRequestSeeder`) drives the real Actions to leave one order in each reached state: `awaiting_delivery`, `at_inspection`, `weight_adjust_pending` (weight), `weight_adjust_pending` (stone, no price), `awaiting_balance`, `ready_to_collect`, `completed`, `cancelled_seller`, `cancelled_inspection` (karat; seller suspended), `cancelled_buyer_nopay` with an open return, and `uncollected_expired`. Deadline passes are simulated with `Carbon::setTestNow`. The seeder never runs outside `local`.

## R24 — Apps

- **Dashboard**:
  - The Orders page (list, chips with counts, filters, the detail drawer with the timeline, actions in `DModal`: Receive, Change branch, Extend deadline, Propose price, Hand over, Seller return hand-over, Cancel (existing)).
  - The Inspections page (the results list with filters, and the work list with the result form for inspectors).
  - The Buy requests page (new nav item, table, filters, near-expiry highlight).
  - Types, services and endpoints; the permission strings; audit categories and events; error codes. The `orders` and `inspections` nav items are unhidden by permission.
- **Customer App**:
  - `OrdersApi` with models `Order`, `OrderInspection`, `OrderTimelineEvent`.
  - `ApiOrdersRepository` builds the order cards from orders (replacing the mock cards for orders) and keeps the buy-request cards from spec 011.
  - Seller: bring-to-branch card with the countdown, cancel the sale (confirm sheet), the inspection result, returned-piece code, relist.
  - Buyer: tracking, inspection result with Accept / Decline, pay balance (with `insufficient_funds` → Add funds), collection code, collection window passed.
  - The fake backend in `test/flows_test.dart`.
  - Invoices, proxy collection, disputes, the first-sale advance and *Ask for more time* stay mock or hidden (*Ask for more time* links to support).
