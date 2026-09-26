# Dashboard authentication & authorization (Spatie)

**Scope:** Dashboard/Staff principals only. Customers do not use roles or permissions.
**Relationship to other docs:** [`dahab-spec-part1-auth.md`](./dahab-spec-part1-auth.md) §4 is the **initial seed** of *who may do what*. Since spec 002 (product-owner decision 2026-09-26) roles, the permissions each role holds, and which staff hold which role are **data managed from the Dashboard**. This document defines how that is enforced in the Laravel application with `spatie/laravel-permission`. Design detail: [`specs/002-dynamic-staff-authorization/`](../../specs/002-dynamic-staff-authorization/).

---

## 1. Customer vs Dashboard boundary

The two principals are separate models, separate guards, and separate authorization mechanisms. Nothing is shared except the token table and the audit log.

| | Customer | Dashboard / Staff |
|---|---|---|
| Model | `App\Models\Customer` (`customer` table) | `App\Models\Staff` (`staff` table) |
| Route prefix | `/api/v1/customer/*` (auth endpoints: `/api/v1/customer/auth/*`) | `/api/v1/dashboard/*` |
| Guard | `customer` guard (Sanctum driver, provider `customers`) — `auth:customer` | `staff` guard (Sanctum driver, provider `staff`) — `auth:staff` |
| Token abilities | `customer:access`, `customer:refresh` | `staff:access`, `staff:refresh` |
| Authorization | Ownership, enforced by Postgres RLS (Part 1 §5.1) | Spatie roles/permissions (this document) |
| Spatie `HasRoles` | **No — never** | Yes, guard `staff` |

Guarantees:

- **A customer token is not a dashboard credential, and a staff token is not a customer credential.** `auth:staff` resolves a bearer token only if the token's owner is a `Staff` row (`config/auth.php`, guard `staff` → provider `staff`); `auth:customer` only if the owner is a `Customer` row. A customer token on any `/dashboard/*` route, or a staff token on any `/customer/*` route, returns `401 unauthenticated`, before any ability or permission check runs. No route uses `auth:sanctum`.
- **Access and refresh tokens are not interchangeable.** Access endpoints require the `*:access` ability (`abilities:staff:access` on the dashboard) and the refresh endpoint requires `*:refresh`; a right-principal token with the wrong ability is `403 forbidden`. Abilities only separate token *kinds* — they never carry permissions. Dashboard permissions stay Spatie's.
- **A customer can never hold a dashboard permission.** `Customer` does not use `HasRoles`, so it has no role or permission rows. A Gate check for a dashboard permission on a customer is `false` (Spatie's Gate hook only acts on models that have `checkPermissionTo`).
- **Spatie rows are guard-scoped.** Every role and permission has `guard_name = 'staff'`.
- Adding Spatie did not change any customer endpoint, model, or table.

Note: the customer surface is `/api/v1/customer/*` (spec FR-X-002, amended by the 2026-09-19 architecture audit). The earlier generic `/api/v1/auth/*` customer routes and `/api/v1/user` were removed.

---

## 2. Dashboard authentication

- Guard: `staff` in `config/auth.php` — `driver: sanctum`, `provider: staff`.
- Tokens: issued by `IssueTokenFamilyAction::forStaff()` (access + refresh pair, shared `family_id`, `actor_kind = staff`). The access token carries only `staff:access`, the refresh token only `staff:refresh`.
- Every dashboard route sits behind `auth:staff` + `abilities:staff:access` (the refresh route behind `abilities:staff:refresh`), then `staff.permission:<code>` where an action needs a permission.
- `GET /api/v1/dashboard/auth/me` — returns the authenticated staff member with their `roles` and effective `permissions` (direct + via role), read from Spatie (spec FR-S-007).
- `POST /api/v1/dashboard/auth/refresh` — exchanges a staff refresh token for a new pair; rotates on every use and revokes the family on replay (limiter `auth.refresh`).
- `POST /api/v1/dashboard/auth/login` — email + password (limiter `auth.staff.login`: 5 failures / 30 min per email → `429 account_locked`, 20 / min per IP → `429 too_many_requests`). Unknown email, wrong password and a disabled account (`is_active = false`) are one indistinguishable `401 invalid_credentials`; a frozen account (open `account_freeze`) with the correct password is `403 account_frozen`. Every success and failure is audited (`auth.staff.sign_in` / `auth.staff.sign_in_failed`).
- **MFA (TOTP, `pragmarx/google2fa`)** — founders (`staff.is_founder`) and anyone holding a role flagged `requires_mfa` never receive a token from `login` (spec 002 FR-040; the seed flags `ceo`, `coo`, `finance`). Founders need MFA whatever their roles say. `dahab-auth.mfa_enforced` (`DAHAB_AUTH_MFA_ENFORCED`) can switch the requirement off for local development only; it is forced on in production. With no TOTP enrolled, `login` answers `mfa_enrollment_required` (`otpauth_url`, one-time `recovery_codes`, `session_ref`); `POST /dashboard/auth/mfa/enroll` (`session_ref` + code) persists the encrypted secret and hashed recovery codes and issues the session. With a TOTP enrolled, `login` answers `mfa_required` (`session_ref`) and `POST /dashboard/auth/mfa/verify` (`session_ref` + `code`, or a single-use `recovery_code`) issues the session. `session_ref` is single-use, lives `dahab-auth.mfa.session_ttl_seconds`, and an accepted TOTP time-step is never accepted twice. Both endpoints share limiter `auth.staff.mfa` (5 / 5 min per `session_ref`, 20 / min per IP). Any other role that enrolled voluntarily is challenged too. `staff_password.force_reenroll_mfa_at` newer than `staff_mfa.enrolled_at` forces re-enrollment.
- `POST /api/v1/dashboard/auth/logout` / `logout-all` — revoke the current token family / every token of the staff member.
- **Not yet built** (spec 001, user story 2/4): staff password reset. Local development can also issue a staff token from tinker (see §7).

- **Standing on every request** (`staff.standing`, spec 002 FR-056): a staff member frozen after sign-in gets `403 account_frozen` on their next request (audited); a deactivated one gets `401 unauthenticated` and the token is revoked. Sign-out routes do not carry it, so a frozen account can still sign out.

Errors: `401 unauthenticated` (no/invalid/expired token, a customer token, or a deactivated account), `403 forbidden` (a staff token with the wrong ability, e.g. a refresh token on `/me`), `403 account_frozen`, `403 permission_denied` (authenticated staff lacks the permission), `401 refresh_invalid` (replayed refresh token), `429 too_many_requests`.

---

## 3. Staff roles

> Changed by spec 002 (product-owner decision 2026-09-26): roles are **data**, not schema.

- Roles live only in Spatie's `roles` table (guard `staff`), extended with `display_name`, `description` and `requires_mfa`. Model: `App\Models\StaffRoleModel` (`config('permission.models.role')`). The machine `name` is immutable after creation.
- Role managers (permission `roles.manage`) create, edit and delete roles and set which staff hold them from the Dashboard — `/api/v1/dashboard/roles*` and `PUT /api/v1/dashboard/staff/{staff}/roles`. A role still held by staff cannot be deleted (`409 role_in_use`).
- The six roles `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch` are only the **initial seed** (`App\Enums\SeedRole`, used by seeders, factories and tests — never for an authorization decision).
- The former `staff.role` enum column, the `staff_role` type and the `igi_has_branch` CHECK are gone. A staff member holds zero or more roles; effective permissions are the union over those roles (direct per-staff permissions are not used).
- **Founder** is a staff flag (`staff.is_founder`), not a role. It drives founder MFA, founder device approval and freezing. **No endpoint can change it** — only the seeder or a controlled manual database operation (spec 002 FR-043).
- **Branch** is an optional attribute of any staff member (`staff.branch_id`). Permissions marked branch-scoped only authorize records of that branch (`App\Support\Authorization\BranchScope`, `403 wrong_branch`).
- **System actor**: one `staff` row with `is_system = true`, created by migration, used by scheduled jobs (`App\Support\SystemActor`, `RequestContext::forSystem()`). It cannot sign in, holds no roles and never appears in staff management.

**Safeguards** (spec 002):

| Rule | Code |
|---|---|
| A role manager only adds/removes permissions they hold, never edits a role they hold, never changes their own roles, and only assigns/removes roles whose every permission they hold | `403 escalation_denied` (audited `authz.escalation_denied`) |
| At least one active, non-system staff member keeps `roles.manage` (backstop; unreachable through the API today) | `409 last_role_manager` |
| A written reason for permission changes, MFA-flag changes, role deletion and role assignment | `422 reason_required` |
| Every change is audited (`authz.role.*`, `authz.staff.roles_changed`) and serialized by an advisory lock | — |
| A change applies on each holder's next request (Spatie cache bust, no re-login) | — |

---

## 4. Staff permissions

The permission **catalogue** is code: `App\Enums\StaffPermission`, one code per protected endpoint, each with a label, a group and a branch-scoped flag. The Dashboard lists it (`GET /api/v1/dashboard/permissions`) and assigns codes to roles; it cannot create or delete codes, because a code nothing checks protects nothing.

| Permission | Meaning | Seed roles | Source |
|---|---|---|---|
| `customer.view` | List customers and open verification details | `ceo`, `verification` | Part 2 §10 |
| `customer.suspend` | Suspend or reinstate a customer account | `ceo`, `coo` | Part 1 §4.3 (corrected reading: both founders); spec FR-S-011 |
| `identity.view` | List identity documents and open a document's image (each image open writes `document_view_log`) | `ceo`, `verification` | Part 1 §4 matrix "Approve an ID or passport", §5.4 |
| `identity.review` | Approve or reject an identity document | `ceo`, `verification` | Part 1 §4 matrix "Approve an ID or passport"; Part 2 §10 |
| `staff.view` | List staff members and their roles | `ceo`, `coo` | spec 002 FR-020 |
| `roles.manage` | Manage roles, their permissions and staff role assignment | `ceo`, `coo` | spec 002 FR-003; Part 1 §4.3 "Change a staff member's permissions" (both founders) |

"Seed roles" is only the starting state. `ceo` is seeded with every code, and every code added in a later release is given to `ceo` (and its other seed roles) when it first appears. Wallet visibility (Part 1 §3.2, §5.2) will be ordinary permissions not seeded to `coo`; there are **no Postgres grants per staff role** (Constitution v2.0.0, Principle II).

---

## 5. Spatie usage

**Package:** `spatie/laravel-permission` (^8.3). Standard config (`config/permission.php`) and standard migration, with one documented customisation: `model_has_roles.model_id` and `model_has_permissions.model_id` are `uuid` because `staff.staff_id` is a UUID.

**Tables** (package-owned; do not hand-create replacements): `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. The custom `staff_permission` table from the first iteration of spec 001 is removed (migration `drop_staff_permission`).

**Model:** `Staff` uses `HasRoles` and declares `Staff::GUARD = 'staff'` explicitly.

**How to check a permission** — always a permission, never a role name:

```php
$staff->can('customer.suspend');          // Gate → Spatie; false (not an exception) if unknown
$staff->hasPermissionTo('customer.suspend');
Route::post(...)->middleware(['auth:staff', 'abilities:staff:access', 'staff.permission:customer.suspend']);
```

**How to manage:** `assignRole()`, `removeRole()`, `syncRoles()`, `givePermissionTo()`, `revokePermissionTo()`, `syncPermissions()`, `hasRole()`, `hasPermissionTo()`, `can()`.

**Middleware:** `staff.permission:<code>` (`App\Http\Middleware\EnforceStaffPermission`). The decision is Spatie's; the middleware only converts a denial into `403 permission_denied` and writes an `auth.staff.permission_denied` audit row naming the staff actor, permission, method and path. A non-staff principal reaching it is refused as well (defence in depth).

**Resource-level rules:** use Laravel Policies/Gates or `BranchScope::assert()` where the decision depends on the resource (for example a branch-scoped rule); they still resolve capabilities through `$staff->can()`, never a role name.

**Cache:** Spatie caches the permission map (24 h). Spatie invalidates it on every role/permission change; the seeder also clears it. No custom cache is kept.

**Seeding** (`DashboardRolesAndPermissionsSeeder` → `SyncPermissionCatalogueAction`): additive only, no accounts, no secrets, safe on every deploy. It creates codes new to the catalogue (giving them to `ceo` and their seed roles once), deletes codes that left the catalogue, and creates missing seed roles. It **never** re-syncs an existing role, so permissions edited from the Dashboard survive deploys.

---

## 6. Authorization flow

```
request ─▶ auth:staff ──────── no valid Staff token ─────────────▶ 401 unauthenticated
             │                 (customer token lands here too)
             ▼
        abilities:staff:access ─ token is a refresh token ────────▶ 403 forbidden
             │
             ▼
        staff.standing ─────── deactivated ───────────────────────▶ 401 unauthenticated (token revoked)
             │                 open account_freeze ───────────────▶ audit + 403 account_frozen
             ▼
        staff.permission:X ─── $staff->can('X') via Spatie ─ false ▶ audit + 403 permission_denied
             │ true
             ▼
        controller / FormRequest / Policy ─▶ Action (audit-logged, actor = staff_id)
```

---

## 7. Operating notes

- **Add a permission:** add a case to `StaffPermission` with its `label()`, `group()`, `isBranchScoped()` and `seedRoles()` (never `coo` for wallet-touching codes), deploy (the seeder gives it to `ceo` and the seed roles once), gate the route with `staff.permission:<code>`, add a test for allowed and denied roles, and update §4.
- **Add or change a role:** from the Dashboard (`roles.manage`), not in code.
- **Local accounts:** `LocalStaffSeeder` creates one account per seed role (`<role>@dahab.test`; `ceo@` and `coo@` are founders) and refuses to run outside the `local`/`testing` environments. The password comes from `DAHAB_LOCAL_STAFF_PASSWORD` (default is a development-only value). No account is ever seeded in staging or production.
- **Issue a local staff token** (until sign-in exists): in tinker, `app(App\Actions\Auth\Shared\IssueTokenFamilyAction::class)->forStaff(App\Models\Staff::where('email','ceo@dahab.test')->first())->accessToken`.
- **Frozen accounts** (Part 1 §4.4) are enforced on every dashboard request by `staff.standing` (spec 002 FR-056).
