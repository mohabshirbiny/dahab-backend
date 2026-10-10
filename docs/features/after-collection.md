# After collection — free relist and rating

> File: `docs/features/after-collection.md` · Branch: `feature/after-collection` in each affected repo
> Status: implemented on the feature branches (not merged, nothing pushed) · Date: 2026-10-10
> Full spec: [`specs/018-after-collection/spec.md`](../../specs/018-after-collection/spec.md)

## Goal

A buyer who collected a piece can put it back on the market at 0% commission within `deadline.free_relist_working_hours` (12) working hours of the staff handover. After a sale each party can rate their experience with Dahab (1–5 stars, optional note). No reputation system, no referral (Spec 019).

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES (additive)
Dashboard:    YES
Customer App: YES
Auth:         YES (trade gate on relist; suspended may rate)
Permissions:  YES (new rating.view — CEO, COO)
```

## Backend Impact
Handover stores the window end (branch resolver); relist action creates a new live listing linked to the origin order (new `draft → live` move for this path only); settlement reads the persistent waiver (commission, VAT, minimum = 0; spread unchanged); only the buyer's invoice is issued; rating store (immutable, one per order and party); `rating.view`; audit events; SMS + email on relist; collected notice gains the window.

## Database Impact
Window end on the order/collection; nullable unique listing→origin-order link; new listing move; rating table with append-only guard and forced RLS; invoice reconciliation accepts a missing seller invoice when waived; permission seed. Dev/test databases: `dahab_wt018`, `dahab_wt018_dev` only.

## API Changes
Additive: `free_relist` and `rating` blocks on customer orders; `POST /customer/me/orders/{order}/free-relist`; `POST /customer/me/orders/{order}/rating`; dashboard order `free_relist` + filter, ratings block with `rating.view`; listing/customer-file references. Names are proposed in the spec. Non-breaking, except the new permission for exhaustive permission maps.

## Dashboard Impact
Order detail panels (Free relist; Ratings with `rating.view`), Orders list filter, Listings line, Customer file History events. No new section.

## Customer App Impact
Live offer with working-hours countdown and relist form on the order screen; rating screen without the invite card; "No Dahab fee on this sale" for the seller; English and Arabic; mocks removed.

## Authentication / Authorization
Relist: customer, trade gate, buyer only. Rating: customer, own party only. Staff: read-only; ratings need `rating.view`.

## Tests / Verification
See the spec's *Test requirements*.

## Out of scope
Referral (Spec 019), rating the other party, public scores, gold certificate badge, staff actions on offers, reminders, spread waiver.
