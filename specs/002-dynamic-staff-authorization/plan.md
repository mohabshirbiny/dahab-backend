# Implementation Plan: Dynamic Staff Authorization, Customer Verified Gate, System Actor

**Branch**: `claude/friendly-bell-1365c8` (spec dir `002-dynamic-staff-authorization`) | **Date**: 2026-09-26 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/002-dynamic-staff-authorization/spec.md`

## Summary

Turn staff authorization from fixed roles into Dashboard-managed data: roles (with display name, description, and "requires MFA"), role→permission mapping, and staff→role assignment. The permission **catalogue** stays in code (`StaffPermission` enum), one code per protected endpoint.

The approach extends the existing Spatie `laravel-permission` setup (guard `staff`) instead of replacing it:
- Three columns on `roles`.
- The fixed `staff.role` enum column is dropped, and `is_founder` / `is_system` flags are added to `staff`.
- The every-deploy `syncPermissions` seeder is replaced with an additive catalogue sync, so Dashboard edits survive deploys.

The same feature adds:
- A default-deny customer gate (`customer.gate:verified|trade`) backed by an architecture test.
- One migration-seeded, non-login **system actor** for scheduled jobs.
- Safeguards:
  - No self-escalation (`escalation_denied`).
  - The last-role-manager backstop (`last_role_manager`).
  - `role_in_use`.
  - A required reason.
  - An audit row on every change.

## Technical Context

**Language/Version**: PHP 8.3+ (local 8.4), Laravel 12

**Primary Dependencies**: Laravel Sanctum (guards `customer`/`staff`), spatie/laravel-permission (guard `staff`, UUID morph key), darkaonline/l5-swagger, pragmarx/google2fa (existing MFA)

**Storage**: PostgreSQL 16 (advisory locks, partial unique index, CHECK constraints). Redis for the permission cache.

**Testing**: Pest 3 feature tests through the HTTP boundary against PostgreSQL. One architecture test over the route table.

**Target Platform**: Linux container (Docker). Local Laragon on Windows.

**Project Type**: Web service (JSON API) consumed by `../dahab-dashboard` (Vue) and the Flutter app

**Performance Goals**: Permission check adds no extra query per request beyond Spatie's cached load. Role-management endpoints run at < 300 ms p95 (tiny tables).

**Constraints**:
- A permission change applies on the holder's next request (cache bust, no re-login).
- Seeding must never overwrite Dashboard edits.
- No staff authorization via DB grants (Constitution v2 II).

**Scale/Scope**: ≤ 200 roles, ≤ 500 staff, ~6 permission codes now (dozens later). 9 new endpoints, 1 new middleware, 3 migrations.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

Constitution **v2.0.0** (amended in this branch, 2026-09-26, from the product-owner decisions in spec.md).

| Principle | Check | Status |
|---|---|---|
| I. Named actor on every state change | Every role/assignment change is attributed to the acting staff member in `audit_log`. The new system actor gives scheduled jobs a real `staff_id`, so `audit_has_actor`/`ledger_txn_has_actor` keep holding. | ✅ Pass (strengthened) |
| II. Customer isolation by engine; staff authz by permission data | Staff authz is only app-level from permission tables. No DB grants are added. Nothing weakens customer RLS: helpers are untouched and switching RLS on is the next feature (FR-054). `SetDatabaseActor` keeps publishing the actor. | ✅ Pass |
| III. Docs are the source of truth | Product-owner decisions are recorded in spec.md. `docs/Technical Spec/dahab-dashboard-authorization.md` and Part 1 (§2.2, §3.1–3.3, §4 intro, §4.4, §5.2) are updated **in the same PR** (research R14). `dahabctoblueprint.md` is not used. | ✅ Pass (docs update is a tracked task) |
| IV. Foundation before modules | This *is* foundation: no business module. Each endpoint gets migration, FormRequest/Resource, Action, Pest happy + refusal test, and OpenAPI. | ✅ Pass |
| V. Test the boundary | Feature tests through HTTP assert persisted `roles`/pivots/`audit_log` rows and envelopes. The gate is proven by a route-table architecture test plus HTTP tests. No ledger is touched. | ✅ Pass |
| Stack constraints | No new packages. PostgreSQL-only statements guarded by driver check. | ✅ Pass |
| Reversible migrations | All three migrations have `down()`. The `staff.role` restore is best-effort and throws clearly when data can't satisfy the old CHECK (flagged in PR). | ⚠️ Pass with PR note |

**Post-design re-check (after Phase 1)**: unchanged. All pass. There is no Complexity Tracking entry. The only note is the best-effort `down()` above.

## Project Structure

### Documentation (this feature)

```text
specs/002-dynamic-staff-authorization/
├── plan.md              # This file
├── research.md          # Phase 0 — decisions R1–R14
├── data-model.md        # Phase 1 — roles/staff changes, catalogue, audit events, gate matrix
├── quickstart.md        # Phase 1 — validation guide
├── contracts/
│   ├── openapi.yaml     # Phase 1 — 9 dashboard operations + StaffProfile change
│   └── error-codes.md   # Phase 1 — 7 codes (5 new, 2 new triggers)
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Actions/Authorization/                # new
│   ├── SyncPermissionCatalogueAction.php
│   ├── CreateRoleAction.php
│   ├── UpdateRoleAction.php
│   ├── DeleteRoleAction.php
│   ├── SetStaffRolesAction.php
│   ├── ListRolesAction.php / ListStaffAction.php
│   └── Concerns/LocksStaffAuthorization.php   # advisory lock + last-manager check
├── Actions/Auth/Staff/LoginStaffAction.php     # MFA via is_founder / role flag; refuse is_system
├── Enums/
│   ├── StaffPermission.php               # + label/group/isBranchScoped/seedRoles; + staff.view, roles.manage
│   ├── SeedRole.php                      # renamed from StaffRole; seed/test vocabulary only
│   └── AuditEvent.php                    # + authz.* events
├── Exceptions/DomainApiException.php     # + escalationDenied, lastRoleManager, roleInUse, reasonRequired, wrongBranch, verificationRequired
├── Http/
│   ├── CustomerRouteAccess.php           # OPEN_ROUTES allow-list
│   ├── Controllers/Api/V1/Dashboard/{PermissionController,RoleController,StaffController}.php
│   ├── Middleware/EnsureCustomerStanding.php   # alias customer.gate
│   ├── Middleware/EnsureStaffStanding.php      # alias staff.standing (freeze/inactive per request, FR-056)
│   ├── Requests/Dashboard/Authorization/{StoreRole,UpdateRole,DeleteRole,SetStaffRoles,ListStaff}Request.php
│   └── Resources/Staff/{PermissionResource,RoleResource,StaffMemberResource}.php, StaffResource.php (profile change)
├── Models/{Staff.php, StaffRoleModel.php}
└── Support/
    ├── SystemActor.php
    ├── RequestContext.php                # + forSystem()
    └── Authorization/{RoleEscalationGuard.php, BranchScope.php}
config/{permission.php (models.role), dahab-auth.php (mfa_enforced; drop role lists)}
database/
├── migrations/
│   ├── 2026_09_26_000010_extend_roles_for_dynamic_authorization.php
│   ├── 2026_09_26_000020_staff_replace_role_with_founder_and_system_flags.php
│   └── 2026_09_26_000030_insert_system_actor.php
├── factories/StaffFactory.php            # withRole(), founder(), system()
└── seeders/{DashboardRolesAndPermissionsSeeder.php, LocalStaffSeeder.php}
routes/api.php                            # dashboard permissions/roles/staff; customer.gate on customer routes
tests/Feature/Authorization/              # roles, assignment, escalation, last-manager, migration, MFA, system actor
tests/Feature/Customer/CustomerRouteGateTest.php + CustomerGateTest.php
docs/Technical Spec/{dahab-dashboard-authorization.md, dahab-spec-part1-auth.md}
postman/Dahab-Backend.postman_collection.json   # new "Dashboard → Access Control" folder
.env.example / phpunit.xml                # DAHAB_AUTH_MFA_ENFORCED replaces DAHAB_AUTH_MFA_REQUIRED_ROLES
```

**Structure Decision**: Single Laravel project, following the existing layering (thin controller → one Action → Resource; permission via `staff.permission:<code>` route middleware). The new domain folder `Actions/Authorization` sits next to `Actions/Auth` and `Actions/Identity`.

## Dashboard Impact (for `../dahab-dashboard`, not edited here)

| Dashboard file | Change | Class |
|---|---|---|
| `src/types/api.ts:42-46` (`ApiStaffRole` union, `role: ApiStaffRole`) | `role` becomes `string \| null`, deprecated. New role names can appear | Potentially breaking |
| `src/components/app/AppSidebar.vue:57`, `src/types/staff.ts` (`STAFF_ROLE_LABELS[auth.user.role]`) | Label lookup misses for new roles. Use `roles_detail[].display_name` | Potentially breaking |
| `src/layouts/DashboardLayout.vue:6` (`auth.user?.role`) | Shows the deprecated value. Switch to `roles_detail` | Potentially breaking |
| `src/services/staff-auth.service.ts:30,45` (comment "roles ceo, coo and finance always go through MFA") | MFA now follows founder flag + role flag | Non-breaking (comment/behaviour note) |
| `src/router/index.ts:31` (`staff` route → Placeholder "Staff and permissions") | New endpoints available: permissions, roles CRUD, staff list/show, set roles. New permission strings `roles.manage`, `staff.view` for `src/types/staff.ts` `PERMISSIONS` | Non-breaking (new) |

The Dashboard still lacks nothing it needs for role management. Picking a staff member's branch needs the branch reference-data feature.
