# Contract: Dashboard audit log (planning artefact — code and `#[OA]` win)

All under `/api/v1/dashboard`, guard `auth:staff`, ability `staff:access`, `staff.standing`, scheme `dashboardBearer`. Every endpoint requires `audit.view_all` **or** `audit.view_own`. Without `audit.view_all`, results are limited to the caller's own actions.

- `GET /audit-log?from=&to=&category=&actor=&action=&entity_type=&entity_id=&cursor=&per_page=` →
  `{ data: AuditEntry[], meta: { total, per_page, next_cursor|null, from, to, category|null } }`, newest first.
  - `category` absent = Everything (all but `sessions`).
  - `actor` = staff UUID or `system`.
  - `per_page` ≤ 100 (default 50).
  - `422 validation_failed` for a bad date, category or cursor.
- `GET /audit-log/{entry}` → `{ data: AuditEntry + { before, after, device_fingerprint } }`
  - `404 not_found` when missing, or when not visible to the caller.
- `GET /audit-log/export?<same filters>` → `200 text/csv; charset=UTF-8` (BOM), `Content-Disposition: attachment; filename="audit-log-YYYYMMDD-HHmm.csv"`
  - `X-Export-Truncated: true` when the 50,000-row cap was reached.
- `GET /audit-log/categories` → `{ data: [{ value, label, in_everything }] }`

`AuditEntry = { id, at, actor: {type, id?, name?, ref?}, action, label, category, subject|null, before_summary|null, after_summary|null, reason|null, ip|null, outcome|null, entity: {type, id|null} }`
