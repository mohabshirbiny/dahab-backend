# Research: Customer Data Isolation Enforced by the Database

**Feature**: [spec.md](./spec.md) · **Date**: 2026-09-26

## R1. Policy shape

**Decision**: One policy per customer-owned table, `FOR ALL`, `USING` and `WITH CHECK` =
`dahab_rls_elevated() OR <owner column> = dahab_current_customer_id()`. Tables are `ENABLE` **and** `FORCE ROW LEVEL SECURITY`, because the application connects as the table owner (`dahab`) and owners bypass RLS unless forced (spec FR-002). Two SQL helpers join the existing `dahab_current_customer_id()` / `dahab_current_staff_id()`:
- `dahab_rls_scope()`: `current_setting('app.rls_scope', true)`, or `''` when unset.
- `dahab_rls_elevated()`: scope ∈ {`staff`, `system`, `bootstrap`, `maintenance`}.

With no scope and no customer the predicate is false, so reads return nothing and writes fail (fail closed, FR-004).

| Table | Owner predicate |
|---|---|
| `customer` | `customer_id` |
| `customer_password` | `customer_id` |
| `customer_trusted_device` | `customer_id` |
| `identity_document` | `customer_id` |
| `one_time_token` | `actor_customer_id` (staff-actor rows are never visible to a customer) |
| `audit_log` | SELECT: elevated only. INSERT: elevated, or `actor_customer_id = current AND actor_staff_id IS NULL` (FR-005). UPDATE/DELETE: no policy (already blocked by the append-only trigger). |
| `document_view_log` | elevated only, all commands (FR-005) |

Later tables with two owners (orders: buyer or seller) use `(buyer_id = cur OR seller_id = cur)`.

**Alternatives**: Per-staff-role database roles were rejected by Constitution v2.0.0. `BYPASSRLS` on a second connection was rejected: it is a whole-connection switch, not a named, scoped, audited elevation.

## R2. Actor binding: per unit of work, not per transaction

**Decision**: The binding (`app.current_customer_id`, `app.current_staff_id`, `app.rls_scope`) is set with session-level `set_config(..., false)` by `App\Support\DatabaseActor`, which keeps a **stack**. Every entry point pushes a frame at its start and pops it in a `finally` block, restoring the previous values, whether the unit succeeds or throws:
- HTTP: `SetDatabaseActor` middleware.
- Queued jobs: `Queue::before` / `after` / `exceptionOccurred` / `failing`.
- Console: migrate and seed commands.
- Explicit elevations: `DatabaseActor::elevate()`.

The bottom of the stack is "no actor" (all empty), so nothing survives a unit.

**Why not `SET LOCAL` per transaction (Part 1 §5.1)?** Binding per transaction would force one transaction around the whole request. Several paths deliberately write an audit row and then throw: permission denied, sign-in failures, escalation denied, and `staff.standing`. A request-wide transaction would roll those audit rows back. Per-unit binding with guaranteed restore gives the same "no carry-over" guarantee (FR-010, SC-003) without losing audit rows. Part 1 §5.1 is updated accordingly (Constitution III).

**Assumption**: no transaction-mode connection pooler (PgBouncer `pool_mode=transaction`) sits between the app and PostgreSQL. With one, session variables are unsafe, and the binding would have to move to per-transaction. This is recorded in Part 1 §5.1.

## R3. Authentication happens before the actor is known

**Finding**: Route middleware priority runs `auth:customer` **before** `SetDatabaseActor`. Sanctum resolves the token and then loads its owner (`customer` row) with no actor set, so under FORCE RLS every customer request would 401.

**Decision**: A custom `App\Models\PersonalAccessToken` (registered with `Sanctum::usePersonalAccessTokenModel`) overrides `findToken()`. It finds the token and loads `tokenable` inside `DatabaseActor::elevate('bootstrap')`, so only the token's owner row is read under elevation, and the elevation ends before any application code runs. `personal_access_tokens` itself is not row-restricted (FR-005).

## R4. Auth bootstrap routes

**Finding**: Registration and email steps validate `Rule::unique('customer', …)` in FormRequests. That runs before the Action and across all customers. Login, the new-device OTP (verify/resend) and registration submit read or create customer rows before any actor exists.

**Decision**: A route middleware `db.elevate:bootstrap` elevates the whole request for exactly these unauthenticated customer routes (`customer.auth.register.*`, `customer.auth.login`, `customer.auth.otp.*`). It is explicit in `routes/api.php`, and an architecture test pins the list (it may only sit on routes without `auth:*`). Staff login touches no customer table and is not elevated.

## R5. Staff requests

**Decision** (Clarification Q1): `SetDatabaseActor` binds scope `staff` plus `app.current_staff_id` for any authenticated staff principal. Permissions still decide everything (FR-022). No audit row for the elevation itself (Q2).

## R6. Queued jobs and the system actor

**Decision**: `Queue::before` pushes `system` scope with `app.current_staff_id = SystemActor::id()` and writes one audit row per job (`rls.system_elevation`, payload: job name, connection, queue; actor: system; Q2). `Queue::after`, `exceptionOccurred` and `failing` pop the frame. With the `sync` queue (tests, local), a job runs inside a request, and the stack restores the request's own scope afterwards. Queued notifications to a `Customer` notifiable need this: unserializing the model reads the `customer` table.

## R7. Console, seeders, tests

**Decision**:
- **Console**: a `CommandStarting` listener pushes `maintenance` scope (system actor, audit row `rls.maintenance_elevation` when `audit_log` and the system actor exist) for `migrate*` and `db:seed`, and `CommandFinished` pops it. Nested `$this->call('db:seed')` inside `migrate:fresh` inherits the outer frame.
- **Tests**: `Tests\TestCase::setUp()` pushes `maintenance` scope without audit, so factories and fixtures work across customers. Each HTTP call in a test pushes and pops its own frame on top, so a request in a test runs exactly as in production. Isolation tests push a customer frame explicitly and query raw SQL.

## R8. Audit writes in a customer context

**Finding**: `audit_log.audit_id` is BIGSERIAL, so Eloquent `create()` issues `INSERT … RETURNING`. PostgreSQL applies the SELECT policy to returned rows, and customers may not read `audit_log` (Q3).

**Decision**: `RecordAuditLogAction` uses a plain `INSERT` (no RETURNING) when the current scope is not elevated, and returns an unsaved `AuditLog` instance carrying the written attributes. The only caller that reads the returned row runs elevated.

## R9. Guarding future tables

**Decision**: `CustomerTableIsolationTest` queries `pg_class`/`information_schema`: every table in `public` having a column named `customer_id`, `actor_customer_id`, `buyer_id` or `seller_id` must have `relrowsecurity` and `relforcerowsecurity` set and at least one policy. It fails the build and names the table otherwise (FR-031). The pattern is documented in `docs/Database schema/05_schema_security.sql` §19 and `docs/platform/architecture.md`.

## Found during implementation

- **Staff auth routes are bootstrap-elevated too.** `dashboard.auth.login` and `dashboard.auth.mfa.*` write staff-attributed audit rows before any actor is bound, and `audit_log` is now RLS-protected. They carry `db.elevate:bootstrap` like the customer auth routes (pinned in `ElevationTest`). Loosening the `audit_log` INSERT policy for anonymous staff-attributed rows was rejected, because it would let those rows be forged.
- **Console elevation covers real CLI runs only.** Laravel fires `CommandStarting`/`CommandFinished` for top-level `php artisan …` runs, not for `Artisan::call()`. Deploy-time `migrate`/`db:seed` are CLI runs and are elevated. Code that calls migrate or seed programmatically must wrap the call in `DatabaseActor::elevate('maintenance', …)` itself.
- **Tests:** teardown only forgets the frame stack (no DB call), because a test may leave its transaction aborted on purpose. A statement on it would skip the rollback and leave locks that hang the next test.
