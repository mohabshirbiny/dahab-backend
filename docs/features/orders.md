# Orders

> File: `docs/features/orders.md` · Branch: `feature/orders` (backend, dashboard; Flutter has no repo)
> Status: built, not committed — backend US1–US10, Dashboard US9–US10 (Orders, Inspections, Buy requests), Customer App US11 (the order life), polish (local seeder, concurrency, reconciliation, leak, notification and opt-in performance tests); open: the manual quickstart walk · Date: 2026-10-02 · Spec Kit: [`specs/012-orders/`](../../specs/012-orders/spec.md)

## Goal

The life of an order after the seller accepts a buy request (spec 011):
- The seller brings the piece to the branch, or cancels.
- IGI inspects it and the server decides the outcome: pass, adjusted price, or cancel.
- The buyer pays the balance from the wallet. At that moment the seller is paid through escrow, and Dahab takes commission, VAT and spread.
- A buyer who never pays forfeits the deposit, split between the seller and Dahab.
- The piece goes to the buyer, or back to the seller, against a code.

The Dashboard gets the Orders, Inspections and Buy requests pages. The Customer App runs the order life on the API.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (6 customer + 13 dashboard endpoints; all built)
Dashboard:    YES (Orders, Inspections, new Buy requests page — built)
Customer App: YES (order life for buyer and seller — built)
Auth:         YES (small: a non-elevated, audited `order` database scope; no change to sign-in, tokens or gates)
Permissions:  YES (8 new codes: order.view, order.receive, inspection.enter, order.price_adjust,
                   order.change_branch, order.extend_deadline, order.handover, buy_request.view)
```

**Classification**: the new endpoints are non-breaking. These changes are potentially breaking:

- order guard errors now answer `illegal_order_transition` (SQLSTATE DH006) instead of `illegal_buy_request_transition`;
- listing and order states beyond `accepted` / `awaiting_delivery` now occur;
- the permission union and the audit event list grow;
- the suspension reason `repeated_cancellations` now appears (system only, never a staff choice);
- accept now refuses a gold piece that cannot be priced (`price_unavailable`), because the seller's rate is locked there.

## Decisions (product owner, 2026-10-01 — spec Clarifications)

| Question | Decision |
|---|---|
| Split? | One spec. Out: first-sale advance, disputes, proxy collection, tax invoices, free relist, market makers, post-window refund from escrow, seller extension requests, approval of inspection messages. |
| Receive / branch scope | `order.receive` (CEO, Operations, IGI). An assigned branch → that branch only (`wrong_branch`, audited); no branch → any. Never by role name. |
| Seller cancel / missed deadline | Listing `accepted → withdrawn`, buyer refunded. The cancellations since the last reinstatement reaching `suspension.cancellations_threshold` (2) suspend the seller automatically — by the sweep, in its own operation (analysis C2). |
| Branch change / extensions | Staff, no cap (`order.change_branch`, `order.extend_deadline`), reason required, audited. |
| Inspection | `inspection.enter` (CEO, IGI), branch-scoped. The server derives the outcome. A stone regrade is priced by staff (`order.price_adjust`). |
| Declined adjustment | Refund, no suspension; the piece is returned. The seller collects it or relists it, taking the measured karat and weight. |
| Seller price | Locked at acceptance (`order.locked_seller_unit_rate`). A negative spread (rates crossed) is absorbed by Dahab. |
| Balance | Wallet only, in full, once, within 10 calendar days. An unanswered adjustment counts as a decline. |
| Forfeiture | `deposit.seller_forfeit_share_pct` (50) to the seller, rounded half-up; the rest to `dahab_commission`; no VAT. |
| Settlement | At pay-balance: one `balance_payment` through escrow (Part 3 §3.3 legs). |
| Buy requests page | `buy_request.view` (CEO, COO, Operations), read-only, display references only. |
| Notifications | Every event plus reminders: 3 working hours before the reach deadline, 24 h before the balance deadline. |
| Collection | `order.handover` (CEO, IGI). A 6-digit code, hashed and also encrypted for its owner. 5 wrong codes lock it for 15 minutes. Wrong code → 422, not 401. |
| Corrections | A new superseding result, allowed only before any decision or payment. |
| Gates | Verified for reads, cancel, decision and pay (wind down while suspended); trade for relist. |
| Karat / fake | Seller suspended at once by the inspector (`piece_misrepresented`). |

## Backend Impact (built — US1–US8)

- **Migration** `2026_10_05_000010_create_orders_lifecycle.php`, built from the schema docs (see Database).
- **Actions**:
  - `app/Actions/Orders/Customer/`: `ListOwnOrders`, `CancelOrderBySeller` (the seller's cancel and the reach sweep), `DecideAdjustment` (the buyer's answer and the unanswered sweep), `PayBalance`, `RelistReturnedPiece`.
  - `app/Actions/Orders/Staff/`: `ShowOrder`, `ReceivePiece`, `ProposePrice`, `ChangeOrderBranch`, `ExtendOrderDeadline`, `HandoverPiece`, `HandoverReturnedPiece`.
  - `app/Actions/Inspections/`: `RecordInspectionResult`, `InspectionWorkList`.
  - Shared: `SuspendSellerAction`, `OpenSellerReturnAction`, `ReleaseOrderDepositAction`, `ForfeitDepositAction`, `OrderSweepAction`.
  - Concerns: `MovesOrder` (the only way an order changes state; writes `order_state_change`) and `TellsOrderParties`.
- **Support** (`app/Support/Orders/`): `OrderSettlement` + `SettlementFigures` (over the new `PriceCalculator::lockedBreakdown`), `CollectionCodes`, `DeadlinePolicy`, `StaffBranchScope`, `OrderTransitions`, `OrderTimeline`. `DatabaseActor::order()`.
- **Changed**:
  - `AcceptBuyRequestAction` locks the seller's rate and writes the order's first history row;
  - `CancelAcceptanceAction` moves through `MovesOrder`;
  - `ReinstateCustomerAction` stamps `cancellations_reset_at`;
  - `SuspendCustomerRequest` refuses the system reason.
- **Command** `orders:sweep`, every minute. Passes:
  - the suspension threshold;
  - missed reach-branch deadlines;
  - the suspension threshold again;
  - the reach-branch and balance reminders;
  - the unanswered adjustment (a decline, no forfeit);
  - the unpaid balance (forfeit);
  - the seller-return window;
  - the collection window.
- **Notifications**: `OrderNotification` (`OrderEvent`), by SMS plus email, after commit.
- **Error codes**: 10 new ones; DH006 is mapped.
- **Still to build**: Flutter (US11) and the polish tasks (the local order seeder, concurrency, reconciliation, leak and notification tests).

## Database Impact

- **New tables**: `order_state_change`, `order_branch_change`, `order_deadline_extension`, `seller_cancellation`, `inspection_result`, `settlement_decision`, `collection`, `seller_return`. All have forced RLS; a customer sees only rows of their own orders.
- **New `"order"` columns**: `locked_seller_unit_rate`, `decision_due_deadline`, `proposed_*`, the settlement figures, `settlement_txn_id`, `forfeit_txn_id`, `release_txn_id`, the reminder stamps.
- **New `customer` column**: `cancellations_reset_at`.
- **New suspension reason**: `repeated_cancellations`.
- **New `order_transition` rows**: `awaiting_balance → weight_adjust_pending` and `awaiting_balance → cancelled_inspection` (corrections).
- **Order guard**: its own SQLSTATE DH006; it freezes the locked rate and the settlement figures.
- **Deferred checks**: `trg_order_change_recorded` (history row) and `trg_order_money`, which replaces `trg_order_cancel_refunded`: a cancellation needs a release, a no-pay a forfeit, a payment a `balance_payment`.
- **Ledger**: one payment and one forfeit per order (unique indexes); `deposit_release_allowed()` extended to seller and inspection cancellations.
- **`order` scope policies** on `buy_request`, `listing` (the buyer moves it to `sold` / `awaiting_seller_return` only), `listing_state_change`, `listing_media` (public), `listing_branch_option` and `customer` (display_ref).
- **Rollback**: `down()` refuses once any order has moved past acceptance.

## API Changes (built)

| Method + path | Gate / permission | Notes |
|---|---|---|
| `GET /customer/me/orders` · `/{order}` | verified | `CustomerOrder`; codes only in the owner's detail |
| `POST /customer/me/orders/{order}/cancel` | verified | seller; `illegal_order_transition` |
| `POST /customer/me/orders/{order}/decision` | verified | buyer; `{accept, inspection_id}`; `price_not_set`, `inspection_correction_not_allowed` (stale result) |
| `POST /customer/me/orders/{order}/pay-balance` | verified | buyer; `insufficient_funds` (`amount_due`, `available`, `shortfall`), `balance_deadline_passed`, `settlement_not_possible` |
| `POST /customer/me/orders/{order}/relist` | trade | seller; `illegal_listing_transition` |
| `GET /dashboard/orders` | `order.view` | `group` (open, waiting_seller, at_igi, needs_decision, waiting_balance, ready_to_collect, returns, closed, all), `past_deadline`, `branch_id`, `q` (order or customer ref), keyset; `meta.counts` per group |
| `GET /dashboard/orders/{order}` | `order.view` | `StaffOrder` detail with ledger, timeline, `can` |
| `POST /dashboard/orders/{order}/receive` | `order.receive` | branch-scoped; `wrong_branch` |
| `POST /dashboard/orders/{order}/inspection-results` | `inspection.enter` | branch-scoped; `inspection_correction_not_allowed` |
| `POST /dashboard/orders/{order}/propose-price` | `order.price_adjust` | stone regrade only |
| `POST /dashboard/orders/{order}/change-branch` | `order.change_branch` | `{branch_id, reason, extend_to?}`; `order_not_open`, `branch_not_in_options`, `deadline_must_move_forward` |
| `POST /dashboard/orders/{order}/extend-deadline` | `order.extend_deadline` | `{which: reach_branch\|balance\|collect, new_deadline, reason}`; `deadline_not_running`, `deadline_must_move_forward` |
| `POST /dashboard/orders/{order}/handover` | `order.handover` | the buyer's code; `invalid_collection_code` (422), `handover_locked` (429) |
| `POST /dashboard/orders/{order}/seller-return/handover` | `order.handover` | `invalid_collection_code` (422), `handover_locked` (429) |
| `GET /dashboard/inspections/work-list` | `inspection.enter` \| `order.receive` \| `order.handover` | no money, no names |
| `GET /dashboard/inspections` | `inspection.enter` \| `order.view` | results, newest first, last 30 days by default; `from`, `to`, `branch_id`, `outcome`; `superseded` flagged; no money |
| `GET /dashboard/buy-requests` | `buy_request.view` | soonest reply deadline first; `state` (queued, accepted, ended), `listing_id`, `near_expiry`, `branch_id`; `meta.counts` (queued, near_expiry); refs only |

Error envelope (unchanged): `{ message, code, details? }`. Every POST needs an `Idempotency-Key`.

## Dashboard Impact

Built (`../dahab-dashboard`, branch `feature/orders`):

- **Orders** (`order.view`): chips with the Backend's counts, past deadline, search, branch; the order panel with the settlement, every result, the money moved and the history; the actions the Backend allows (`can`) — receive, enter or correct a result, propose a price, change branch, extend a deadline, hand over to the buyer or back to the seller, cancel the acceptance — each in a `DModal` with an `Idempotency-Key`.
- **Inspections**: the branch work list (`order.receive` / `inspection.enter` / `order.handover`) with the task buttons and the result form; the results list (`inspection.enter` / `order.view`).
- **Buy requests** (`buy_request.view`, read-only; not in the design).
- The types for the new order and listing states, the 8 permissions and the new error codes. `ServiceError` now carries the Backend's `details` (`attempts_left`, `retry_after`). The suspension reason label `repeated_cancellations` is shown but never offered in the suspend form.
- Audit labels come from the Backend; nothing to add.

Not built, because the design shows them but the Backend has no such feature: sellers asking for more time, approving the message before it is sent, export to Excel, and People to watch. These are Backend requirements to approve first. Quick "+N working hours" picks are not built either: the Backend has no endpoint that computes working hours, and the client must not compute them.

## Customer App Impact

Built (`../dahab-flutter`, no repository):

- The Orders tab lists the orders (`GET /customer/me/orders`), then the buy requests not accepted; an accepted request is the order. The prototype's mock order cards are gone.
- One order screen (`/order?id=`) for both sides and every stage: bring the piece (branch, live countdown, *Cancel the sale*, *I need more time* → Support), at IGI, the inspection result, accept or decline a new price ("Waiting for Dahab's price" for a regrade), pay the balance (*You need a little more* → *Add funds* on `insufficient_funds`), the collection code, the proceeds, the returned piece with its code and *Put it back on the market*, and every cancellation in plain words (`cancel.reason_kind`).
- The suspension reason `repeated_cancellations` in the plain-language notice (spec 007).
- English and Arabic strings; the fake backend and five flow tests.

The prototype's other order screens (ask for more time with a form, disputes, someone else collects, rating) stay prototype-only: the Backend has no such features.

## Permissions

The codes and their seed holders. The CEO holds every code. All are editable from Staff and permissions → Roles.

| Code | Seeded to |
|---|---|
| `order.view` | COO, Finance, Operations |
| `order.receive` | COO, Operations, IGI (COO added in implementation: it must hold what the Operations role it manages holds) |
| `inspection.enter` | IGI |
| `order.price_adjust`, `order.change_branch`, `order.extend_deadline`, `buy_request.view` | COO, Operations |
| `order.handover` | IGI |

The order codes are **not** spec 002 "branch-scoped" permissions. Branch scope comes from the staff member's assigned branch, and unassigned staff act everywhere (`StaffBranchScope`).

## Follow-ups

US11 and the polish tasks: `specs/012-orders/tasks.md` T057–T060, T073–T082.
