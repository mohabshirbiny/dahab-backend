# Data Model: Customer Data Isolation

No new tables or columns. Migration `2026_09_26_000040_enable_customer_row_level_security` adds:

## SQL helpers

| Function | Returns |
|---|---|
| `dahab_rls_scope()` | `current_setting('app.rls_scope', true)` or `''` |
| `dahab_rls_elevated()` | `dahab_rls_scope() IN ('staff','system','bootstrap','maintenance')` |
| `dahab_current_customer_id()` / `dahab_current_staff_id()` | existing (migration `2026_09_19_003010`) |

## Session settings (per unit of work, restored by `DatabaseActor`)

| Setting | Values |
|---|---|
| `app.rls_scope` | `''` (none), `customer`, `staff`, `bootstrap`, `system`, `maintenance` |
| `app.current_customer_id` | customer UUID in `customer` scope, else `''` |
| `app.current_staff_id` | staff UUID in `staff` scope; the system actor's id in `system` / `maintenance`; else `''` |

## Policies (all tables `ENABLE` + `FORCE ROW LEVEL SECURITY`)

| Table | Policy | Commands | Rule |
|---|---|---|---|
| `customer` | `customer_isolation` | ALL | elevated OR `customer_id = dahab_current_customer_id()` |
| `customer_password` | `customer_password_isolation` | ALL | elevated OR `customer_id = cur` |
| `customer_trusted_device` | `customer_trusted_device_isolation` | ALL | elevated OR `customer_id = cur` |
| `identity_document` | `identity_document_isolation` | ALL | elevated OR `customer_id = cur` |
| `one_time_token` | `one_time_token_isolation` | ALL | elevated OR `actor_customer_id = cur` |
| `audit_log` | `audit_log_read` | SELECT | elevated |
| `audit_log` | `audit_log_write` | INSERT | elevated OR (`actor_customer_id = cur` AND `actor_staff_id IS NULL`) |
| `document_view_log` | `document_view_log_staff` | ALL | elevated |

## Audit actions (new)

| Action | When | Actor |
|---|---|---|
| `rls.system_elevation` | a queued job starts | system actor; payload `{job, connection, queue}` |
| `rls.maintenance_elevation` | `migrate*` / `db:seed` starts (when `audit_log` and the system actor exist) | system actor; payload `{command}` |
