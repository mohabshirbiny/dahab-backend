# Dashboard authentication & authorization (Spatie)

**Scope:** Dashboard/Staff principals only. Customers do not use roles or permissions.
**Relationship to other docs:** [`dahab-spec-part1-auth.md`](./dahab-spec-part1-auth.md) §3–§4 remain the source of truth for *who may do what* (the permission matrix). This document defines *how that matrix is enforced in the Laravel application* with `spatie/laravel-permission`. Where the two disagree, Part 1 wins and this document is corrected.

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
- **MFA (TOTP, `pragmarx/google2fa`)** — `ceo`/`coo`/`finance` never receive a token from `login`. With no TOTP enrolled, `login` answers `mfa_enrollment_required` (`otpauth_url`, one-time `recovery_codes`, `session_ref`); `POST /dashboard/auth/mfa/enroll` (`session_ref` + code) persists the encrypted secret and hashed recovery codes and issues the session. With a TOTP enrolled, `login` answers `mfa_required` (`session_ref`) and `POST /dashboard/auth/mfa/verify` (`session_ref` + `code`, or a single-use `recovery_code`) issues the session. `session_ref` is single-use, lives `dahab-auth.mfa.session_ttl_seconds`, and an accepted TOTP time-step is never accepted twice. Both endpoints share limiter `auth.staff.mfa` (5 / 5 min per `session_ref`, 20 / min per IP). Any other role that enrolled voluntarily is challenged too. `staff_password.force_reenroll_mfa_at` newer than `staff_mfa.enrolled_at` forces re-enrollment.
- `POST /api/v1/dashboard/auth/logout` / `logout-all` — revoke the current token family / every token of the staff member.
- **Not yet built** (spec 001, user story 2/4): staff password reset. Local development can also issue a staff token from tinker (see §7).

Errors: `401 unauthenticated` (no/invalid/expired token, or a customer token), `403 forbidden` (a staff token with the wrong ability, e.g. a refresh token on `/me`), `403 permission_denied` (authenticated staff lacks the permission), `401 refresh_invalid` (replayed refresh token), `429 too_many_requests`.

---

## 3. Staff roles

Exactly six roles, fixed in the domain (Part 1 §3.1): `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`.

- Source: `App\Enums\StaffRole`. The `staff.role` column (Postgres enum `staff_role`) stays the account **classification**: it drives the `igi_has_branch` constraint, MFA policy (`isMfaRequired()`), and founder handling (`isFounder()`).
- Each role also exists as a Spatie role of the same name (guard `staff`), created by `DashboardRolesAndPermissionsSeeder`. The Spatie role is what carries permissions.
- A staff member is given the Spatie role matching their `staff.role` when the account is created (seeder / factory today; the create-staff action later). Extra roles or direct permissions may be layered on with Spatie (`assignRole`, `givePermissionTo`), which is how "change a staff member's permissions" (Part 1 §4.3) will be built.
- Roles are schema, not admin data: a new role is an enum + seeder change.

---

## 4. Staff permissions

Only permissions that the current spec needs are materialised. Everything else in Part 1 §4 is added when the endpoint that needs it is specified (foundation before modules).

| Permission | Meaning | Roles | Source |
|---|---|---|---|
| `customer.suspend` | Suspend or reinstate a customer account | `ceo`, `coo` | Part 1 §4.3 (corrected reading: both founders); spec FR-S-011 |
| `identity.view` | List identity documents and open a document's image (each image open writes `document_view_log`) | `ceo`, `verification` | Part 1 §4 matrix "Approve an ID or passport", §5.4 |
| `identity.review` | Approve or reject an identity document | `ceo`, `verification` | Part 1 §4 matrix "Approve an ID or passport"; Part 2 §10 |

Source in code: `App\Enums\StaffPermission` (`roles()` is the canonical role → permission map). The wallet asymmetry (Part 1 §3.2, §4.2) is unaffected: no wallet permission exists yet, and wallet visibility remains a Postgres grant, not an application check.

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

**Resource-level rules:** use Laravel Policies/Gates where the decision depends on the resource (for example a branch-scoped `igi_branch` rule); they still resolve capabilities through `$staff->can()`.

**Cache:** Spatie caches the permission map (24 h). Spatie invalidates it on every role/permission change; the seeder also clears it. No custom cache is kept.

**Seeding** (`DashboardRolesAndPermissionsSeeder`): idempotent (`findOrCreate` + `syncPermissions`), no accounts, no secrets, safe to run on every deploy. It converges roles to the canonical map, so a role's permissions are changed in `StaffPermission`, not at runtime.

---

## 6. Authorization flow

```
request ─▶ auth:staff ──────── no valid Staff token ─────────────▶ 401 unauthenticated
             │                 (customer token lands here too)
             ▼
        abilities:staff:access ─ token is a refresh token ────────▶ 403 forbidden
             │
             ▼
        staff.permission:X ─── $staff->can('X') via Spatie ─ false ▶ audit + 403 permission_denied
             │ true
             ▼
        controller / FormRequest / Policy ─▶ Action (audit-logged, actor = staff_id)
```

---

## 7. Operating notes

- **Add a permission:** add a case to `StaffPermission` with its `roles()`, run `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`, gate the route with `staff.permission:<code>`, add a test for allowed and denied roles, and update §4.
- **Local accounts:** `LocalStaffSeeder` creates one account per role (`<role>@dahab.test`) and refuses to run outside the `local`/`testing` environments. The password comes from `DAHAB_LOCAL_STAFF_PASSWORD` (default is a development-only value). No account is ever seeded in staging or production.
- **Issue a local staff token** (until sign-in exists): in tinker, `app(App\Actions\Auth\Shared\IssueTokenFamilyAction::class)->forStaff(App\Models\Staff::where('email','ceo@dahab.test')->first())->accessToken`.
- **Frozen accounts** (Part 1 §4.4) are not yet enforced at the permission gate; they belong to spec 001 user story 8.
