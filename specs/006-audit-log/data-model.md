# Data Model: Audit Log Viewer

## Existing table (unchanged)

`audit_log` (`docs/Database schema/05_schema_security.sql`). The columns are:
- `audit_id`
- `actor_staff_id`
- `actor_customer_id`
- `action`
- `entity_type`
- `entity_id` (UUID)
- `before_json`
- `after_json`
- `reason`
- `ip_address`
- `device_fingerprint`
- `created_at`

Rows are append-only (trigger) and readable only in elevated scopes (RLS, spec 003).

**Addition (006)**: index `idx_audit_created_id ON audit_log (created_at DESC, audit_id DESC)` for the listing and keyset pagination.

## Code catalogue (no table)

- `AuditEvent::label(): string` and `AuditEvent::category(): AuditCategory`.
- `AuditCategory`: `money`, `pricing`, `accounts`, `identity`, `promo`, `reference`, `sessions`, `system`, each with a `label()`. "Everything" = every category except `sessions`.

## Read model (API)

`AuditEntry`:

| Field | Source |
|---|---|
| `id` | `audit_id` |
| `at` | `created_at` (ISO, Cairo offset) |
| `actor` | `{ type: "staff", id, name }`, `{ type: "customer", id, ref }` (`display_ref`) or `{ type: "system" }` (the system actor) |
| `action` | code |
| `label` | from `AuditEvent` (raw code for unknown) |
| `category` | `AuditCategory` value |
| `subject` | presenter (string or null) |
| `before_summary` | presenter (string or null) |
| `after_summary` | presenter (string or null) |
| `reason` | |
| `ip` | |
| `outcome` | `after_json.outcome` (success / denied / …) |
| `entity` | `{ type, id }` |

The detail read adds `before` and `after` (full JSON) and `device_fingerprint`.

## Permissions (StaffPermission)

| Code | Seed roles (+ ceo) |
|---|---|
| `audit.view_all` | — (ceo only) |
| `audit.view_own` | coo, finance, operations, verification |
