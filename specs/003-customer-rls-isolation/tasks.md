# Tasks: Customer Data Isolation Enforced by the Database

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [quickstart.md](./quickstart.md)
**Tests**: Required (Constitution V). Isolation is proven against the real PostgreSQL engine as the non-superuser `dahab` role.

## Phase 1: Setup

- [X] T001 Add `app/Support/DatabaseActor.php`: a stack of frames `{scope, customerId, staffId}`, with methods:
  - `push()` / `pop()`: write `app.rls_scope`, `app.current_customer_id` and `app.current_staff_id` with session-level `set_config`; pop restores the previous frame, and the base frame is empty.
  - `bindCustomer()` / `bindStaff()` / `bindNone()`.
  - `elevate(string $scope, Closure $work)`: push, run, pop in `finally`.
  - `isElevated()`, `scope()`, `reset()` (research R1–R2).

  The scopes are `customer`, `staff`, `bootstrap`, `system`, `maintenance`; any other value throws. It is a no-op on non-pgsql drivers.

## Phase 2: Foundational (blocks all stories)

- [X] T002 Migration `database/migrations/2026_09_26_000040_enable_customer_row_level_security.php`. It adds the helpers `dahab_rls_scope()` and `dahab_rls_elevated()`, then `ENABLE` + `FORCE ROW LEVEL SECURITY` with the policies exactly as in [data-model.md](./data-model.md) on `customer`, `customer_password`, `customer_trusted_device`, `identity_document`, `one_time_token`, `audit_log` and `document_view_log`. `down()` drops the policies, runs `NO FORCE` / `DISABLE`, and drops the two helpers.
- [X] T003 Rewrite `app/Http/Middleware/SetDatabaseActor.php`:
  - Customer principal → `push(customer)`; staff principal → `push(staff)` (Clarification Q1); anonymous → `push(none)`.
  - `$next` runs inside `try`, and `pop()` runs in `finally`.
  - Update the docblock: per-request frame, not a leaking session `SET`.
- [X] T004 Add `app/Models/PersonalAccessToken.php` extending Sanctum's model. `findToken()` runs inside `DatabaseActor::elevate('bootstrap')` and eager-loads `tokenable` (research R3). Register it with `Sanctum::usePersonalAccessTokenModel()` in `AppServiceProvider::boot()`, and keep the existing `family` column behaviour.
- [X] T005 Add `app/Http/Middleware/ElevateDatabaseScope.php` (alias `db.elevate`, parameter `bootstrap` only). Apply `db.elevate:bootstrap` in `routes/api.php` to `customer.auth.register.*`, `customer.auth.login` and `customer.auth.otp.*` (research R4).
- [X] T006 In `app/Actions/Auth/Shared/RecordAuditLogAction.php`: when `DatabaseActor::isElevated()` is false, write with a plain `DB::table('audit_log')->insert()` (no RETURNING) and return an unsaved `AuditLog` filled with the written attributes (research R8).
- [X] T007 In `AppServiceProvider`, wire the elevations and their audit rows (research R6–R7):
  - `Queue::before` → push `system` (staff id = `SystemActor::id()`) and audit `rls.system_elevation`.
  - `Queue::after` / `exceptionOccurred` / `failing` → pop.
  - `CommandStarting` for `migrate*` / `db:seed` → push `maintenance` and audit `rls.maintenance_elevation`, only when `audit_log` exists and the system actor exists.
  - `CommandFinished` → pop.

  Add the `AuditEvent` cases `RLS_SYSTEM_ELEVATION` and `RLS_MAINTENANCE_ELEVATION`.
- [X] T008 `tests/TestCase.php`: `setUp()` pushes a `maintenance` frame (no audit) after the parent setup, and `tearDown()` resets `DatabaseActor`. Then run the whole existing suite, which must pass unchanged (SC-004). Fix any real regression in production code, never by loosening a policy.

## Phase 3: US1 + US2 — isolation and fail-closed (P1)

- [X] T009 [P] [US1] `tests/Feature/Isolation/CustomerRowIsolationTest.php`. Build customers A and B with rows in every covered table, then push a `customer(A)` frame and run raw SQL with no owner filter:
  - reads return only A's rows;
  - `UPDATE` / `DELETE` of B's rows affect 0 rows;
  - an `INSERT` owned by B throws;
  - `audit_log` returns 0 rows, but inserting an audit row with A as actor succeeds;
  - `document_view_log` is neither readable nor writable.
- [X] T010 [P] [US2] `tests/Feature/Isolation/ActorBindingTest.php`:
  - no frame → 0 rows and inserts fail;
  - after an HTTP request as A, the next statement on the same connection sees none of A's rows (the maintenance frame is restored);
  - the same after a request that throws;
  - a sync-queued job run during a customer request restores the customer frame afterwards.
- [X] T011 [P] [US2] `tests/Feature/Isolation/DatabaseRoleTest.php`: the application's DB role is not a superuser and lacks `BYPASSRLS`, and every covered table has `relforcerowsecurity`.

## Phase 4: US3 — legitimate cross-customer work (P1)

- [X] T012 [US3] `tests/Feature/Isolation/ElevationTest.php`:
  - registration start → submit and customer login (known device and new device + OTP) succeed with RLS on;
  - a staff member with `customer.view` lists all customers;
  - a queued job writes exactly one `rls.system_elevation` audit row with the system actor;
  - `db:seed` writes `rls.maintenance_elevation`;
  - `db.elevate` only appears on routes without `auth:*`, and exactly on the pinned list.

## Phase 5: US4 — pattern for future tables (P2)

- [X] T013 [US4] `tests/Feature/Isolation/CustomerTableIsolationTest.php`: every `public` table with a `customer_id` / `actor_customer_id` / `buyer_id` / `seller_id` column has forced RLS and ≥ 1 policy. A second case creates a throwaway table inside the test transaction and asserts that the checker reports it.

## Phase 6: Polish

- [X] T014 [P] Docs (Constitution III):
  - `docs/Technical Spec/dahab-spec-part1-auth.md` §5.1: forced RLS, per-unit binding with restore instead of `SET LOCAL` and why, the elevations, and the no-transaction-pooler assumption.
  - `docs/Database schema/05_schema_security.sql` §19: the real policies, the helpers, and a "how to add a customer table" pattern.
  - `docs/platform/architecture.md`: a short "Customer data isolation" note with the pattern.
- [X] T015 Quality gates:
  - `./vendor/bin/pint --test` on the changed files;
  - full `composer test`;
  - `migrate:fresh --seed`, then `migrate:rollback --step=1`, then `migrate` as `dahab`.

  Report per CLAUDE.md Part 1 Step 5 (Dashboard and Customer App intentionally unchanged: no API change).

## Dependencies

T001 → T002–T008 (T002 before T008) → US1/US2 (T009–T011, [P]) → US3 (T012) → US4 (T013) → Polish.
