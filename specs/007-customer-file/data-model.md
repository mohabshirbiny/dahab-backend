# Data Model: Customer File v1

The schema docs are updated first (Constitution III): `docs/Database schema/02_schema_identity.sql`, `05_schema_security.sql` and `00_schema_full.sql`.

## customer (altered)

| Column | Change | Notes |
|---|---|---|
| `suspended_reason` | + CHECK | `IN ('piece_misrepresented','off_platform_dealing','repeated_disputes','reported_by_users','identity_unconfirmed','customer_request','other')` or NULL. Old codes are remapped first (research R5). |
| `suspended_note` | **new** `TEXT NULL` | The staff note (1–1000 characters, enforced in the FormRequest). Never sent to the customer. |
| `status_before_suspension` | **new** `TEXT NULL` | CHECK `IN ('pending_verification','active','rejected')`. |

New constraint `customer_suspension_state`: `(status = 'suspended') = (status_before_suspension IS NOT NULL)`.

Existing constraints are kept:
- `suspended_needs_actor`;
- the status/flag coherence CHECK. When suspended, `is_verified = (status_before_suspension = 'active')`.

### State transitions

```
pending_verification ─┐
active ───────────────┼─ suspend(reason, note, staff) ─▶ suspended [status_before_suspension = previous state]
rejected ─────────────┘
suspended ─ reinstate(note, staff) ─▶ status_before_suspension   (suspension fields cleared)
suspended ─ identity review approve / reject ─▶ still suspended; status_before_suspension := active / rejected
```

- Only `Customer::suspend()` / `Customer::reinstate()` enter or leave `suspended`; `transitionTo(SUSPENDED)` throws.
- Suspend while already suspended → `409 customer_already_suspended`. Reinstate while not suspended → `409 customer_not_suspended`.

## idempotency_key (new, `05_schema_security.sql`)

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGSERIAL PK` | |
| `idem_key` | `UUID NOT NULL` | The client's `Idempotency-Key`. |
| `actor_kind` | `TEXT NOT NULL` | CHECK `IN ('customer','staff')`. |
| `actor_customer_id` | `UUID NULL → customer` | Set when `actor_kind = 'customer'`. |
| `actor_staff_id` | `UUID NULL → staff` | Set when `actor_kind = 'staff'`. |
| `endpoint` | `TEXT NOT NULL` | The route name, e.g. `api.v1.dashboard.customers.suspend`. |
| `request_hash` | `CHAR(64) NOT NULL` | SHA-256 of the canonical body plus the route parameters. |
| `state` | `TEXT NOT NULL DEFAULT 'in_flight'` | CHECK `IN ('in_flight','completed','failed')`. |
| `response_status` | `SMALLINT NULL` | |
| `response_body` | `TEXT NULL` | The exact response bytes, replayed as sent (JSONB would reorder keys). |
| `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | |
| `completed_at` | `TIMESTAMPTZ NULL` | |
| `expires_at` | `TIMESTAMPTZ NOT NULL` | `created_at + 24 h`. |

Constraints and indexes:
- CHECK `idempotency_has_actor`: exactly one actor column is set, and it matches `actor_kind`.
- UNIQUE `(actor_kind, COALESCE(actor_customer_id, actor_staff_id), endpoint, idem_key)`, as a unique index on the expression.
- INDEX `(expires_at)`, used by the prune command.
- RLS: forced. The policy is `dahab_rls_elevated() OR actor_customer_id = dahab_current_customer_id()`.
- Mutable by design: the row moves from `in_flight` to `completed` or `failed`, and prune deletes expired rows. It is **not** an audit table.

## audit_log (index only)

`CREATE INDEX idx_audit_actor_customer ON audit_log (actor_customer_id, created_at DESC) WHERE actor_customer_id IS NOT NULL;`

This serves History (research R7, SC-005). It adds no columns and no new write paths.

## Read models (no tables)

- **CustomerFile**: the existing `StaffCustomerVerification` plus `preferred_lang`, `joined_at`, `documents[]` and `suspension` (contracts §1).
- **CustomerActivityEntry**: the Audit log entry shape from spec 006 (`AuditEntryResource` list form).
- **CustomerDevice**: `device_ref`, `first_seen_at`, `last_seen_at`.
- **CustomerSession**: `session_id` (the family id), `started_at`, `last_active_at`, `expires_at`. Open sessions only (ended ones are deleted by sign-out).

## Enums

- `SuspendedReason`: the seven codes above, plus `label()`.
- New error codes, as `DomainApiException` factories (the codebase keeps `AuthErrorCode` for auth failures only; business and protocol refusals are `DomainApiException`):
  - `customer_already_suspended` (409)
  - `customer_not_suspended` (409)
  - `idempotency_key_required` (400)
  - `idempotency_key_mismatch` (422)
  - `idempotency_in_progress` (409)
- `AuditEvent`: none new. `CUSTOMER_SUSPENDED` and `CUSTOMER_UNSUSPENDED` already exist, with labels and the Accounts category.
