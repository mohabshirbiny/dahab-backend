# Make a customer a market maker (from the Customer file)

> File: `docs/features/market-maker-designation.md` · Branch: `claude/market-maker-steps-9ab0d7` (Backend worktree) and the Dashboard checkout
> Status: built, uncommitted · Date: 2026-10-24

## Goal

A dealer code can only be tied to an account whose `customer.customer_type` is `market_maker`
(`CreatePromoCodeAction`, spec 019/023). Until now nothing in the platform set that type: it was written only
by the seeder and by hand in the database. Staff can now make a customer a market maker, or an ordinary customer
again, from the Customer file, with a note, audited.

## Impact Summary

```
Backend:      YES
Database:     NO
API:          YES
Dashboard:    YES
Customer App: NO
Auth:         NO
Permissions:  NO
```

## Backend Impact

- `SetCustomerTypeAction` (locks the customer row, refuses an unchanged type, refuses removing `market_maker` while a
  dealer code tied to the account is still active, writes one audit entry in the same transaction).
- `SetCustomerTypeRequest` (`type` in `ordinary|market_maker`, `note` 1–1000), `CustomerController::setType`, route
  `POST /dashboard/customers/{customer}/type` (`staff.permission:promo.manage`, `idempotent`).
- `AuditEvent::CUSTOMER_TYPE_CHANGED` (`customer.type_changed`, category PROMO; not in the founder-alert list — creating the
  code is what alerts the founders). `DomainApiException::customerTypeUnchanged`, `customerHasActiveDealerCode`.

## Database Impact

None. `customer.customer_type` and its CHECK (`ordinary|market_maker`) already exist.

## API Changes

`POST /dashboard/customers/{customer}/type` — body `{ "type": "ordinary|market_maker", "note": string }`, header
`Idempotency-Key`. `200 { data: StaffCustomerFile }` (already carries `customer_type`). Errors: `403 permission_denied`,
`404 not_found`, `409 customer_type_unchanged`, `409 customer_has_active_dealer_code`, `422 validation_failed`.
Classification: **non-breaking** (new endpoint).

## Dashboard Impact

`endpoints.customerType`, `customerService.setType`, `useSetCustomerType`, the new `SetCustomerTypeForm.vue`, a *Type* row
on the profile card, and a *Make market maker* / *Remove as market maker* button on the Customer file (gated by
`promo.manage`), beside Suspend / Reinstate. The two new error codes map to `conflict`.

## Customer App Impact

Not affected — the app already reads `customer_type` from `GET /customer/me`; this feature only changes who can set it.

## Authentication / Authorization

Staff surface (`auth:staff`). Permission `promo.manage` (reused; Finance and the CEO hold it): the same people who create
dealer codes decide who is a dealer.

## Permissions

Reused: `promo.manage`. No new permission, no seeder change. The button is hidden without it.

## Validation

Backend authoritative: type must be one of the two values, note required (max 1000). The Dashboard mirrors the note being
required for UX only.

## Error Handling

`customer_type_unchanged` — "already has that type, reload". `customer_has_active_dealer_code` — "turn off the dealer
code in Promo codes first". Both shown inline in the form.

## UI States

Customer file: button hidden without `promo.manage` or for a closed account; form with pending state, inline error, toast
on success; the file replaces itself from the response (no extra "file opened" audit).

## Testing

`tests/Feature/MarketMakers/CustomerTypeTest.php` (5 tests: make + audit + dealer code now allowed, remove blocked while the
code is on and allowed once off, unchanged type, validation, permission). Dashboard `type-check` and `build` pass; the
project's existing lint errors are unrelated to these files. Postman: *Set Customer Type (Market Maker)*.

## Breaking Changes

None.

## Migration / Compatibility

Deploy Backend first, then the Dashboard. Existing market-maker accounts (set by hand) are unaffected and can be changed
back through the same button.
