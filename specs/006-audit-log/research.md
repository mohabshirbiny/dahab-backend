# Research: Audit Log Viewer

**Feature**: [spec.md](./spec.md) · **Date**: 2026-09-27

## R1. No schema change

**Decision**: Read the existing `audit_log` table (schema §13, `05_schema_security.sql`) as it is. Its forced-RLS read policy allows elevated scopes only, and staff requests run in the `staff` scope, which is elevated (spec 003).

The existing indexes are `created_at`, `actor_staff_id` and `(entity_type, entity_id)`. One index is added for the default listing and keyset pagination: `(created_at DESC, audit_id DESC)`. It is recorded in the schema doc first (Constitution III). No trigger or policy changes.

## R2. Catalogue: label, category, subject and summary per event

**Decision**: `AuditEvent` (the enum every feature already writes through) gains:

- `label()`: plain English ("Manual gold price entered").
- `category()`: returns an `AuditCategory` enum. The cases are `money`, `pricing`, `accounts`, `identity`, `promo`, `reference`, `sessions` and `system`.

The mapping:

| Category | Events |
|---|---|
| sessions | `auth.*` sign-in/failed/OTP/MFA/password-reset/token events, and `auth.staff.permission_denied` |
| accounts | `authz.*`, customer suspended/unsuspended, registration submitted |
| identity | `identity.document.*`, customer verification approved/rejected/details viewed |
| reference | `reference.*` |
| pricing | `pricing.*` |
| system | `rls.*` |
| money, promo | none yet |

A **test** fails when an `AuditEvent` case has no label or category (the `match` has no default arm). Stored action codes that are not in the enum (legacy rows) are shown with the raw code as the label, under `system`, never dropped.

**Subject and summaries**: an `AuditEntryPresenter` builds, per event:

- `subject`: for example "21K", "IGI Nasr City", "commission.gold_pct", the role's display name, the staff member's name, or the customer's `display_ref`;
- `before_summary` and `after_summary`: short strings, for example "20 → 18" split into before "20" and after "18"; "On"/"Off"; "−15 EGP fixed".

The subject comes from `entity_type`/`entity_id`, or from the integer ids kept in the payload (`karat_code`, `branch_id`, `closure_id`, the role name, the setting key). Names are looked up **in one batch per page**, never per row. The fallback, when an event has no specific rule, is a compact JSON cut to 80 characters. The labels are Backend data, so any future export or screen says the same thing.

## R3. Visibility

**Decision**: Two catalogue codes (spec 002):

| Code | Label | Seed roles |
|---|---|---|
| `audit.view_all` | View the whole audit log | ceo (gets everything anyway) |
| `audit.view_own` | View your own actions in the audit log | coo, finance, operations, verification |

The route middleware accepts either code: `EnforceStaffPermission` is extended to take `a|b` (any of the listed codes), e.g. `staff.permission:audit.view_all|audit.view_own`. Single codes behave as before. The Action applies `actor_staff_id = me` **unless** the viewer holds `audit.view_all`. This covers the list, the count, the export and the single-entry read:
- another person's entry → `404`, which does not reveal that it exists;
- an `actor` filter naming someone else → an empty result.

Customer-actor rows (`actor_customer_id`) are therefore visible to `view_all` holders only.

## R4. Pagination and counting

**Decision**: Keyset ("cursor") pagination on `(created_at, audit_id)` descending. The cursor is an opaque base64 of the last `(created_at, audit_id)`, and the page size is at most 100 (default 50). New entries arriving between pages cannot shift or duplicate rows (FR-001).

`meta.total` is an exact `count(*)` under the same filters. The default period (7 days) keeps it bounded, and the plan's SC-004 check uses a seeded 1M-row table in a benchmark test (manual).

## R5. Filters

- `from` / `to`: ISO dates in Cairo time; the default is the last 7 days.
- `category`: one of the 8; absent = "Everything", which excludes `sessions` (Clarification 1).
- `actor`: a staff UUID, or `system`.
- `action`: an exact code.
- `entity_type` and `entity_id`.

Category filtering turns into `action IN (codes of that category)`, taken from the enum. Unknown legacy codes fall under `system`, so the `system` filter adds `action NOT IN (all known codes)`.

## R6. Export

**Decision**: `GET /dashboard/audit-log/export`, with the same filters, streams `text/csv; charset=UTF-8` with a BOM, so Excel opens Arabic correctly.

- **Columns**: When (Cairo), Who, What, Subject, Before, After, Reason, IP, Device fingerprint.
- **Streaming**: a lazy cursor (`lazyByIdDesc` over the keyset) keeps memory flat.
- **Cap**: 50,000 rows. When the cap is hit, the file's last line says so, and the response header `X-Export-Truncated: true` is set.
- **Filename**: `audit-log-YYYYMMDD-HHmm.csv`.
- **Permissions**: the same visibility rules (R3).
- **Audit**: each export writes one `audit.log.exported` entry (category `system`, actor = the staff member) with the filters, the row count and `truncated`. It is written after the stream completes, through `RecordAuditLogAction` (spec FR-011, analysis S1). Screen reads stay unaudited.
- **Injection guard**: cells starting with `= + - @` are prefixed with `'` (CSV injection).

## R7. Endpoints

| Method | Path | Permission |
|---|---|---|
| GET | `/dashboard/audit-log` | `audit.view_all` or `audit.view_own` |
| GET | `/dashboard/audit-log/{entry}` | same (own-only filter applies) |
| GET | `/dashboard/audit-log/export` | same |
| GET | `/dashboard/audit-log/categories` | same (labels, and which categories "Everything" includes) |

## R8. Dashboard

- The `audit` placeholder becomes `pages/audit/index.vue`:
  - the lead sentence;
  - `FilterChips` for "Everything" plus the 8 categories;
  - a period select (7 days / 30 days / custom range);
  - an optional actor filter (only for `view_all`: a staff select from `GET /dashboard/staff` when the viewer also has `staff.view`, otherwise hidden).
- The "Log" panel shows the count, an "Export to Excel" button (a Blob download through the authenticated client), a table, "Load more" (cursor) and an entry detail modal (pretty JSON before/after, reason, IP, fingerprint).
- The nav item is unhidden and gated on either audit code (a new `anyPermission` support in `nav.ts` if not present).
