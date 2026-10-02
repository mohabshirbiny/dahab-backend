# Buy Requests

> File: `docs/features/buy-requests.md` · Branch: `feature/buy-requests` (backend, dashboard; Flutter has no repo)
> Status: done (pending review and merge) · Date: 2026-10-01 · Spec Kit: [`specs/011-buy-requests/`](../../specs/011-buy-requests/spec.md)

## Goal

A buyer asks to buy a live piece: a deposit is held from their wallet and they join the piece's line. The seller
answers the first in line — accept (an order is opened with the branch and the deadline to reach it; everyone else
is refunded) or decline. Nobody's money is ever stranded: an unanswered request expires, a taken-down or withdrawn
piece and a suspended seller's piece release the line, and staff can cancel an accepted sale.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (7 new endpoints + changed: withdraw, take-down, suspend, market detail, listing show/index)
Dashboard:    YES
Customer App: YES
Auth:         YES (small: a non-elevated `queue` database scope; no change to sign-in, tokens or gates)
Permissions:  YES (new order.cancel — ceo, coo, operations)
```

Classification: non-breaking (new endpoints, optional fields), with potentially breaking enum growth handled in both
apps: listing states `reserved` / `accepted`, the permission `order.cancel`, the audit category `orders`.

## Decisions (product owner, 2026-09-30 / 10-01)

| Question | Decision |
|---|---|
| Acceptance | Creates the `"order"` row: `DH-YYYY-NNNNNN`, chosen branch, reach-branch deadline from the working-hours resolver, `awaiting_delivery`. Nothing moves it afterwards until the orders spec. |
| Reply clock | `deadline.seller_reply_hours` clock hours from the buyer's join; then released and refunded. |
| Decline | Head only, no reason. |
| Deposit | `deposit.buyer_pct` of the locked price, half-up to the piastre. Short wallet → 409 `insufficient_funds` with the shortfall → app's *Add funds*. |
| Price lock | Within `buyrequest.price_tolerance_pct` (0.5, new operations setting) the fresh price locks; beyond it 409 `price_moved`. |
| Terms | `legal_document` `deposit_agreement` v1 (draft for the legal clinic); accepted with each request. |
| Suspension | Seller: reserved pieces held, lines released and refunded. Buyer: requests stay; a suspended buyer cannot be accepted. |
| Limits | None beyond one active request per buyer per piece; own piece refused (`cannot_buy_own_listing`). |
| Take-down | Staff and the seller, from reserved: the line is released and refunded. |
| Staff cancel (analysis H3) | `order.cancel` (CEO, COO, Operations): `cancelled_staff`, full refund, relist or withdraw, audited, both sides told. |
| Dashboard | Read-only line and order in the listing panel; Buyers in line / Accepted chips; no Orders page yet. |

## Backend Impact

- `app/Actions/BuyRequests/*`: send, list own, leave, seller queue, accept, decline, release a whole line, expire,
  notify-when-free; concerns `RunsInQueue` and `ReleasesRequests`. `app/Actions/Orders/CancelAcceptanceAction`.
- Changed: `WithdrawListingAction`, `DecideListingAction` (take-down from reserved), `HoldListingsOfCustomerAction`
  (reserved pieces on suspension; notify-when-free on reinstatement), `ListListingsForReviewAction` (queue, order,
  counts).
- Support: `DepositLedger` (holds/releases through `PostLedgerEntryAction`), `DepositRule`, `PlaceInLine`,
  `OrderReference`, `BuyRequestTransitions`, `BuyRequestCursor`; `DatabaseActor::queue()`.
- Models `BuyRequest`, `Order`; enums `BuyRequestState`, `OrderState`, `BuyRequestEvent`; `SettingKey`,
  `StaffPermission::ORDER_CANCEL`, `AuditCategory::ORDERS`, `AuditEvent::ORDER_CANCELLED`.
- `BuyRequestNotification` sent through `NotifyCustomerJob` (system scope) after commit; `NotifyWhenFreeJob`.
- Command `buy-requests:expire`, every minute.
- 13 new error codes; `DomainApiException` may carry `details`; DH005 and commit-time PDO errors mapped.

## Database Impact

One migration `2026_10_04_000010_create_buy_requests.php`: `buy_request_state`, `order_state` (+`cancelled_staff`),
`buy_request`, `"order"`, `order_ref_seq`, the transition tables, guard / money / consistency triggers (deferred ones
read under a scope they set), ledger FKs (`lt_request_fk` deferred) and one-hold / one-release unique indexes, forced
RLS and the `queue` policies, two listing moves out of `accepted`, the new setting and the deposit terms. **Rollback
refuses while any buy request exists** (they are tied to append-only ledger entries); this needs a designated second
reviewer from the backend/database review group, recorded in the PR before merge.

## API Changes

See [`specs/011-buy-requests/contracts/buy-requests-api.md`](../../specs/011-buy-requests/contracts/buy-requests-api.md).

- Customer: `POST|GET /customer/me/buy-requests`, `GET /customer/me/buy-requests/{id}`,
  `POST /customer/me/buy-requests/{id}/withdraw`, `GET /customer/me/listings/{id}/buy-requests`,
  `POST /customer/me/listings/{id}/accept|decline`.
- Dashboard: `POST /dashboard/orders/{id}/cancel`.
- Changed: market detail `deposit_amount`; seller listing `queue_count`, `order`, `can_withdraw` from reserved; staff
  listing `queue`, `order`, `can_take_down` from reserved, `meta.counts.reserved|accepted`; withdraw / take-down /
  suspend release a line.

## Dashboard Impact

Listings to review: *Buyers in line* and *Accepted* chips; `ListingQueuePanel`, `ListingOrderBox`,
`CancelAcceptanceModal` (`DModal`); the take-down modal counts the buyers refunded; the new setting on Commission
rates; `order.cancel` in `src/types/staff.ts`. The Orders page stays mock.

## Customer App Impact

`ApiBuyRequestsRepository`, `ApiOrdersRepository` (the buyer's requests lead Orders), models in
`lib/models/buy_request.dart`; the piece page (deposit, line, send with the terms, place in line, leave; the seller's
Accept with branch pick / Decline); *Request sent* and *You need a little more* on the API's figures. Order life after
acceptance stays mock. Also fixed: the piece page's reload after an action never ran (a `setState` callback returned
a Future).

## Testing

- Backend (isolated DB `dahab_wt011`): full suite **1429 passed, 0 failed** (perf excluded; before the last Pint
  import reordering — see the final report for the re-run); `tests/Feature/BuyRequest/*` covers send/refusals, leave,
  isolation, the seller's answer, expiry (command, effects read through the API), take-down/withdraw/suspension,
  staff cancel, schema guards, the queue scope's limits, real commits and races on two connections, and ledger
  reconciliation.
- Performance (`--group=perf`, T053): send with 50 in line p95 ≈ 55 ms (target 300); sweep of 1,000 due requests
  ≈ 19 s (target 60).
- Local data: `LocalBuyRequestSeeder` — Hoda first in line on Karim's earrings; order `DH-2026-000001` on Hoda's
  ring; a demo gold price only when there is none.
- Dashboard: `type-check` OK, lint clean on every touched file, `build` OK.
- Flutter: `analyze` no issues, `test` 38 passed (4 new flows), `build web --release` OK.

## Follow-ups

The orders spec (delivery sweep, seller cancel + suspension count, branch change, IGI, balance, forfeiture,
settlement) — until then staff cancellation is the only exit for an accepted sale. The orders spec also brings a
Dashboard **Buy requests** page (product owner, 2026-10-01; not in the design reference): every open request across
listings with the buyer, the piece, the place in line, the deposit held and the seller-reply deadline, behind a new
read endpoint and permission to be settled in its clarify. Until then the line is read from each listing. Category controls and market makers. A Dashboard Orders / Buy requests page.
