# API contract: staff side of spec 023 (planning artefact; the code and generated OpenAPI win on disagreement)

Envelope as in `docs/platform/api-contract.md`: success `{ "data": … }` (lists may add `"meta"`), errors `{ "message", "code", "errors"?, "details"? }` (flat). Money is a 4-dp string, percentages a decimal string, dates ISO 8601 (Cairo offset). Every POST / PUT / PATCH / DELETE here needs `Idempotency-Key` and is wrapped by the `idempotent` middleware (last). Codes and SQLSTATE mapping: `codes-and-events.md`. All routes sit under `/api/v1/dashboard` with `auth:staff`, `abilities:staff:access`, `staff.standing`.

Consumers (read at planning, 2026-10-12): Dashboard `src/composables/useMarketMakers.ts` and `src/pages/promos|market-maker/index.vue` (the only users of `/dashboard/promo-codes*` and `/dashboard/market-maker/*`; both pages are rewritten here). No Customer-app consumer.

## 1. `GET /dashboard/promo-codes` — changed (additive)

Permission `promo.manage`. Every field of today's row stays (`code, kind, customer, monthly_cap_egp, is_active, commission_waived, gives_spread, uses_this_month, created_at, deactivated_at`); added:

```json
{
  "code": "WELCOME", "kind": "discount",
  "what_it_does": { "commission": { "mode": "percent", "percent": "50.0000" }, "gives_spread": false, "pays_advance": false },
  "tied_to": { "audience": "anyone" },
  "first_sale_only": true,
  "conditions": { "use_limit": "once", "min_listed_days": null, "requires_approval": null, "starts_on": null, "ends_on": null },
  "monthly_cap_egp": null,
  "uses_this_month": 64, "value_this_month_egp": "0.0000",
  "is_active": true
}
```

- `tied_to` for `audience = account` is `{ "audience": "account", "customer": { "id", "display_ref", "full_name", "phone_masked": "+20 10 ••8842" } }`.
- `conditions.min_listed_days` / `requires_approval` are returned **only for market-maker codes** (`null` otherwise); for them `min_listed_days` is the effective value (`code ?? marketmaker.min_list_age_days`) and `requires_approval` the effective `code AND setting`. (research R9, R21)
- `uses_this_month` counts **allowed, non-voided** uses of the Cairo calendar month; `value_this_month_egp` sums their `amount_egp` (shown against `monthly_cap_egp` as `312,400 of 500,000`; a code without a cap shows the count).
- `what_it_does.commission.mode` is `waive | percent | none`; the Dashboard words it (*No commission*, *50% off commission*, the commission unchanged) and prints *Spread goes to them* when `gives_spread`.

## 2. `POST /dashboard/promo-codes` — changed (backward compatible)

Permission `promo.manage`. Today's body `{ code, tied_customer_id, monthly_cap_egp }` still creates a market-maker code exactly as before.

| Field | Rule |
|---|---|
| `code` | required; `^[A-Za-z0-9-]{3,32}$`, stored upper-case; must not equal any referral code (`promo_code_exists`) |
| `kind` | optional `market_maker \| first_sale \| discount`; inferred when absent (research R10); a contradiction is `validation_failed` |
| `audience` | `account` (default when a tie is sent) or `anyone` |
| `tied_to` | account number (`display_ref`) **or** phone, resolved to a customer; `tied_customer_id` (uuid) also accepted; required for `account`, prohibited for `anyone`; unresolved ⇒ `422 promo_account_not_found`; a market-maker tie needs `customer_type = market_maker` (`promo_customer_not_market_maker`) |
| `use_limit` | `unlimited` (default) `\| once \| monthly` — counted per national ID (`id_key`), research R7 |
| `commission_mode` | `waive \| percent \| none`; default: `waive` for a market-maker tie, else `none` |
| `commission_percent` | required iff `percent`; `0 < p < 100`, at most 4 decimals |
| `gives_spread` | boolean; `true` only for a market-maker code (else `validation_failed`); default `true` for market makers |
| `min_listed_days` | integer `0..3650` or null (null = follow the setting); stored for any code, enforced for market-maker codes |
| `requires_approval` | boolean, default `true`; same note |
| `monthly_cap_egp` | `> 0`, `≤ 999,999,999`, or null |
| `starts_on`, `ends_on` | dates; `ends_on ≥ starts_on`; inclusive, Cairo days |
| `first_sale_only` | boolean (default `false`); forced `true` for `first_sale` |
| `pays_advance` | boolean (default `false`); makes the code `first_sale`; forces `first_sale_only`; refused for a market-maker tie |

`201` returns the list item of §1. Audit `promo.created` with every term. Errors: `409 promo_code_exists`, `422 promo_account_not_found | promo_customer_not_market_maker | validation_failed`, `403 permission_denied`. **`promo_kind_not_available` is no longer returned** (its tests change).

## 3. `PATCH /dashboard/promo-codes/{code}` — unchanged

Only `monthly_cap_egp` and `active` change (guard DH020 now also freezes every new term). Audit `promo.updated`. Turning a code off stops **new** redemptions at once; credits already granted carry on (research R2).

## 4. `GET /dashboard/promo-codes/{code}/uses` — changed (additive)

Newest first, 500 max. Each row keeps `used_at, customer, allowed, block_reason, order_id`; adds `value_egp` (the use's `amount_egp`), `listing_ref` (seller side) or `buy_request_ref` (market maker), `credit_status` (`active|exhausted|expired|voided|null`) and `reduction_egp` (from the `spent` event, null until the sale settles). Never the ID hash.

## 5. `GET /dashboard/market-maker/queue` — changed (additive)

Permission `market_maker.approve`. Rows keep today's fields (`views` now a number). Added per row:

```json
{ "listing_id": "…", "title": "Gold bangle, 21K", "weight_g": "30.000", "views": 127, "requests": 0,
  "media": [ { "media_id": "…", "kind": "video" }, { "media_id": "…", "kind": "invoice" }, { "media_id": "…", "kind": "photo" } ],
  "guide": { "kind": "making_charge", "value_pct": 21, "tone": "ok" } }
```

- `views` = rows in `listing_view` for the piece (R18), `requests` = buy requests ever sent on it.
- `media[].kind` ∈ `photo | video | invoice | stone_certificate`; the Dashboard shows *Video*, *Original invoice*, *N photos*, the grey *No video*, and for a stone *GIA certificate* (the certificate kind).
- `guide`: gold ⇒ `{ kind: "making_charge", value_pct, tone: "ok"|"bad" }`; stones ⇒ `{ kind: "none", value_pct: null, tone: "none" }` (no Rapaport yet, R19).
- `meta`: `{ min_list_age_days, require_approval }` (both live settings, for the page's info note).

## 6. `POST /dashboard/market-maker/queue/{listing}/leave` — new

Permission `market_maker.approve`, `idempotent`. Body `{ "note"?: string ≤ 500 }`. Changes nothing about the piece (D23-7); audits `market_maker.left` with the figures seen. `200 { "data": { "listing_id", "left_at" } }`. Errors: `404` (not in the queue), `409 listing_not_live`.

## 7. `GET /dashboard/market-maker/queue/{listing}/media/{media}` — new

Permission `market_maker.approve` (the existing `GET /dashboard/listings/{id}/media/{media}` needs a `listing.*` permission that the approver may not hold). Same streaming and the same **audited view** as `ReadListingMediaAction`; refuses a piece that is not in the queue or approved (`404`).

## 8. `GET /dashboard/market-maker/cost` — changed (additive)

Permission `promo.manage` or `market_maker.approve`. `?month=YYYY-MM` (default this month, Cairo). Sums **stored** figures of orders **settled** in the month (research R17):

```json
{ "month": "2026-10", "pieces_bought": 18,
  "commission_given_up_egp": "14200.0000", "spread_given_up_egp": "3880.0000", "total_cost_egp": "18080.0000",
  "not_recorded": 2,
  "value_egp": "…", "spread_paid_to_dealers_egp": "…", "commission_waived": true }
```

`total_cost_egp` = the two figures added. `not_recorded` = market-maker sales settled before this spec (no stored figures); they are in `pieces_bought` but not in the money. The three old keys stay (`value_egp`, `spread_paid_to_dealers_egp`, `commission_waived`).

## 9. `GET /dashboard/market-maker/approvals`, `POST …/approvals` — unchanged

The rebuilt page no longer calls the list (P23-11). Approving stays as spec 019; `asking_price_seen` etc. unchanged.

## 10. First-sale advance (staff) — new

Permission **`first_sale.manage`** (new; Finance, the CEO holds every code).

- `POST /dashboard/customers/{customer}/first-sale-advance` `{ "reason": 10..1000 chars }` → `201 { "data": { "enablement_id", "seller": {…}, "created_at" } }`. The seller's next eligible gold sale is advanced (actor = this staff member). Errors: `409 first_sale_not_eligible` (the seller already had an advance), `409 first_sale_enablement_open`, `404`. Audit `first_sale.enabled`.
- `DELETE /dashboard/customers/{customer}/first-sale-advance` `{ "reason"?: … }` → `200`; revokes the open enablement. `404 first_sale_enablement_not_found`. Audit `first_sale.revoked`.
- `GET /dashboard/first-sale-advances?status=outstanding|recovered|written_off&cursor=&per_page=` →

```json
{ "data": [ { "advance_id": "…", "order_ref": "DH-2026-000412", "seller": { "display_ref": "C-1042", "full_name": "Mona Adel" },
              "amount_egp": "52000.0000", "basis": "promo_code", "promo_code": "WELCOME", "status": "outstanding",
              "advanced_at": "…", "recovered_egp": null, "shortfall_absorbed_egp": null, "written_off_reason": null } ],
  "meta": { "enabled": false, "cap_egp": "100000.0000", "ceiling_egp": "1000000.0000",
            "outstanding_egp": "0.0000", "outstanding_count": 0, "headroom_egp": "1000000.0000",
            "written_off_total_egp": "0.0000", "shortfall_total_egp": "0.0000", "next_cursor": null } }
```

- Customer file additive: `GET /dashboard/customers/{id}` gains `first_sale` (only for `first_sale.manage`, else absent): `{ "state": "none"|"enabled"|"used", "enablement": {…}|null, "advance": {…}|null }`.
- Staff order detail (`GET /dashboard/orders/{id}`, `order.view`) gains `advance` (as one list item, or null) and `commission_waiver`: `{ "recorded": true, "kind": "credit", "ordinary_egp", "charged_egp", "given_up_egp", "floor_egp", "floor_applied", "code": "WELCOME"|null, "spread_given_up_egp" }` (`recorded:false` and nulls before this spec).

## 11. Identity review — changed (optional field)

`POST /dashboard/identity-documents/{document}/review` (permission `identity.review`) gains an optional `id_number` (exactly 14 digits) honoured only on **approve** of an `egyptian_id`; the server stores its HMAC (`identity_document.id_number_hash`) and returns nothing new. Audit payload carries `id_number_recorded: true`, never the number. The Dashboard form requires it for new approvals (UI only). Non-breaking.

## 12. Settings — additive

`GET /dashboard/settings` lists the 16 new keys (`data-model.md` §7) with their group, bounds and kind; `PATCH /dashboard/settings` validates them through `SettingKey` (bool keys are bool, counts integer, percentages `0 < x < 100`). Permission by group (`pricing.rates.manage` / `settings.manage`), reason required, history append-only — all existing behaviour.

## 13. Classification

| Change | Class |
|---|---|
| New fields on list/queue/cost/uses/order/customer responses | non-breaking |
| New optional request fields on `POST /dashboard/promo-codes`, `id_number` on review | non-breaking |
| New endpoints (leave, queue media, first-sale enable/revoke/list) | non-breaking |
| `kind: first_sale` / `discount` now accepted, `promo_kind_not_available` no longer returned | potentially breaking for a client that relied on the refusal; none exists (the only consumer, the current Dashboard page, is rewritten here) |
| New enum value `discount` / `first_sale` in `kind` of the list | potentially breaking for exhaustive maps; the rewritten page maps all three |
