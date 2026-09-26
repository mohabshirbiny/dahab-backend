# Research: Dynamic Staff Authorization, Customer Verified Gate, System Actor

**Feature**: [spec.md](./spec.md) · **Date**: 2026-09-26

Every item below resolves a design question raised by the spec. The Technical Context in [plan.md](./plan.md) has no open NEEDS CLARIFICATION.

---

## R1. Where roles live

**Decision**: Keep Spatie `laravel-permission` (already installed, guard `staff`) as the role store. Add columns to Spatie's `roles` table: `display_name`, `description`, `requires_mfa`. Point `config/permission.php` → `models.role` at a new `App\Models\StaffRoleModel` (extends `Spatie\Permission\Models\Role`). The machine name is Spatie's `name` column, immutable after creation.

**Rationale**: Role→permission and model→role pivots, caching and cache busting already exist and are used by `EnforceStaffPermission` and `StaffResource`. Adding three columns turns the fixed seed into editable data with no parallel table.

**Alternatives considered**: A custom `staff_role_def` table plus our own pivots duplicates Spatie and breaks `$staff->can()`. Spatie "teams" is not needed; there is one tenant.

## R2. The permission catalogue

**Decision**: `App\Enums\StaffPermission` stays the single catalogue. Add `label()`, `group()`, `isBranchScoped()`, and `seedRoles()` (renamed from `roles()`, now seed-only and returning role **names**). A new idempotent `SyncPermissionCatalogueAction`, run by `DashboardRolesAndPermissionsSeeder` on every deploy:

1. Creates a `permissions` row for each enum case that has none. For each **newly created** permission it attaches the permission to `ceo` and to its `seedRoles()` (spec FR-070).
2. Deletes `permissions` rows whose name is no longer an enum case (the pivot cascade removes them from roles).
3. Creates the six seed roles **only if they do not exist**, with their seed permission sets. Existing roles are never re-synced, so Dashboard edits survive deploys.

**Rationale**: Today's seeder calls `syncPermissions()` on every run. Once roles are editable, that would silently wipe Dashboard changes on the next deploy. That is the main regression risk of the feature.

**Alternatives considered**: A catalogue in a DB table editable by users is rejected by the spec (FR-001), since a code nobody checks protects nothing. A config-file catalogue duplicates the enum.

## R3. Removing the fixed `staff.role` column

**Decision**: One migration:
- Backfills: for every staff row, ensure `model_has_roles` holds the Spatie role named like `staff.role` (creating the role if needed).
- Adds `staff.is_founder BOOLEAN NOT NULL DEFAULT false`, backfilled `true` where `role IN ('ceo','coo')`.
- Adds `staff.is_system BOOLEAN NOT NULL DEFAULT false`, with `CHECK (NOT (is_system AND is_founder))` and a partial unique index `ON staff ((true)) WHERE is_system` (at most one).
- Drops `CONSTRAINT igi_has_branch`, drops column `staff.role`, drops type `staff_role`.
- `down()` recreates the type and column, filling `role` from the staff member's first seed-named Spatie role (else `operations`), and restores the CHECK only if data satisfies it. Otherwise it throws with a clear message (documented as best-effort reversal).

`staff.branch_id` stays `SMALLINT NULL`. The FK to `branch` lands with the branch reference-data feature.

**Rationale**: Spec FR-042/FR-072. The enum is the last place roles are schema. Founder moves to a flag that no endpoint writes (FR-043).

**Alternatives considered**: Keeping `staff.role` as a "primary role" label creates two sources of truth. Deriving founder from holding the `ceo`/`coo` role would let role assignment change founder status, which FR-044 forbids.

## R4. MFA requirement

**Decision**: `LoginStaffAction::mfaStep()` requires enrollment when `$staff->is_founder || $staff->roles()->where('requires_mfa', true)->exists()`. Voluntary enrollment is still challenged (unchanged). The env list `DAHAB_AUTH_MFA_REQUIRED_ROLES` is replaced by a boolean `DAHAB_AUTH_MFA_ENFORCED` (default `true`) that exists only so local development can turn enforcement off. The config forces it to `true` in production (`env(...) || app()->isProduction()`). `founder_roles` config is removed. `StaffRole` is renamed `SeedRole` and used only by seeders, factories and tests, never for authorization decisions.

**Rationale**: Spec FR-040 and Clarification Q2 (founders always need MFA). Keeps the local-dev escape hatch the current config has, without a role list.

## R5. When permission changes take effect

**Decision**: Rely on Spatie's cache busting. `Role::syncPermissions()`, `Role::delete()` and `Staff::syncRoles()` call `forgetCachedPermissions()`. Each request resolves the staff member fresh from the token, so the next request sees the new set. No token revocation.

**Rationale**: Spec FR-014 / SC-002. Verified by a feature test that changes a role between two requests on the same token.

## R6. Escalation guard (Clarification Q1)

**Decision**: One domain service, `App\Support\Authorization\RoleEscalationGuard`, called by every role/assignment Action inside its transaction:
- `assertCanChangePermissions(actor, added, removed)`: every added and removed code ∈ actor's effective permissions.
- `assertNotOwnRole(actor, role)`: actor does not hold `role`.
- `assertCanAssign(actor, target, rolesAddedOrRemoved)`: `target ≠ actor`, and every permission of every added or removed role ∈ actor's effective permissions.

A refusal throws `DomainApiException::escalationDenied()` → `403 escalation_denied` and writes an audit row (`authz.escalation_denied`).

Direct (per-staff) permissions are not used. `model_has_permissions` stays empty, and effective permissions = union over roles (FR-022).

## R7. Last-role-manager rule and concurrency

**Decision**: Every authz-mutating Action runs in a DB transaction that first takes `pg_advisory_xact_lock(hashtext('dahab.staff_authz'))`, then applies the change, then counts active, non-system staff whose roles grant `roles.manage`. If the count is 0, it throws `409 last_role_manager`, which rolls back.

**Note**: With R6 (no self-edit), an active actor who holds `roles.manage` always keeps it, so this cannot fire through the API today. It is kept as a backstop invariant for future paths (staff deactivation in the staff-account feature, catalogue removals) and is tested by calling the Actions directly with an inactive actor fixture.

**Rationale**: Checking *after* applying inside the transaction covers all three paths (role permission edit, role delete, assignment change) with one query. The advisory lock serializes concurrent edits, so two managers cannot each remove "the other" manager at once. Role edits are rare, so a global lock costs nothing.

**Alternatives considered**: Pre-computing per path means three different predicates and races. Row locks on `roles` miss the assignment path.

## R8. Customer verified gate (FR-030–FR-033)

**Decision**: A route middleware `customer.gate:{level}` (`App\Http\Middleware\EnsureCustomerStanding`) reads the live `customer.status` on every request:
- `trade`: requires `active`. `suspended` → `403 account_suspended`; `pending_verification`/`rejected` → `403 verification_required`.
- `verified`: requires `active` or `suspended` (own-data reads for a verified customer, Part 1 §2.2). Otherwise `403 verification_required`.

The allow-list is a constant, `App\Http\CustomerRouteAccess::OPEN_ROUTES` (route names). An architecture test walks `Route::getRoutes()`: every route using `auth:customer` must either be in `OPEN_ROUTES` or carry `customer.gate:*`, or the build fails. That makes "gated by default" enforceable (SC-005). Current open routes: `customer.auth.refresh`, `customer.auth.me`, `customer.auth.logout`, `customer.auth.logout-all`, `customer.me.uploads.store`, `customer.me.identity-documents.store`. Marketplace read is unauthenticated (no `auth:customer`, so outside the rule). Wishlist behavior is out of scope (spec Clarifications 2026-09-26): whether its routes are allow-listed or gated is decided when that feature is implemented.

**Rationale**: A single middleware and a failing test beat remembering a middleware per route. The live status read satisfies FR-033.

**Alternatives considered**: A global group middleware with `withoutMiddleware()` on open routes is easy to bypass by adding routes outside the group, and the architecture test is needed anyway. Checking inside each Action scatters the rule.

## R9. System actor (FR-060–FR-062)

**Decision**: Created by a **migration** (it must exist in every environment, not only seeded local ones): `staff` row with `full_name = 'System'`, `email = 'system@dahab.internal'`, `is_system = true`, `is_active = true`, no `staff_password` row, no roles. Helpers:
- `App\Support\SystemActor::id()`: cached lookup of the single row.
- `RequestContext::forSystem()`: context with `staffId = SystemActor::id()`, IP `127.0.0.1`, user agent `system`, for jobs.

Refusals:
- `LoginStaffAction` rejects `is_system` with the generic `invalid_credentials` (no password row makes that the natural outcome; asserted explicitly).
- `Staff::scopeManageable()` (`is_system = false`) is used by the staff list, show, and assignment. A system id → `404 not_found`.

## R10. Audit events

New `AuditEvent` cases:
- `authz.role.created`
- `authz.role.updated` (display name, description)
- `authz.role.permissions_changed`
- `authz.role.mfa_changed`
- `authz.role.deleted`
- `authz.staff.roles_changed`
- `authz.escalation_denied`

Each write carries `before_json`, `after_json`, `reason`, and entity (`role`/`staff`, id). Existing `auth.staff.permission_denied` is unchanged.

## R11. Written reason (Clarification Q4)

**Decision**: FormRequest rule `reason: required_if(...)|string|min:5|max:500`. It is required when the PATCH body changes `permissions` or `requires_mfa`, on DELETE role, and on PUT staff roles. The Action re-checks and throws `422 reason_required` (Part 1 §6 code) if the FormRequest was bypassed. It is stored in `audit_log.reason`.

## R12. Branch scoping (FR-041)

**Decision**: `StaffPermission::isBranchScoped()` marks codes. `App\Support\Authorization\BranchScope::assert(Staff $staff, StaffPermission $p, int $recordBranchId)` throws `403 wrong_branch` if the permission is branch-scoped and `staff.branch_id` is null or different. No current permission is branch-scoped, so the helper is exercised by a unit-level test with a test-only enum case bound via a fake. The first real use comes with inspection. The Dashboard action to pick a staff member's branch ships with branch reference data (spec Assumptions).

## R13. Staff profile shape and Dashboard compatibility

**Decision**: `StaffProfile` keeps every existing field:
- `role` stays, **deprecated**. Its value is the staff member's first role name alphabetically, or `null`. Its type changes from a closed enum to `string|null`.
- `roles` stays `string[]` (names).
- New fields: `roles_detail: [{ name, display_name }]` and `is_founder`.

**Rationale**: Nothing the Dashboard reads today is removed or retyped as a structure. `role` widening from enum to free string (nullable) is *potentially breaking* for `STAFF_ROLE_LABELS[auth.user.role]` and is reported as Dashboard impact.

## R14. Constitution and docs

Constitution v2.0.0 (already amended in this branch) permits this design. Docs to update in the same PR:
- `docs/Technical Spec/dahab-dashboard-authorization.md` §3–§6
- Part 1 §2.2 (top-up now gated), §3.1, §3.2 (founder flag), §3.3 (no per-role DB connection), §4 intro (matrix = seed), §4.4 (no grant backstop), §5.2 (wallet visibility is a permission)
- `specs/001-auth-customer-staff/contracts/error-codes.md` → new codes listed in [contracts/error-codes.md](./contracts/error-codes.md).
