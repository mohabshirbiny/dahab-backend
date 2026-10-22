# Balance due before a new buy request

> File: `docs/features/balance-due-before-new-request.md` · Branch: `claude/prevent-order-before-settlement-5774d9` in dahab-backend; `main` (uncommitted) in dahab-flutter
> Status: built · Date: 2026-10-22

## Goal

The owner's rule: a buyer paid the 20% deposit, the piece passed IGI, and the balance is waiting,
with the money in the wallet. Before paying, the buyer asks for another piece. That should not
happen: the buyer should finish the open order first.

A new deposit comes out of the same available balance, so it can leave too little to pay the
balance and push the first order into the no-pay forfeit. It also locks another seller's piece.

## Rule

`POST /customer/me/buy-requests` is refused with `409 balance_due_first` while the buyer has an
order in one of these states:

| Order state | Blocks | Why |
|---|---|---|
| `awaiting_balance` | yes | the balance is due now |
| `weight_adjust_pending` | yes | the buyer's answer to a new price is due |
| `awaiting_delivery`, `at_inspection`, `inspection_passed` | no | nothing is asked of the buyer yet |
| `ready_to_collect` | no | already paid; collection is a trip to the branch |
| `disputed` | no | frozen, not the buyer's step |

`details`: `order_id`, `order_ref`, `order_state` (the oldest such order). Nothing is recorded and
nothing is held. Requests already in other lines stay as they are; the seller side is unchanged.

## Impact Summary

```
Backend:          YES  (SendBuyRequestAction, DomainApiException::balanceDueFirst)
Database:         NO
API:              YES  (one new 409 code; potentially breaking — a new validation rule)
Dashboard:        NO   (it never sends buy requests)
Customer App:     YES  (the piece page shows "Finish your open order first" with Open the order)
Auth:             NO
Permissions:      NO
API models/types: NO
```

## Customer App

`balanceDueOf(e)` in `lib/services/api/buy_requests_api.dart`; the piece page's send opens a
dialog with the order reference and *Open the order* (`R.order?id=`). Texts
`buy_request.balance_due.*` (Arabic AR-DRAFT); the fallback toast is in `buyRequestErrorMessage`.
An app version without this shows the generic error until updated.

## Tests

`tests/Feature/BuyRequest/BalanceDueFirstTest.php`; Flutter `test/flows_test.dart` ("a buyer who
owes a balance is sent to that order instead of a new request").
