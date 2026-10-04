# Disputes and freeze, proxy collection, the seller's request for more time

> File: `docs/features/disputes.md` · Branch: `feature/disputes` (backend, dashboard; Flutter has no repo)
> Status: in progress · Date: 2026-10-03 · Spec Kit: [`specs/014-disputes/`](../../specs/014-disputes/spec.md)

## Goal

A buyer or a seller reports a problem on an order; the order freezes (`disputed`) so no deadline runs and no money
moves; staff work the case in *Disputes and reports* and close it only with a reply — resuming the sale (deadlines
given back the frozen time) or, before payment, ending it against the sale (deposit refunded, piece returned), with
optional compensation and an optional seller suspension. The buyer may name someone else to collect a paid piece;
the counter checks that person's ID. The seller may ask for more time to bring the piece; staff accept with 6, 12,
24 or 48 working hours or refuse.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (6 customer + 9 dashboard endpoints; 2 upload purposes; order/customer-file fields added; handover body extended)
Dashboard:    YES (Disputes and reports page; dispute / extension request / proxy in the order detail; More time requested; handover proxy check)
Customer App: YES (Report a problem, dispute status and reply, Someone else collects, Ask for more time; the disputed state)
Auth:         NO
Permissions:  YES (dispute.handle, order.refund, compensation.pay, compensation.uncapped)
```

**Classification**: new endpoints and response fields are non-breaking; the handover keeps spec 012's behaviour by
default. Potentially breaking: orders can now be `disputed`; new `OrderEvent` / audit values; the permission union
(+4) and the upload purpose enum (+2).

## Decisions (product owner, 2026-10-03 — spec Clarifications)

| Question | Decision |
|---|---|
| Who / when | Buyer or seller, from `at_inspection`, `weight_adjust_pending`, `awaiting_balance`, `ready_to_collect`; never from `awaiting_delivery`. One dispute per party per order, never two unresolved. |
| Against the sale | Only before payment: `cancelled_inspection`, the deposit refunded in full, the piece returned. A paid order can only be resumed (optionally with compensation); unwinding a paid order is deferred. |
| Resume | Every running deadline pushed forward by exactly the frozen time, automatically (extension rows naming the dispute). |
| Suspension | Never automatic; an optional *Suspend the seller* on an against-the-sale resolution (needs `customer.suspend`). |
| Compensation | Only inside a resolution; `compensation.pay` up to `compensation.cap_per_payment_egp` / `cap_per_day_egp` per staff member per Cairo day; `compensation.uncapped` lifts the caps. |
| Permissions | `dispute.handle` — CEO, COO, Operations, Finance; `order.refund`, `compensation.pay` — CEO, Finance; `compensation.uncapped` — CEO only. Never the COO on money. |
| Privacy | A dispute, its photos and history are readable (database-enforced) only by the customer who raised it; the other party sees the order frozen and, later, resumed or cancelled. |
| Proxy | Named on a ready-to-collect order (name, phone, ID photo, the authorisation tick). One SMS to the proxy without the code; the buyer shares the code. The ID check is required when the proxy collects. |
| More time | The seller gives a reason and a line; staff pick 6 / 12 / 24 / 48 working hours or refuse. *Sold elsewhere* is the seller cancel. A request lapses when the order moves on. |
| Out of scope | Staff approval of inspection messages; listing reports (`RPT-…`); stand-alone compensation / refund screens; re-inspection; a reply thread. |

## Backend Impact

- **Migration** `2026_10_07_000010_create_disputes.php`.
- **Actions**: `Disputes/Customer/OpenDisputeAction`; `Disputes/Staff/{List,Show,ViewDisputePhoto,ListDisputeAssignees,PassOnDispute,ResolveDispute}Action`; `Disputes/{PayCompensation,GiveBackFrozenTime}Action`; `Orders/Customer/{NameProxy,RemoveProxy,RequestMoreTime}Action`; `Orders/Staff/{ListExtensionRequests,AcceptExtensionRequest,RefuseExtensionRequest,ViewProxyId}Action`; `MovesOrder::assertNotFrozen` in the order Actions; the handover's proxy check.
- **Notifications**: six new `OrderEvent`s; `ProxyNamedNotification` (on-demand SMS, no code).

## Database Impact

`dispute` (extended per `05_schema_security.sql` §16), `dispute_photo`, `dispute_change`, `compensation`,
`order_extension_request`, `dispute_transition`, `extension_request_transition`; `collection` proxy columns;
`order_deadline_extension` (`which` + `decision`, `dispute_id`, `extension_request_id`); three `order_transition`
rows; guards DH009 / DH010; forced RLS on five tables; legal document `collection_proxy_authorisation`.

## API Changes

See [`specs/014-disputes/contracts/disputes-api.md`](../../specs/014-disputes/contracts/disputes-api.md).

| Method + path | Who |
|---|---|
| `POST /customer/me/orders/{order}/disputes` | party, verified |
| `POST /customer/me/orders/{order}/extension-requests` | seller, verified |
| `POST /customer/me/orders/{order}/proxy` · `/proxy/remove` | buyer, trade / verified |
| `GET /dashboard/disputes` · `/{dispute}` · `/{dispute}/photos/{photo}` · `GET /dashboard/dispute-assignees` | `dispute.handle` |
| `POST /dashboard/disputes/{dispute}/pass-on` · `/resolve` | `dispute.handle` (+ `order.refund`, `compensation.pay`, `customer.suspend` per field) |
| `GET /dashboard/extension-requests` · `POST …/{request}/accept|refuse` | `order.extend_deadline` (`order.view` reads) |
| `GET /dashboard/orders/{order}/proxy-id` | `order.handover` or `order.view` |
| `POST /dashboard/orders/{order}/handover` (+`collector`, `proxy_id_checked`) | `order.handover` |

New error codes: `order_frozen`, `dispute_already_raised`, `dispute_outcome_not_allowed`, `illegal_dispute_transition`,
`assignee_not_eligible`, `extension_request_pending`, `illegal_extension_request_transition`.

## Dashboard Impact

`src/pages/disputes/index.vue`, `src/components/disputes/*`; the order detail panels, `ExtensionRequestsTable`,
`AnswerExtensionModal`, the handover modal; types / services / errors; navigation *Disputes and reports*.

## Customer App Impact

`DisputeScreen`, `ProxyScreen`, `ExtendScreen` on the API; the order screen's on-hold, dispute, request and proxy
blocks; models and `orders_api.dart`; EN/AR; fake backend and flow tests.

## Follow-ups

Unwinding a paid order; staff approval of inspection messages; listing reports; stand-alone compensation and refund;
re-inspection; a reply thread; automatic repeated-disputes suspension; the Withdrawals "open disputes" signal.
