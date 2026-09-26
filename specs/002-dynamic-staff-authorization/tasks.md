# Tasks: Dynamic Staff Authorization, Customer Verified Gate, System Actor

**Input**: Design documents from `specs/002-dynamic-staff-authorization/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: Required. Constitution V demands HTTP-boundary Pest feature tests for state-changing paths, and Principle IV requires a happy path plus one refusal per endpoint.

**Organization**: Tasks are grouped by user story (spec.md US1–US5).

**Conventions for every task**:
- Run DB commands as `DB_USERNAME=dahab DB_PASSWORD=secret php artisan …`, never as the `.env` user.
- Files use LF line endings (use Edit/Write, not Python text-mode writes).
- Apply the named `.claude/skills/laravel-*` skill for each layer.
- Never reference role **names** in business code. Check permission codes or the `is_founder`/`is_system` flags.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Error codes, audit events, and config that every later phase uses.

- [X] T001 [P] Add `DomainApiException` factories in `app/Exceptions/DomainApiException.php`:
  - `escalationDenied()` → `escalation_denied`, 403
  - `lastRoleManager()` → `last_role_manager`, 409
  - `roleInUse(int $count)` → `role_in_use`, 409, message states the count
  - `reasonRequired()` → `reason_required`, 422
  - `wrongBranch()` → `wrong_branch`, 403
  - `verificationRequired()` → `verification_required`, 403

  Messages follow [contracts/error-codes.md](./contracts/error-codes.md). Confirm `bootstrap/app.php` already renders `DomainApiException` as `{message, code}`.
- [X] T002 [P] Add `AuditEvent` cases in `app/Enums/AuditEvent.php`: `ROLE_CREATED='authz.role.created'`, `ROLE_UPDATED='authz.role.updated'`, `ROLE_PERMISSIONS_CHANGED='authz.role.permissions_changed'`, `ROLE_MFA_CHANGED='authz.role.mfa_changed'`, `ROLE_DELETED='authz.role.deleted'`, `STAFF_ROLES_CHANGED='authz.staff.roles_changed'`, `ESCALATION_DENIED='authz.escalation_denied'` (data-model §5).
- [X] T003 [P] Replace the role lists in `config/dahab-auth.php`:
  - Remove `mfa_required_roles` and `founder_roles`.
  - Add `'mfa_enforced' => (bool) env('DAHAB_AUTH_MFA_ENFORCED', true) || app()->isProduction()` with a comment: local-only escape hatch, forced on in production (research R4).
  - Update `.env.example:123` to `# DAHAB_AUTH_MFA_ENFORCED=true` and `phpunit.xml:39` to `<env name="DAHAB_AUTH_MFA_ENFORCED" value="true"/>`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, models, catalogue sync, and the shared authz services. The existing suite must be green again at the end of this phase, with identical behaviour for every seeded account (SC-003).

**⚠️ CRITICAL**: No user story work begins until this phase is complete.

### Schema (skill: `laravel-migrations`)

- [X] T004 Create `database/migrations/2026_09_26_000010_extend_roles_for_dynamic_authorization.php`. On `roles`, add:
  - `display_name VARCHAR(100) NOT NULL`: add nullable, backfill `initcap(replace(name,'_',' '))`, then set NOT NULL.
  - `description TEXT NULL`.
  - `requires_mfa BOOLEAN NOT NULL DEFAULT false`: backfill `true` for `name IN ('ceo','coo','finance')`.
  - pgsql-only CHECKs: `roles_name_format CHECK (name ~ '^[a-z][a-z0-9_]{2,49}$')` and `roles_guard_staff CHECK (guard_name = 'staff')`.

  `down()` drops the constraints and the three columns.
- [X] T005 Create `database/migrations/2026_09_26_000020_staff_replace_role_with_founder_and_system_flags.php`, in this order:
  1. For each `staff` row, ensure the Spatie role named `staff.role::text` exists (guard `staff`) and a `model_has_roles` row links it (`model_type = 'App\Models\Staff'`, `model_id = staff_id`).
  2. Add `is_founder BOOLEAN NOT NULL DEFAULT false`, backfilled `true` where `role IN ('ceo','coo')`.
  3. Add `is_system BOOLEAN NOT NULL DEFAULT false`.
  4. Add `CONSTRAINT staff_system_not_founder CHECK (NOT (is_system AND is_founder))` and `CREATE UNIQUE INDEX one_system_staff ON staff ((true)) WHERE is_system`.
  5. Drop `CONSTRAINT igi_has_branch`.
  6. Drop column `role`.
  7. `DROP TYPE staff_role`.

  `down()` recreates the type, adds `role` filled from the first seed-named Spatie role (else `operations`), and restores `igi_has_branch` only if the data satisfies it. Otherwise it throws `RuntimeException('staff.role cannot be restored: IGI rows without branch_id')`. Then it drops the index, CHECK, and flags (research R3).
- [X] T006 Create `database/migrations/2026_09_26_000030_insert_system_actor.php`. Insert one `staff` row: `full_name='System'`, `email='system@dahab.internal'`, `is_system=true`, `is_active=true`, `is_founder=false`. No password and no roles. It must be idempotent (skip if an `is_system` row exists). `down()` deletes it only if no `audit_log` row references it, otherwise it throws (research R9).

### Models & enums (skill: `laravel-eloquent-models`)

- [X] T007 [P] Rename `app/Enums/StaffRole.php` → `app/Enums/SeedRole.php`:
  - Enum `SeedRole` with the same six cases.
  - Remove `isMfaRequired()` / `isFounder()`.
  - Docblock: seed/test vocabulary only, never used for authorization decisions.

  Update every import in `database/seeders/*`, `database/factories/*` and the 13 test files that reference `StaffRole` (`grep -rl StaffRole tests database app`).
- [X] T008 [P] Create `app/Models/StaffRoleModel.php` extending `Spatie\Permission\Models\Role`:
  - Fillable: `name`, `guard_name`, `display_name`, `description`, `requires_mfa`.
  - Cast `requires_mfa` to boolean.
  - An `updating` hook throws `LogicException` if `name` is dirty.
  - Scope `withHolderCount()` adds `staff_count` = count of `model_has_roles` joined to `staff` where `is_system = false`.

  Set `'role' => App\Models\StaffRoleModel::class` in `config/permission.php` `models`.
- [X] T009 Update `app/Models/Staff.php`:
  - Remove `role` from `$fillable` and casts.
  - Cast `is_founder`, `is_system` to boolean and keep them **out of** `$fillable` (FR-043).
  - Add scope `manageable()` (`where('is_system', false)`).
  - Add `requiresMfa(): bool` = `$this->is_founder || $this->roles()->where('requires_mfa', true)->exists()`.
  - Add `effectivePermissionCodes(): array` (sorted names from `getAllPermissions()`).
- [X] T010 Extend `app/Enums/StaffPermission.php`:
  - Add cases `STAFF_VIEW='staff.view'` and `ROLES_MANAGE='roles.manage'`.
  - Rename `roles()` → `seedRoles(): array` returning **role names** (strings), not counting `ceo`. `customer.view`/`identity.*` → `['verification']`, `customer.suspend` → `['coo']`, `staff.view`/`roles.manage` → `['coo']`.
  - Add `label()`, `group()`, `isBranchScoped()` (all `false` today) with the values in data-model §2.
  - Docblock on `seedRoles()` (spec FR-051): "Wallet-touching permissions added by later modules MUST NOT list `coo` here. COO has everything except wallets by default, and that stays editable from the Dashboard."
- [X] T011 Update `database/factories/StaffFactory.php`:
  - Remove `role` from `definition()`.
  - Replace `role(StaffRole)` with `withRole(string ...$names)`, which does `Role::findOrCreate($name, 'staff')` then `assignRole` in `afterCreating`. `withRole('igi_branch')` also sets `branch_id = 1`.
  - Add states `founder()` (`is_founder=true` via `forceFill`) and `system()` (`is_system=true`).
  - Keep `disabled()` and `frozen()`. `frozen()` must create its freezer with `withRole('ceo')->founder()`.

### Catalogue sync & seeders (skill: `laravel-actions-services`)

- [X] T012 Create `app/Actions/Authorization/SyncPermissionCatalogueAction.php` (research R2), idempotent, in one transaction:
  1. Create missing `permissions` rows for every `StaffPermission` case (guard `staff`). For each **newly created** permission, give it to role `ceo` and to its `seedRoles()`, if those roles exist.
  2. Delete `permissions` rows (guard `staff`) whose name is not a case.
  3. For each `SeedRole` case whose role does **not** exist: create it with `display_name`, `requires_mfa` (true for ceo/coo/finance) and its seed permissions (`ceo` = all).

  It never calls `syncPermissions` on an existing role. It calls `PermissionRegistrar::forgetCachedPermissions()` at the end.
- [X] T013 Rewrite `database/seeders/DashboardRolesAndPermissionsSeeder.php` to only call `SyncPermissionCatalogueAction`. Update `database/seeders/LocalStaffSeeder.php`:
  - Use `SeedRole`, drop the `role` attribute, and `syncRoles([$role->value])`.
  - Set `is_founder=true` for `ceo@`/`coo@` via `forceFill()->save()`.
  - Keep `branch_id=1` for `igi_branch@`.
  - Never touch the system actor.

### Shared authz services (skill: `laravel-auth-authorization`)

- [X] T014 [P] Create `app/Support/Authorization/RoleEscalationGuard.php` (research R6). Methods:
  - `assertCanChangePermissions(Staff $actor, array $added, array $removed, string $entityType, string|int $entityId)`
  - `assertNotOwnRole(Staff $actor, StaffRoleModel $role)`
  - `assertCanAssign(Staff $actor, Staff $target, array $addedRoleNames, array $removedRoleNames)`: covers `target ≠ actor` and every permission of every added or removed role held by the actor.

  On refusal it writes `AuditEvent::ESCALATION_DENIED` with `{attempt, offending_permissions}` via `RecordAuditLogAction` and throws `DomainApiException::escalationDenied()`. The audit write must survive the caller's rollback: record it **after** the transaction is rolled back, or outside it.
- [X] T015 [P] Create `app/Actions/Authorization/Concerns/LocksStaffAuthorization.php` (trait) (research R7):
  - `withAuthzLock(Closure $apply)` runs `DB::transaction`, first executes `SELECT pg_advisory_xact_lock(hashtext('dahab.staff_authz'))` (pgsql only), then calls `$apply`.
  - Then `assertRoleManagerRemains()`: counts `staff` where `is_active` and not `is_system` and holding a role granting `roles.manage`. If 0, it throws `DomainApiException::lastRoleManager()`, which rolls back.
  - Then `forgetCachedPermissions()`.
- [X] T016 [P] Create `app/Support/Authorization/ReasonRule.php`, a helper `ReasonRule::assertPresent(?string $reason)`. It throws `DomainApiException::reasonRequired()` when blank or shorter than 5 characters (FR-055, research R11).

### Login & profile adaptations (skill: `laravel-auth-authorization`, `laravel-api-endpoints`)

- [X] T017 Update `app/Actions/Auth/Staff/LoginStaffAction.php`:
  - `mfaStep()` returns `'enroll'` when not enrolled and `config('dahab-auth.mfa_enforced') && $staff->requiresMfa()`. Voluntary enrollment is still challenged.
  - Before the password check, refuse `is_system` staff with the same `AuthApiException::invalidCredentials()` path as an unknown email (FR-061).
  - Update the docblock. Also update `app/Actions/Auth/Staff/CompleteStaffSignInAction.php:39` audit payload: replace `'role' => $staff->role->value` with `'roles' => $staff->getRoleNames()->all()`.
- [X] T018 Update `app/Http/Resources/Staff/StaffResource.php` (StaffProfile, research R13; contract `StaffProfile`):
  - `role` = first role name alphabetically or `null`, OA `deprecated: true`, `type string nullable` (drop the enum list).
  - Keep `roles` as a string[].
  - Add `roles_detail` (`[{name, display_name}]` sorted by name) and `is_founder`.
- [X] T019 Fix the existing suite for the new model. Update the 13 files from T007:
  - `->role(StaffRole::X)` → `->withRole(SeedRole::X->value)`
  - `->role(StaffRole::CEO)`/`COO` → `->withRole(...)->founder()` wherever founder behaviour or MFA is asserted
  - assertions on `role` in `tests/Feature/Auth/Staff/StaffRolesAndPermissionsTest.php` and `LocalStaffSeederTest.php`

  Run `composer test`. The whole suite must pass with no behaviour change.
- [X] T020 Create `tests/Feature/Authorization/StaffAuthorizationMigrationTest.php` (SC-003, quickstart §1). After `migrate:fresh --seed` in testing, assert:
  - `staff.role` column and `staff_role` type are absent.
  - Exactly one `is_system` row.
  - `ceo@`/`coo@` have `is_founder`.
  - Each seeded account's `/dashboard/auth/me` `permissions` equal the pre-feature map plus `staff.view`/`roles.manage` for ceo/coo only.
  - `roles.requires_mfa` is true for exactly ceo/coo/finance.
  - Running `DashboardRolesAndPermissionsSeeder` a second time after removing a permission from `operations` leaves that removal intact.

### Per-request staff standing (analysis S1, spec FR-056)

- [X] T021 Create `app/Http/Middleware/EnsureStaffStanding.php` with alias `staff.standing` registered in `bootstrap/app.php`. On every request authenticated by `auth:staff`, it re-reads the staff row from the DB:
  - `is_active = false` → revoke the current token and throw `401 unauthenticated`.
  - Open `account_freeze` (`unfrozen_at IS NULL`) → `AuthApiException::accountFrozen()` (403 `account_frozen`) and audit `auth.staff.permission_denied` with `{reason: 'frozen'}`.

  Apply it in `routes/api.php` to every dashboard route using `auth:staff` **except** `dashboard.auth.logout` and `dashboard.auth.logout-all`, so a frozen account can still sign out. Refresh is refused too. It must run before `staff.permission:*`.
- [X] T022 Create `tests/Feature/Auth/Staff/StaffStandingTest.php`. A staff member with a live access token:
  - frozen after sign-in → `GET /dashboard/customers` and `PATCH /dashboard/roles/{x}` → 403 `account_frozen`, while logout → 204/200
  - deactivated after sign-in → 401 `unauthenticated` and the token is deleted
  - refresh with a frozen account → 403 `account_frozen`
  - an unfrozen account (`unfrozen_at` set) works again on the next request

**Checkpoint**: Suite green, behaviour unchanged, roles are data, the system actor exists, and frozen or deactivated staff are stopped on every request.

---

## Phase 3: User Story 1 — Manage roles and their permissions (Priority: P1) 🎯 MVP

**Goal**: Role managers list the catalogue and create, edit, delete, and view roles from the Dashboard, with escalation guard, reason, audit, and next-request effect.

**Independent Test**: quickstart §2 steps 1, 2, 5, 6 and §3 rows 1, 2, 4, 5.

### Tests for User Story 1 (skill: `laravel-pest-testing`) ⚠️ write first, watch them fail

- [X] T023 [P] [US1] `tests/Feature/Authorization/PermissionCatalogueTest.php`, covering `GET /api/v1/dashboard/permissions`:
  - 200 for `ceo` with every `StaffPermission` case, each with `code,label,group,branch_scoped`.
  - 403 `permission_denied` for `operations` (audit row `auth.staff.permission_denied`).
  - 401 without a token and with a customer token.
- [X] T024 [P] [US1] `tests/Feature/Authorization/RoleManagementTest.php`:
  - Create a role → 201 with body per contract `Role`, `staff_count=0`, audit `authz.role.created`.
  - Duplicate/badly formed `name` (`^[a-z][a-z0-9_]{2,49}$`) → 422 `validation_failed`.
  - Unknown permission code → 422 `validation_failed`.
  - `display_name` > 100 chars or `description` > 500 chars → 422.
  - Show → 200, unknown name → 404.
  - List ordered by `display_name`, with `staff_count` excluding the system actor.
  - PATCH `display_name`/`description` without reason → 200 (audit `authz.role.updated`).
  - PATCH `permissions` without reason → 422 `reason_required`.
  - PATCH `permissions` with reason → 200, audit `authz.role.permissions_changed` with `added`/`removed`.
  - PATCH `requires_mfa` without reason → 422, with reason → audit `authz.role.mfa_changed`.
  - PATCH body containing `name` → name unchanged.
  - DELETE held role → 409 `role_in_use`. DELETE unheld role without reason → 422. DELETE with reason → 204 and audit `authz.role.deleted`.
- [X] T025 [P] [US1] `tests/Feature/Authorization/RoleEscalationTest.php`:
  - `ceo` PATCHes role `ceo` → 403 `escalation_denied`.
  - `coo` adds a permission `coo` lacks (fixture: a test-only permission given to ceo only) to `operations` → 403.
  - `coo` removes that same permission from `ceo` → 403.
  - `coo` creates a role containing it → 403.
  - `coo` deletes a role it holds → 403.
  - Each refusal leaves DB unchanged and writes `authz.escalation_denied`.
- [X] T026 [P] [US1] `tests/Feature/Authorization/PermissionChangeTakesEffectTest.php` (SC-002). Give `operations` a custom role with `customer.view`. The same token gets `GET /dashboard/customers` → 200. The manager removes the permission. The same token → 403.

### Implementation for User Story 1

- [X] T027 [P] [US1] Create `app/Http/Resources/Staff/PermissionResource.php` (wraps a `StaffPermission` case: `code,label,group,branch_scoped`) and `app/Http/Resources/Staff/RoleResource.php` (`name,display_name,description,requires_mfa,permissions` sorted, `staff_count`, `created_at`, `updated_at` ISO-8601), with `#[OA\Schema]` names `DashboardPermission`, `DashboardRole`.
- [X] T028 [P] [US1] Create the FormRequests in `app/Http/Requests/Dashboard/Authorization/`:
  - `StoreRoleRequest`:
    - `name` required, string, regex `/^[a-z][a-z0-9_]{2,49}$/`, unique on `roles,name` with guard staff
    - `display_name` required, string, max 100
    - `description` nullable, string, max 500
    - `requires_mfa` boolean
    - `permissions` present, array; `permissions.*` distinct, in `StaffPermission` values
    - `reason` nullable, string, min 5, max 500
  - `UpdateRoleRequest`:
    - same field rules except `name` (prohibited), all `sometimes`, at least one of `display_name,description,requires_mfa,permissions`
    - `reason` required when `permissions` or `requires_mfa` present
  - `DeleteRoleRequest`: `reason` required, min 5, max 500.

  Add `#[OA\Schema]` on each.
- [X] T029 [US1] Create `app/Actions/Authorization/CreateRoleAction.php`, `UpdateRoleAction.php`, `DeleteRoleAction.php`, and `ListRolesAction.php`. All mutating Actions use `LocksStaffAuthorization` and `RoleEscalationGuard`:
  - Create: `assertCanChangePermissions(actor, added: $permissions, removed: [])`.
  - Update: `assertNotOwnRole`, compute added/removed against the current set, `assertCanChangePermissions`, `ReasonRule` when permissions or `requires_mfa` actually change. Then write one audit row per changed aspect (data-model §5).
  - Delete: `assertNotOwnRole`, `roleInUse` if holders > 0, `ReasonRule`, audit snapshot.
  - List: `withHolderCount()` ordered by `display_name`, limit 200.
- [X] T030 [US1] Create `app/Http/Controllers/Api/V1/Dashboard/PermissionController.php` (`index`) and `RoleController.php` (`index,store,show,update,destroy`). They are thin: resolve the role by `name` (guard staff, 404 otherwise), call one Action, return the Resource. Add `#[OA\…]` per [contracts/openapi.yaml](./contracts/openapi.yaml) with tag `Dashboard Access Control` and security `dashboardBearer`.
- [X] T031 [US1] Register routes in `routes/api.php` inside the dashboard group with `['auth:staff','abilities:staff:access','staff.permission:roles.manage']`, all under the `api.v1.dashboard.` names:
  - `GET /permissions` → `permissions.index`
  - `GET|POST /roles` → `roles.index` / `roles.store`
  - `GET|PATCH|DELETE /roles/{role}` → `roles.show` / `roles.update` / `roles.destroy`, with `{role}` constrained to `[a-z][a-z0-9_]{2,49}`

  Run T023–T026. They must pass.

**Checkpoint**: Roles are fully manageable. US1 can be demoed alone with seeded staff (assignment via seeder).

---

## Phase 4: User Story 2 — Assign roles to staff (Priority: P1)

**Goal**: Role managers list and view staff (system actor hidden) and replace a staff member's roles. The profile exposes `roles_detail`.

**Independent Test**: quickstart §2 steps 3–4 and §3 row 3. Assigning `verification` to `operations@` makes `identity.review` appear in their `/me`.

### Tests for User Story 2 ⚠️

- [X] T032 [P] [US2] `tests/Feature/Authorization/StaffListTest.php`:
  - `GET /dashboard/staff` needs `staff.view` (`verification` → 403).
  - Paginated (`per_page` 1–50, default 25), ordered by `full_name`, filter `?role=`.
  - System actor never listed. `GET /dashboard/staff/{system id}` → 404. Unknown uuid → 404.
  - Body per contract `StaffMember`, including read-only `is_founder`.
- [X] T033 [P] [US2] `tests/Feature/Authorization/StaffRoleAssignmentTest.php`:
  - `PUT /dashboard/staff/{id}/roles` with reason → 200; the target's `/me` `permissions` equal the union of roles; audit `authz.staff.roles_changed` with `added/removed`.
  - Missing reason → 422 `reason_required`.
  - Unknown role name → 422 `validation_failed`.
  - On self → 403 `escalation_denied`.
  - `coo` assigning a role containing a permission `coo` lacks → 403.
  - Empty `roles` allowed.
  - Body containing `is_founder: true` → founder unchanged (SC-008).
  - Target's same token sees the change on the next request.
- [X] T034 [P] [US2] `tests/Feature/Authorization/LastRoleManagerInvariantTest.php` (research R7 note). Call `SetStaffRolesAction`/`UpdateRoleAction`/`DeleteRoleAction` directly with an **inactive** actor fixture, where the only active manager would lose `roles.manage`. Expect `409 last_role_manager` and no DB change. Also cover concurrent-safety: the advisory lock is taken (assert via `pg_locks` inside the closure, pgsql only).
- [X] T035 [P] [US2] `tests/Feature/Auth/Staff/StaffProfileShapeTest.php`: `/dashboard/auth/me` and the sign-in `staff` object contain `role` (deprecated, first role alphabetically or null), `roles` string[], `roles_detail`, `is_founder`.

### Implementation for User Story 2

- [X] T036 [P] [US2] Create `app/Http/Resources/Staff/StaffMemberResource.php` (contract `StaffMember`: `id, full_name, email, phone, is_active, is_founder, branch_id, roles[{name,display_name}], permissions[], mfa_enrolled`), `#[OA\Schema(schema: 'DashboardStaffMember')]`. Eager-load `roles.permissions` to avoid N+1.
- [X] T037 [P] [US2] Create FormRequests in `app/Http/Requests/Dashboard/Authorization/`:
  - `ListStaffRequest`: `role` nullable, string, exists `roles,name`; `per_page` integer 1–50.
  - `SetStaffRolesRequest`: `roles` present, array; `roles.*` distinct, string, exists `roles,name` (guard staff); `reason` required, string, min 5, max 500.

  Unknown keys such as `is_founder` are ignored because only validated data is used.
- [X] T038 [US2] Create `app/Actions/Authorization/ListStaffAction.php` (`Staff::manageable()`, optional role filter, paginate) and `app/Actions/Authorization/SetStaffRolesAction.php`. The latter:
  - Resolves the target via `manageable()` (404 otherwise), runs `withAuthzLock`, computes added/removed, calls `assertCanAssign`, then `ReasonRule`, then `syncRoles`.
  - Writes audit `STAFF_ROLES_CHANGED`.
  - Never touches `is_founder`.
- [X] T039 [US2] Create `app/Http/Controllers/Api/V1/Dashboard/StaffController.php` (`index`, `show`, `updateRoles`) with `#[OA\…]` per contract. Register routes in `routes/api.php`:
  - `GET /staff` and `GET /staff/{staff}` (uuid constraint) with `staff.permission:staff.view` → `staff.index` / `staff.show`
  - `PUT /staff/{staff}/roles` with `staff.permission:roles.manage` → `staff.roles.update`

  Run T032–T035. They must pass.

**Checkpoint**: US1 + US2 = the full "manage access from the Dashboard" MVP.

---

## Phase 5: User Story 3 — Customer verified gate (Priority: P1)

**Goal**: Unverified customers can sign in and use only the allow-list. Every other customer route is gated by default.

**Independent Test**: quickstart §4. The architecture test fails when a new customer route is ungated.

### Tests for User Story 3 ⚠️

- [X] T040 [P] [US3] `tests/Feature/Customer/CustomerRouteGateTest.php` (architecture). Iterate `Route::getRoutes()`: every route whose middleware includes `auth:customer` must be named in `App\Http\CustomerRouteAccess::OPEN_ROUTES` **or** carry `customer.gate:verified` / `customer.gate:trade`. A second case registers a throwaway ungated `auth:customer` route in the test and asserts the checker reports it.
- [X] T041 [P] [US3] `tests/Feature/Customer/CustomerGateTest.php`. Using test-only routes registered in the test (`customer.gate:verified` and `customer.gate:trade`), cover the data-model §6 matrix for `active`, `suspended`, `pending_verification`, `rejected`:
  - `verification_required` / `account_suspended` codes.
  - A status change between two requests on the same token takes effect (FR-033).
  - An unverified customer can `POST /customer/auth/login`, `GET /customer/auth/me`, `POST /customer/me/uploads`, `POST /customer/me/identity-documents`.

### Implementation for User Story 3

- [X] T042 [P] [US3] Create `app/Http/CustomerRouteAccess.php` with `public const OPEN_ROUTES = ['api.v1.customer.auth.refresh','api.v1.customer.auth.me','api.v1.customer.auth.logout','api.v1.customer.auth.logout-all','api.v1.customer.me.uploads.store','api.v1.customer.me.identity-documents.store']`. The docblock states the FR-030 rule, that the marketplace read is unauthenticated, and that wishlist behavior is out of scope until the wishlist feature is implemented.
- [X] T043 [US3] Create `app/Http/Middleware/EnsureCustomerStanding.php` with parameter `verified|trade`:
  - Re-reads `customer.status` from the DB (not the token's cached model: `Customer::query()->whereKey(...)->value('status')`).
  - Applies the data-model §6 matrix. `account_suspended` uses the existing `AuthApiException::accountSuspended()`, `verification_required` uses `DomainApiException::verificationRequired()`.

  Register the alias `customer.gate` in `bootstrap/app.php`.
  - *Found during implementation*: `app/Actions/Auth/Customer/AssertCustomerCanSignIn.php` refused sign-in for `pending_verification`/`rejected`/`suspended`, which contradicts US3 AS1 and FR-032. It now lets all four statuses sign in (the gate refuses everything else). `NewDeviceOtpTest` was updated, and a sign-in test per status was added to `CustomerGateTest`. **Flutter impact**: `lib/services/auth/auth_messages.dart` no longer receives these codes at login. Verify the route names in T042 with `php artisan route:list --path=api/v1/customer`, then run T040–T041.

**Checkpoint**: Every future trade endpoint is gated unless deliberately allow-listed.

---

## Phase 6: User Story 4 — MFA and branch follow the staff member (Priority: P2)

**Goal**: MFA from the founder flag OR any role's `requires_mfa`, and the branch-scoped permission rule.

**Independent Test**: quickstart §5.

### Tests for User Story 4 ⚠️

- [X] T044 [P] [US4] `tests/Feature/Authorization/StaffMfaRequirementTest.php`:
  - A staff member with a new role `requires_mfa=true` gets `mfa_enrollment_required` at login.
  - After the manager sets it false (with reason), the next login completes without MFA.
  - A founder whose roles all have `requires_mfa=false` still gets `mfa_enrollment_required` (Clarification Q2).
  - A voluntarily enrolled non-founder is still challenged.
  - With `dahab-auth.mfa_enforced=false`, nobody is forced to enroll.
- [X] T045 [P] [US4] `tests/Unit/Authorization/BranchScopeTest.php`. `BranchScope::assert` with a branch-scoped permission: staff branch equal → passes; different → `wrong_branch`; null branch → `wrong_branch`; non-branch-scoped permission → passes regardless. Use a fake scoped check via a `BranchScope::for(bool $scoped, …)` seam, since no real code is branch-scoped yet (research R12).

### Implementation for User Story 4

- [X] T046 [US4] Create `app/Support/Authorization/BranchScope.php` with `assert(Staff $staff, StaffPermission $permission, int $recordBranchId): void`, throwing `DomainApiException::wrongBranch()` per FR-041 and research R12. Plus the testing seam used by T045.
- [X] T047 [US4] Confirm `LoginStaffAction` (T017) satisfies T044. Add any missing branch in `mfaStep()` and update `app/Actions/Auth/Staff/EnrollStaffMfaAction.php` / `VerifyStaffMfaAction.php` if they reference the removed role checks. Run T044–T045.

**Checkpoint**: New roles can require MFA. Founders can never lose MFA through role edits.

---

## Phase 7: User Story 5 — System actor for scheduled work (Priority: P2)

**Goal**: One non-login system actor that jobs attribute their writes to.

**Independent Test**: quickstart §3 last two rows, plus an audit row written "as system".

### Tests for User Story 5 ⚠️

- [X] T048 [P] [US5] `tests/Feature/Authorization/SystemActorTest.php`:
  - Exactly one `is_system` row after `migrate:fresh`. Inserting a second one fails (unique index). `is_system AND is_founder` fails (CHECK).
  - `POST /dashboard/auth/login` with `system@dahab.internal` and any password → 401 `invalid_credentials`.
  - Not in `GET /dashboard/staff`. `PUT /dashboard/staff/{system id}/roles` → 404.
  - `RecordAuditLogAction::execute(..., ctx: RequestContext::forSystem())` writes a row with `actor_staff_id = SystemActor::id()`.

### Implementation for User Story 5

- [X] T049 [P] [US5] Create `app/Support/SystemActor.php`: `id(): string`, a memoized lookup of `staff.staff_id WHERE is_system`. It throws `RuntimeException` if missing.
- [X] T050 [US5] Add `RequestContext::forSystem(): self` in `app/Support/RequestContext.php` (`staffId = SystemActor::id()`, `ip = '127.0.0.1'`, `userAgent = 'system'`, no device). Document in the docblock that scheduled jobs must bind it before calling Actions. Run T048.

**Checkpoint**: The seller-reply sweep (buy-request module) can attribute its writes.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T051 [P] Update docs (Constitution III):
  - `docs/Technical Spec/dahab-dashboard-authorization.md` §3–§6: roles are data, catalogue in code, seed-only mapping, escalation rule, founder/system flags, no DB grants.
  - `docs/Technical Spec/dahab-spec-part1-auth.md`:
    - §2.2: top-up now needs verification; allow-list.
    - §3.1: roles dynamic.
    - §3.2: founder is a flag, not API-writable, always MFA.
    - §3.3: no per-role DB connection.
    - §4 intro: matrix = initial seed.
    - §4.4: no grant backstop.
    - §5.2: wallet visibility = permissions.
  - **SQL schema contract (analysis C1)**, PostgreSQL files only, not `00_schema_mysql.sql`. They must match migrations T004–T006:
    - `docs/Database schema/01_schema_core.sql`: remove `staff_role` enum.
    - `docs/Database schema/02_schema_identity.sql`: `staff` table drops `role` and `igi_has_branch`, adds `is_founder`, `is_system`, `staff_system_not_founder`, `one_system_staff`; `roles` extra columns `display_name`, `description`, `requires_mfa`.
    - `docs/Database schema/05_schema_security.sql`: remove per-staff-role DB roles and grants. Keep customer RLS policies.
    - `docs/Database schema/00_schema_full.sql`: the same changes.
  - `docs/Technical Spec/dahab-spec-part2-api.md`: every `staff_role` reference becomes "role (dynamic, Dashboard-managed)"; add the `/dashboard/permissions|roles|staff` endpoints to the Dashboard section.

  Each change carries a note "Changed by spec 002 (product-owner decision 2026-09-26)". Constitution III allows docs in the same PR. Do this task **before T005 is merged**; it can start right after T004.
- [X] T052 [P] Update `specs/001-auth-customer-staff/contracts/error-codes.md`: add a pointer row to `specs/002-dynamic-staff-authorization/contracts/error-codes.md` and change `mfa_enrollment_required`'s "When" text to "founder, or a role with requires_mfa, has no `staff_mfa` row yet".
- [X] T053 [P] Update `postman/Dahab-Backend.postman_collection.json` per `postman/README.md`:
  - New folder **Dashboard → Access Control** with the 9 requests. Bodies match the FormRequests, with `reason` included where required.
  - Collection variables `role_name` and `staff_id`, saved by test scripts from `POST /roles` and `GET /staff`.
  - Update the Dashboard folder description: MFA = founders + roles flagged `requires_mfa`.
  - Update the environment file if new variables are needed.
- [X] T054 Run `composer swagger:generate` and fix warnings. Confirm `tests/Feature/Auth/Shared/OpenApiGenerationTest.php` passes and the generated doc has the 9 operations plus `StaffProfile.role` deprecated.
- [X] T055 Run the `laravel-quality-gates` skill:
  - `./vendor/bin/pint`
  - `composer test`
  - `DB_USERNAME=dahab DB_PASSWORD=secret php artisan migrate:fresh --seed`
  - `… migrate:rollback --step=3 && … migrate`
  - `php artisan route:list --path=api/v1`
  - an N+1 check on `GET /dashboard/roles` and `GET /dashboard/staff`
  - a security review of the escalation paths

  Then run the quickstart §1–§5 checks.
- [X] T056 Write the Dashboard impact report (plan.md "Dashboard Impact" table, verified against `../dahab-dashboard/src` at that time) into the PR description. Do not edit the Dashboard.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (T001–T003)**: no dependencies. All [P].
- **Foundational (T004–T022)**: after Setup. **Blocks all stories.** Inner order:
  1. T004 → T005 → T006 (migrations in sequence)
  2. T007, T008, T010 in parallel, then T009 and T011
  3. T012 → T013
  4. T014, T015, T016 in parallel (after T008–T009)
  5. T017, T018
  6. T019 → T020
  7. T021 → T022 (staff standing; after T009)
- **US1 (T023–T031)**: after Foundational.
- **US2 (T032–T039)**: after Foundational. Independent of US1's code, but its tests create roles through the seeder or factory, not the US1 API.
- **US3 (T040–T043)**: after Foundational only (it doesn't touch staff code). Can run fully in parallel with US1/US2.
- **US4 (T044–T047)**: after Foundational. T044's "manager turns the flag off" step uses the US1 endpoint, so it runs after T031 (or it sets the flag directly and asserts the audit separately).
- **US5 (T048–T050)**: after Foundational (T006). T048's staff-list and assignment assertions need US2's T039.
- **Polish (T051–T056)**: after the desired stories. T051–T053 [P].

### Story completion order

Foundational → **US1 ∥ US3** → US2 → US4 ∥ US5 → Polish

### Within each story

Tests first (they must fail), then Resources/FormRequests [P], then Actions, then Controller and routes, then run the story's tests.

## Parallel Examples

```text
# Setup
T001, T002, T003 together

# Foundational, after migrations
T007 SeedRole rename  |  T008 StaffRoleModel  |  T010 StaffPermission catalogue
T014 RoleEscalationGuard  |  T015 LocksStaffAuthorization  |  T016 ReasonRule

# US1 tests
T023 PermissionCatalogueTest | T024 RoleManagementTest | T025 RoleEscalationTest | T026 PermissionChangeTakesEffectTest
# US1 impl
T027 Resources | T028 FormRequests  → then T029 Actions → T030 Controllers → T031 Routes

# US3 alongside US1 (different files entirely)
T040 CustomerRouteGateTest | T041 CustomerGateTest | T042 CustomerRouteAccess → T043 middleware
```

## Implementation Strategy

### MVP first

1. Phases 1–2: roles become data, and behaviour is unchanged (proven by T019 + T020). This is safe to merge alone.
2. Phase 3 (US1) + Phase 4 (US2): the Dashboard can manage access. **This is the MVP the product owner asked for.**
3. Phase 5 (US3): the customer gate. It must land before the listings/buy-request modules.

### Incremental delivery

- US4 and US5 are P2. They are needed before inspection (branch scope) and before the first deadline sweep (system actor), but not for the MVP demo.
- Polish (docs, Postman, OpenAPI, quality gates) is mandatory before the PR, per the Constitution and CLAUDE.md.

### Next feature after this one

Customer RLS activation (spec FR-054, Clarification Q3), then reference data, settings and manual gold price, then the ledger core.
