# Audit Log Viewer

> File: `docs/features/audit-log.md` · Branch: `feature/audit-log` (backend, dashboard; Flutter has no repo yet)
> Status: in progress · Date: 2026-09-28

## Goal

Let staff read the audit trail every feature already writes (Backend spec 006,
[`specs/006-audit-log/`](../../specs/006-audit-log/spec.md)): the **CEO** sees every recorded action; **COO, Finance,
Operations and Verification** see their own actions; **IGI** has no access (Part 1 §4.3). Every sensitive action, in
order, in plain words, with before/after, reason and origin; exportable to Excel.

## Impact Summary

```
Backend:      YES
Database:     YES (one index)
API:          YES
Dashboard:    YES
Customer App: NO
Auth:         NO
Permissions:  YES
```

## Backend Impact

- `AuditEvent::label()` / `category()` for every recorded kind (exhaustive, tested), new `AuditCategory`
  (Money, Pricing, Accounts, Identity documents, Promo codes, Reference data, Sign-ins and sessions, System).
- `app/Support/Audit`: `AuditQuery` (filters, visibility, keyset), `AuditCursor`, `AuditFilters`, `AuditEntryPresenter`
  (subject and before/after summaries; personal fields never in summaries; names loaded once per page).
- Actions: `ListAuditEntriesAction`, `ShowAuditEntryAction`, `ExportAuditEntriesAction` (CSV with BOM, capped, CSV-injection
  safe, records `audit.log.exported`).
- `EnforceStaffPermission` accepts `a|b` (any of the codes).

## Database Impact

Index `idx_audit_created_id (created_at DESC, audit_id DESC)`; no change to rows, trigger or RLS policies.

## API Changes

Non-breaking, new endpoints under `/api/v1/dashboard/audit-log*` — see Part 2 "Audit log viewer".

## Dashboard Impact

`pages/audit/index.vue` (chips, period, count, table, Load more, details, Export to Excel), `components/audit/*`,
`services/audit.service.ts`, `composables/useAuditLog.ts`, `types/audit.ts`; `canAny` in the auth store and
`usePermissions`, `meta.anyPermissions` in the route guard, `anyPermission` on nav items.

## Customer App Impact

Not affected — customers have no audit screen.

## Permissions

| Code | Seed roles (+ ceo) |
|---|---|
| `audit.view_all` | — |
| `audit.view_own` | coo, finance, operations, verification |

## Known gaps vs the design (not faked)

- The design's "Device" (device model, city): the platform records an IP address and a device fingerprint only. The
  column is **Origin** (IP); the fingerprint is in the details.
- Money and Promo codes have no entries until those features exist.

## Testing

- Backend: `tests/Unit/Audit/AuditCatalogueTest.php`, `tests/Feature/Audit/{AuditLogList,AuditLogVisibility,AuditLogExport}Test.php`
  (23 tests), OpenAPI and principal-isolation lists updated.
- Benchmark (SC-004, 1,000,000 rows, local): default 7-day page 150 ms; Pricing 63 ms; 60 days (500,000 matches) 438 ms.
- Browser: CEO sees all 49 entries of the local DB; Finance sees 1 (own); IGI has no menu item and is sent to Forbidden.

## Migration / Compatibility

Deploy the Backend: `php artisan migrate` (index), then `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`
(the two codes). Then the Dashboard.
