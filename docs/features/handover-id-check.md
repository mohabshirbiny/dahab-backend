# ID check at the buyer handover

> File: `docs/features/handover-id-check.md` · Branch: worktree `claude/seller-id-display-handoff-b7536b` (Backend), `main` (Dashboard, Flutter)
> Status: done (not deployed) · Date: 2026-10-24

## Goal

At the counter, the branch checks the ID of whoever collects a paid piece:

1. **The buyer collecting in person** — staff see the buyer's verified identity document (the one from sign-up:
   Egyptian ID front + back, or passport) in the handover window, as they already see a named proxy's ID.
2. **A proxy named by the buyer** — the buyer uploads the proxy's ID the way sign-up asks for one: the kind
   (Egyptian ID or passport), the front and the back for an Egyptian ID (the passport photo page, another page
   optional), each with a preview in the app. Staff see both sides.

Builds on spec 014 (proxy collection) and the work-list `proxy` field (2026-10-23).

## Impact Summary

```
Backend:      YES
Database:     YES  (two collection columns, one CHECK, the frozen-proxy trigger)
API:          YES
Dashboard:    YES
Customer App: YES
Auth:         NO
Permissions:  NO   (reuses order.handover | order.view, as the proxy ID does)
```

## Backend Impact

- `ViewBuyerIdAction` — one side of the buyer's latest **verified** identity document, only while the order waits
  for collection (`ready_to_collect`, not collected). Goes through `ViewIdentityDocumentAction` (document view log +
  `identity.document.viewed`) and adds `order.buyer_id_viewed` on the order.
- `ViewProxyIdAction` — takes a side.
- `NameProxyAction` — kind + front + optional back; `RemoveProxyAction` clears the new columns.
- `WorkListItemResource` / `StaffOrderResource` — `buyer_id` and the proxy's `id_kind` / `has_back`.

## Database Impact

`collection.proxy_id_kind` (`egyptian_id` | `passport`, null on rows named before) and
`collection.proxy_id_back_storage_ref`; CHECK: an Egyptian ID has its back. `collection_proxy_frozen()` also
freezes the two columns once collected. No backfill.

## API Changes

| Endpoint | Change | Class |
|---|---|---|
| `POST /customer/me/orders/{id}/proxy` | new required `id_kind` (`egyptian_id` \| `passport`); `id_upload_token` is the front / photo page; new `id_back_upload_token` (`purpose=proxy_id`), required for `egyptian_id`, optional for a passport | Potentially breaking — only consumer is the Flutter app, shipped together |
| `GET /dashboard/orders/{id}/proxy-id` | optional `?side=front\|back` (default front); 404 for a side not uploaded | Non-breaking |
| `GET /dashboard/orders/{id}/buyer-id?side=front\|back` | **new**; `order.handover` or `order.view`; 404 when the order is not waiting for collection or the buyer has no verified document with that side | Non-breaking |
| `GET /dashboard/inspections/work-list` | handover rows add `buyer_id: {doc_kind, has_back}`; `proxy` adds `id_kind`, `has_back` | Non-breaking |
| `GET /dashboard/orders/{id}` | `collection.buyer_id`, `collection.proxy.id_kind|has_back`, `can.view_buyer_id` | Non-breaking |

## Dashboard Impact

Types/services for the new fields; `ProxyIdPhoto` becomes an ID viewer for the buyer or the proxy with the front
and the back; the handover window shows the buyer's ID when the buyer collects and the proxy's when the proxy does,
from both the Inspections page and the order detail.

## Customer App Impact

`orders_api.dart` `nameProxy` sends the kind and both tokens; the proxy form gets the Egyptian ID / Passport choice
and two photo slots with previews (`slotPreview`, like *Upload your ID again*); new texts through the catalogue
(`tool/gen_text_catalogue.py`, manifest copied to the Backend); the fake backend in `test/` follows.

## Authentication / Authorization

Staff guard. The buyer's ID is opened only for an order waiting for collection, with `order.handover` or
`order.view` (same as the proxy ID). Every view is logged (document view log + two audit rows).

## Validation

`id_kind` in (`egyptian_id`, `passport`); `id_back_upload_token` `required_if:id_kind,egyptian_id`. The app mirrors it
(front + back for an ID, the photo page for a passport).

## Error Handling

`upload_token_invalid` (either token), `validation_failed`, 404 `not_found` for a missing side / document.
