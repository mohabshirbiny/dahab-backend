# Contract: Orders API (spec 012)

These are planning artefacts. The code and its `#[OA]` attributes are the authority once built. All routes are under `/api/v1`. Money is a decimal string with 4 places, weight a string with 3, and times RFC 3339 in Cairo. Errors use the API's standard envelope `{ message, code, details? }` (corrected during implementation: this draft first said `{ error: {...} }`). Every POST needs an `Idempotency-Key` (a UUID).

## Customer — `/customer/me/orders` (auth: customer)

### `GET /customer/me/orders`
- **Gate**: verified (a suspended customer may read).
- **Query**: `role` = `buyer` | `seller` (optional; both by default), `group` = `open` | `closed` (optional), `limit` (≤ 100, default 20), `cursor`.
- **200** `{ data: OrderItem[], meta: { next_cursor } }`, newest first.

`OrderItem`:
```json
{
  "order_id": "uuid", "order_ref": "DH-2026-000042", "role": "seller|buyer",
  "state": "awaiting_delivery", "stage": "bring_piece|at_igi|decide|pay|collect|done|cancelled|returned",
  "piece": { "listing_id": "uuid", "piece_type": "ring", "category": "gold", "karat_code": 21, "stated_weight_g": "10.000", "cover_photo_url": "…" },
  "branch": { "branch_id": 3, "name": "Nasr City", "address": "…", "hours": [ … ] },
  "counterparty_ref": "6620",
  "locked_total_price": "55631.2500", "deposit_amount": "11126.2500",
  "deadline": { "kind": "reach_branch|decision|balance|collect|return|null", "at": "…", "overdue": false },
  "amount_due": "44505.0000|null",
  "seller_proceeds": "54684.7500|null",
  "inspection": null | {
    "inspection_id": "uuid", "outcome": "pass|weight_adjust|stone_regrade|karat_cancel|fake_cancel",
    "stated_karat": 21, "measured_karat": 21, "stated_weight_g": "10.000", "measured_weight_g": "9.900",
    "weight_diff_pct": "-1.0000", "measured_stone_grade": null, "certificate_number": "IGI-EG-88214",
    "inspector_note": "…", "inspected_at": "…",
    "new_price": "55074.9375|null", "price_pending": false, "decision_needed": true
  },
  "collection": null | { "code_available": true, "collect_deadline": "…", "collected_at": null, "window_passed": false },
  "seller_return": null | { "return_deadline": "…", "code_available": true, "can_relist": true, "collected_at": null, "relisted_at": null, "window_passed": false },
  "cancel": null | { "state": "cancelled_seller", "at": "…", "reason_kind": "seller|deadline_missed|staff|inspection|declined|no_answer|no_pay" },
  "actions": ["cancel","decide","pay","relist"],
  "timeline": [ { "event": "accepted|received|result|decision|paid|collected|branch_changed|deadline_extended|cancelled|forfeited|returned|relisted", "at": "…", "detail": { } } ]
}
```
The other party appears only as `counterparty_ref` (never a name, phone or email). The seller never sees `amount_due`; the buyer never sees `seller_proceeds`.

### `GET /customer/me/orders/{order}`
- **Gate**: verified. A non-party gets **404** (RLS).
- **200** `{ data: OrderItem + { "collection_code": "123456|null", "return_code": "654321|null" } }`. The buyer gets `collection_code` while `ready_to_collect`; the seller gets `return_code` while a return is open.

### `POST /customer/me/orders/{order}/cancel` — the seller cancels
- **Gate**: verified (a suspended seller may wind down). The caller must be the seller (else 404).
- **Body**: none.
- **200** `{ data: OrderItem }` (state `cancelled_seller`).
- **Errors**: `illegal_order_transition` 409 (not `awaiting_delivery`).
- **Side effects**: the buyer refunded; listing → withdrawn; audit `order.seller_cancelled` (actor = the seller). The cancellation counts toward the threshold; a suspension, if due, is applied by the sweep within a minute, not by this request.

### `POST /customer/me/orders/{order}/decision` — the buyer decides on an adjusted price
- **Gate**: verified. Buyer only.
- **Body**: `{ "accept": true|false, "inspection_id": "uuid" }` (`inspection_id` must be the latest result → else `inspection_correction_not_allowed` 409, "the result changed; reload").
- **200** `{ data: OrderItem }`.
- **Errors**: `illegal_order_transition` 409, `price_not_set` 409.
- **Audit**: `order.decided` (actor = the buyer).

### `POST /customer/me/orders/{order}/pay-balance` — the buyer pays
- **Gate**: verified. Buyer only.
- **Body**: none.
- **200** `{ data: OrderItem + collection_code }` (state `ready_to_collect`).
- **Errors**:
  - `insufficient_funds` 409 `details { amount_due, available, shortfall }`
  - `balance_deadline_passed` 409
  - `illegal_order_transition` 409
  - `settlement_not_possible` 409
- **Audit**: `order.paid` (actor = the buyer).

### `POST /customer/me/orders/{order}/relist` — the seller puts a returned piece back on the market
- **Gate**: **trade** (`account_suspended` 403 when suspended). Seller only.
- **Body**: none.
- **200** `{ data: OrderItem }` (`seller_return.relisted_at` set; the listing `live`).
- **Errors**: `illegal_listing_transition` 409 (no open return).
- **Audit**: `order.relisted` (actor = the seller).

## Dashboard — `/dashboard` (auth: staff, MFA as configured)

### `GET /dashboard/orders` — `order.view`
- **Query**:
  - `group` = `open` (default) | `waiting_seller` | `at_igi` | `needs_decision` | `waiting_balance` | `ready_to_collect` | `returns` | `closed` | `all`
  - `past_deadline` = `1`
  - `branch_id`
  - `q` (order ref, or a customer display ref)
  - `limit`, `cursor`
- **200** `{ data: StaffOrderItem[], meta: { next_cursor, counts: { open, waiting_seller, at_igi, needs_decision, waiting_balance, ready_to_collect, returns, past_deadline } } }`.

`StaffOrderItem`:
```json
{
  "order_id", "order_ref", "state", "group",
  "listing_state",
  "piece": { … },
  "seller": { "customer_id", "display_ref" }, "buyer": { "customer_id", "display_ref" },
  "branch": { "branch_id", "name" },
  "value", "held", "paid",
  "deadline": { "kind", "at", "overdue" },
  "accepted_at"
}
```
`customer_id` is there to open the Customer file for `customer.view`.

### `GET /dashboard/orders/{order}` — `order.view`
- **200** `{ data: StaffOrderItem + { locked_total_price, deposit_amount, locked_seller_unit_rate, settlement: { final_weight_g, final_buyer_total, final_seller_gross, commission_amount, vat_amount, spread_amount, seller_proceeds, balance_amount } | null, inspections: [ …all, superseded flagged… ], decision, proposed_price, collection: { collect_deadline, collected_at, handover_by, failed_attempts, locked_until }, seller_return: { … }, branch_changes: [ … ], extensions: [ … ], ledger: [ { ledger_txn_id, event_kind, created_at, lines: [ { account, party, amount } ] } ], timeline: [ … ], can: { receive, inspect, propose_price, change_branch, extend: ["reach_branch"…], handover, return_handover, cancel } } }`.
- Codes are never returned to staff.

### `POST /dashboard/orders/{order}/receive` — `order.receive`, branch-scoped
- **Body**: none.
- **200** detail.
- **Errors**: `wrong_branch` 403, `illegal_order_transition` 409.
- **Audit**: `order.received`.

### `POST /dashboard/orders/{order}/inspection-results` — `inspection.enter`, branch-scoped
- **Body**:
  ```json
  {
    "measured_karat": 21|null,
    "measured_weight_g": "9.900"|null,
    "measured_stone_grade": "VS2 G"|null,
    "certificate_number": "…"|null,
    "inspector_note": "…"|null,
    "is_counterfeit": false,
    "stone_below_claim": false,
    "supersedes_id": "uuid"|null
  }
  ```
- **Validation**:
  - Gold and gold-with-diamond require `measured_karat` and `measured_weight_g` (> 0, 3 dp).
  - Stone categories accept `measured_stone_grade` and `stone_below_claim`.
  - The note is ≤ 2000 characters and the certificate number ≤ 100.
- **201** `{ data: { inspection: {…, outcome}, order_state } }`. The response has no prices for an inspector.
- **Errors**: `wrong_branch` 403, `illegal_order_transition` 409, `inspection_correction_not_allowed` 409.
- **Audit**: `inspection.result_recorded`.

### `POST /dashboard/orders/{order}/propose-price` — `order.price_adjust`
- **Body**: `{ "price": "48000.0000", "reason": "10–1000 chars" }`.
- **200** detail.
- **Errors**: `illegal_order_transition` 409 (not a regrade awaiting a price).
- **Audit**: `order.price_proposed`.

### `POST /dashboard/orders/{order}/change-branch` — `order.change_branch`
- **Body**: `{ "branch_id": 5, "reason": "10–1000", "extend_to": "…"|null }`.
- **200** detail.
- **Errors**: `order_not_open` 409, `branch_not_in_options` 409, `deadline_must_move_forward` 422, 422 if same branch.
- **Audit**: `order.branch_changed`.

### `POST /dashboard/orders/{order}/extend-deadline` — `order.extend_deadline`
- **Body**: `{ "which": "reach_branch|balance|collect", "new_deadline": "…", "reason": "10–1000" }`.
- **200** detail.
- **Errors**: `deadline_not_running` 409, `deadline_must_move_forward` 422.
- **Audit**: `order.deadline_extended`.

### `POST /dashboard/orders/{order}/handover` — `order.handover`, branch-scoped
- **Body**: `{ "code": "123456" }`.
- **200** detail (state `completed`).
- **Errors**: `invalid_collection_code` 422 (`details.attempts_left`), `handover_locked` 429 (`details.retry_after`), `wrong_branch` 403, `illegal_order_transition` 409.
- **Audit**: `order.handed_over` / `order.handover_failed`.

### `POST /dashboard/orders/{order}/seller-return/handover` — `order.handover`, branch-scoped
- **Body**: `{ "code": "654321" }`.
- **200** detail (listing `withdrawn`, return collected).
- **Errors**: as above, plus `illegal_listing_transition` 409 (no open return).
- **Audit**: `order.return_handed_over`.

### `POST /dashboard/orders/{order}/cancel` — `order.cancel` (spec 011, unchanged)

### `GET /dashboard/inspections/work-list` — `inspection.enter` | `order.receive` | `order.handover`
- **Query**: `branch_id` (only for staff with no assigned branch), `limit`, `cursor`.
- **200** `{ data: [ { order_id, order_ref, state, task: "receive|inspect|correct|handover|return_handover", piece_type, category, stated_karat, stated_weight_g, expected_stones, branch, since } ] }`.
- No price, wallet, name, phone or email.

### `GET /dashboard/inspections` — `inspection.enter` | `order.view`
- **Query**: `from`, `to` (Cairo dates, default the last 30 days), `branch_id`, `outcome`, `cursor`.
- **200** `{ data: [ { inspection_id, order_id, order_ref, piece, seller_ref, branch, received_at, inspected_at, stated_karat, measured_karat, stated_weight_g, measured_weight_g, weight_diff_pct, measured_stone_grade, certificate_number, outcome, superseded, order_state } ] }`.

### `GET /dashboard/buy-requests` — `buy_request.view`
- **Query**: `state` = `queued` (default) | `accepted` | `ended`, `listing_id`, `near_expiry` = `1`, `branch_id`, `limit`, `cursor`.
- **200**:
  ```json
  {
    "data": [
      {
        "buy_request_id", "state",
        "listing": { "listing_id", "piece_type", "category", "karat_code", "stated_weight_g", "cover_photo_url" },
        "seller": { "customer_id", "display_ref" }, "buyer": { "customer_id", "display_ref" },
        "place_in_line", "queue_length",
        "locked_total_price", "deposit_amount",
        "requested_at", "seller_reply_deadline", "near_expiry",
        "order_ref"
      }
    ],
    "meta": { "next_cursor", "counts": { "queued", "near_expiry" } }
  }
  ```
- Sorted by `seller_reply_deadline` ascending.

## Consumers

- **Dashboard**: the Orders, Inspections and Buy requests pages.
- **Flutter**: `OrdersApi`.

**Classification**: every endpoint is new, so the change is **non-breaking**. The changes to existing behaviour:

- Order guard errors now answer `illegal_order_transition` instead of `illegal_buy_request_transition` (staff cancel only). This is **potentially breaking** for the Dashboard's error map (handled in the tasks).
- Order and listing states beyond `awaiting_delivery`/`accepted` now occur on the existing seller listing and buyer request resources. This is **potentially breaking** for exhaustive maps in Flutter and the Dashboard (handled in the tasks).
- The permission union gains 8 codes. This is **potentially breaking** for Dashboard types (handled).
