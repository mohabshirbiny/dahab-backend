# Implementation Plan: Authentication — Customer & Dashboard Staff

**Feature Directory**: `specs/001-auth-customer-staff/` | **Date**: 2026-09-19 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-auth-customer-staff/spec.md`

## Summary

Deliver Dahab's auth layer for two principals — customer and dashboard staff — on Laravel Sanctum, mirroring the applied SQL schema in `docs/Database schema/02_schema_identity.sql` and `05_schema_security.sql`. Customer sign-in is phone + password with an SMS OTP challenge on unrecognized devices; staff sign-in is email + password with TOTP MFA for founder/finance roles. Session model is short-lived access tokens (Sanctum) plus a rotating refresh token family, with revoke-family-on-replay. Every state-changing request resolves to exactly one `customer_id` XOR `staff_id`; that resolution and the audit-log write are the invariant this feature guarantees for every later Dahab feature. Customer endpoints live under `/api/v1/customer/auth/*` (guard `auth:customer`) and dashboard endpoints under `/api/v1/dashboard/*` (guard `auth:staff`); tokens carry the single abilities `customer:access`, `customer:refresh`, `staff:access`, `staff:refresh`. All are wrapped in the project's `ApiResponse` envelope, documented via OpenAPI attributes, and covered by Pest 3 feature tests through the HTTP boundary.

## Technical Context

**Language/Version**: PHP 8.3+, Laravel 12.

**Primary Dependencies**: `laravel/sanctum` (^4.3), `laravel/horizon` (^5.49), `darkaonline/l5-swagger` (^11.1), `predis/predis` locally, `phpredis` in Docker. TOTP: `pragmarx/google2fa` (to be added in P0). SMS/email: pluggable via Laravel Notifications; fake channels in tests.

**Storage**: PostgreSQL 16 as the sole primary datastore; Redis 7 for cache, sessions, queue, rate-limiter buckets, and OTP challenges. SQLite in-memory only for framework-only tests; feature tests run against Postgres (see `phpunit.xml` override in tests folder).

**Testing**: Pest 3 on PHPUnit 11 through the HTTP boundary (`postJson`, `getJson`) with `RefreshDatabase`. Fakes for SMS/email notifications, `Cache::spy()` for rate-limiter behavior, `Notification::fake()` for outbound.

**Target Platform**: Linux/x86-64 (Docker); Windows/Laragon supported for local dev.

**Project Type**: Web service (Laravel API).

**Performance Goals**: Auth endpoints under 200 ms p95 in local development against Postgres; under 100 ms p95 when the request hits the cache-only paths (rate limiter, OTP verify). Tokens issued in a single transaction — no N+1 on the sign-in path.

**Constraints**:
- Password hashes MUST NOT appear in any response, log, exception, or OpenAPI schema (grep-enforced in CI).
- Every state-changing request MUST resolve to exactly one actor before commit (Constitution Principle I).
- Migrations reversible where physically possible (Constitution / Workflow).
- No global Repository pattern; Eloquent used directly by Actions and Services (Constitution).

**Scale/Scope**:
- ~10 endpoints (customer auth) + ~7 endpoints (dashboard auth) + 2 dashboard endpoints for customer suspend/unsuspend.
- 3 new domain tables (`customer`, `staff`, `audit_log`) mirroring the applied schema, plus Spatie's standard roles/permissions tables for dashboard authorization; 4 auxiliary tables for OTP challenges, refresh-token families, trusted devices, staff device fingerprints.

## Constitution Check

*GATE: Must pass before Phase 0. Re-checked after Phase 1.*

Read against `.specify/memory/constitution.md` v1.0.0:

| Principle | Compliance | Notes |
|---|---|---|
| I. Named Actor on Every State Change (NON-NEGOTIABLE) | ✅ | This feature *is* the layer that resolves actors. Middleware sets a request-scoped `RequestContext` populated from the Sanctum token, and every Action reads it. Audit-log rows carry `customer_id XOR staff_id` per the applied CHECK. |
| II. Least Privilege Enforced by the Engine | ✅ | Row-level security policies for `customer` and staff-role grants live in the migrations, per `docs/Database schema/05_schema_security.sql`. The Laravel connection sets `SET LOCAL app.current_customer_id` / `app.current_staff_id` at the start of each request transaction; RLS policies read from that setting. |
| III. Docs Are the Source of Truth (NON-NEGOTIABLE) | ✅ | Every column, CHECK, and index in the migrations mirrors `docs/Database schema/*.sql`. Any deviation surfaces as an amendment PR to `docs/` in the same change. The clarify session recorded the customer-login rate limit that the docs left open (Part 1 §2.3). |
| IV. Foundation Before Modules | ✅ | This is the first module; it delivers migrations, actions, request validation, resources, feature tests, and OpenAPI attributes together. No half-scaffold. |
| V. Test the Boundary and the Ledger | ✅ | Pest tests are all Feature-level `postJson`/`getJson` against the router, asserting on persisted rows, response envelopes, dispatched jobs, and sent notifications. No unit tests for Actions in this feature — the Actions are exercised through the endpoints they serve. |

Constraints:
- Tech stack: PHP 8.3+, Laravel 12, PostgreSQL 16, Redis 7 — met.
- OpenAPI annotations on every `/api/v1/*` endpoint — enforced via l5-swagger attributes on each controller method.
- No password field ever returned — API Resources omit it; `User` `$hidden` set; grep in CI.
- Reversible migrations — every migration ships `down()`.

**Gate result**: PASS. No complexity tracking entries required.

## Project Structure

### Documentation (this feature)

```text
specs/001-auth-customer-staff/
├── plan.md              # This file
├── research.md          # Phase 0 — resolves technology decisions
├── data-model.md        # Phase 1 — entities and state transitions
├── quickstart.md        # Phase 1 — runnable validation guide
├── contracts/           # Phase 1 — OpenAPI schema, error-code table
│   ├── openapi.yaml
│   └── error-codes.md
├── checklists/
│   └── requirements.md  # Written by /speckit-specify
├── spec.md              # Written by /speckit-specify + /speckit-clarify
└── tasks.md             # /speckit-tasks output — NOT created here
```

### Source Code (repository root — Laravel monolith)

```text
app/
├── Actions/
│   └── Auth/
│       ├── Customer/
│       │   ├── RegisterCustomerAction.php
│       │   ├── LoginCustomerAction.php
│       │   ├── VerifyOtpAction.php
│       │   ├── LogoutCustomerAction.php        # current() + all() — no separate LogoutAllCustomerAction
│       │   │   # no RefreshCustomerSessionAction: Shared/RotateRefreshTokenAction serves both principals
│       │   ├── RequestPasswordResetAction.php
│       │   ├── ResetPasswordAction.php
│       │   ├── SetOrChangeEmailAction.php
│       │   └── ConfirmEmailAction.php
│       ├── Staff/
│       │   ├── LoginStaffAction.php
│       │   ├── EnrollMfaAction.php
│       │   ├── VerifyMfaAction.php
│       │   ├── LogoutStaffAction.php
│       │   ├── LogoutAllStaffAction.php
│       │   ├── RequestStaffPasswordResetAction.php
│       │   └── ResetStaffPasswordAction.php
│       ├── Shared/
│       │   ├── IssueTokenFamilyAction.php
│       │   ├── RotateRefreshTokenAction.php
│       │   ├── RevokeTokenFamilyAction.php
│       │   └── RecordAuditLogAction.php
│       │   # no ResolveDeviceFingerprint: hashing lives in SetRequestContext::fingerprint()
│       └── Suspension/
│           ├── SuspendCustomerAction.php
│           └── UnsuspendCustomerAction.php
├── Enums/
│   ├── StaffRole.php               # ceo, coo, finance, operations, verification, igi_branch
│   ├── SuspendedReason.php         # fixed list
│   ├── AuditEvent.php              # event kinds
│   ├── AuthErrorCode.php           # error_code strings
│   └── TokenAbility.php            # customer:access|refresh, staff:access|refresh
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── Customer/Auth/
│   │   │   ├── CustomerAuthController.php    # BUILT: register, login, refresh, me, logout, logout-all
│   │   │   ├── CustomerOtpController.php     # planned (US3)
│   │   │   ├── CustomerPasswordController.php # planned (US4)
│   │   │   └── CustomerEmailController.php   # planned (US7)
│   │   │   # no CustomerSessionController: refresh and logout-all live on CustomerAuthController
│   │   └── Dashboard/
│   │       ├── Auth/
│   │       │   ├── StaffAuthController.php   # BUILT: me, refresh — planned: login, logout, logout-all
│   │       │   ├── StaffMfaController.php    # planned (US2)
│   │       │   └── StaffPasswordController.php # planned (US4)
│   │       │   # no StaffSessionController: refresh lives on StaffAuthController
│   │       └── Customers/
│   │           └── CustomerSuspensionController.php # planned (US5)
│   ├── Middleware/
│   │   ├── SetRequestContext.php   # populates $request->context() with customer_id XOR staff_id
│   │   ├── SetDatabaseActor.php    # SET LOCAL app.current_customer_id / staff_id for RLS
│   │   ├── EnforceTradeAllowed.php # guard for stub 'trade' route used in tests
│   │   └── EnforceStaffPermission.php # Spatie-backed `staff.permission:<code>` gate
│   ├── Requests/
│   │   ├── Auth/Customer/ (Register, Login, VerifyOtp, ResetRequest, ResetSubmit, ChangeEmail, RefreshToken)
│   │   └── Auth/Staff/ (Login, MfaEnroll, MfaVerify, ResetRequest, ResetSubmit, SuspendCustomer, UnsuspendCustomer, RefreshToken)
│   └── Resources/
│       ├── Customer/CustomerResource.php     # schema CustomerProfile
│       └── Staff/StaffResource.php           # schema StaffProfile
│       # no Auth/SessionResource: App\Support\SessionDto::toArray() carries the Session schema
├── Models/
│   ├── Customer.php
│   ├── Staff.php
│   ├── (no StaffPermission model — Spatie's Role/Permission are used)
│   ├── AuditLog.php
│   ├── CustomerTrustedDevice.php
│   ├── StaffDeviceFingerprint.php
│   │   # no RefreshTokenFamily model: family_id/rotated_at/actor_kind are columns on personal_access_tokens
│   └── OneTimeToken.php            # password reset + email verification
├── Notifications/
│   ├── Customer/
│   │   ├── OtpCodeNotification.php
│   │   ├── PasswordResetNotification.php
│   │   └── EmailVerificationNotification.php
│   └── Staff/
│       └── StaffPasswordResetNotification.php
├── Policies/
│   └── CustomerSuspensionPolicy.php  # thin, resolves through $staff->can() (Spatie)
├── Providers/
│   ├── AppServiceProvider.php        # existing, RateLimiter definitions extended
│   └── AuthServiceProvider.php       # registered but intentionally empty: guards live in config/auth.php, abilities in Enums/TokenAbility.php, ability middleware aliases in bootstrap/app.php; policy binding is planned
└── Support/
    ├── ApiResponse.php               # existing
    ├── RequestContext.php            # value object: customer_id, staff_id, ip, ua, device
    └── SessionDto.php                # Session schema (access + refresh pair)

config/
└── auth.php (customer + staff guards), sanctum.php, hashing.php (Argon2id), dahab-auth.php  # dahab-auth.php holds tunables

database/migrations/
└── 2026_09_19_*_create_auth_tables.php   # per docs/Database schema/02 + 05

routes/api.php                             # /api/v1/customer/auth/* and /api/v1/dashboard/auth/*

tests/Feature/Auth/
├── Customer/{Register,Login,Otp,Logout,Me,PasswordReset,EmailVerify,Refresh,Suspended}Test.php
├── Staff/{Login,Mfa,Logout,Me,PasswordReset,Permission,Frozen,Disabled}Test.php
└── Shared/{Envelope,Enumeration,AuditLog,RateLimit}Test.php
tests/Pest.php                             # extend to Auth/ (Feature namespace already covered)
```

**Structure Decision**: single Laravel monolith, directories exactly as above. All auth code lives under `App\{Actions,Http,Models,Notifications,Policies,Support}\Auth\*` or is namespaced by principal (`Customer/`, `Staff/`) so future features import a stable path.

## Authentication architecture (as built, 2026-09-19)

The structure tree above mixes built and planned files (each is marked). What is built and enforced today:

| Concern | Implementation |
|---|---|
| Principal isolation | `config/auth.php`: guard `customer` (Sanctum, provider `customers` → `Customer`) and guard `staff` (Sanctum, provider `staff` → `Staff`). Customer routes `auth:customer`, dashboard routes `auth:staff`; no route uses `auth:sanctum`. Staff token on the Customer API and customer token on the Dashboard API → `401 unauthenticated`. |
| Route surface | `/api/v1/customer/auth/{register,login,refresh,me,logout,logout-all}`, `/api/v1/dashboard/auth/{me,refresh}`, `/api/v1/health`. Names `api.v1.customer.auth.*`, `api.v1.dashboard.auth.*`. No `/api/v1/auth/*`, no `/api/v1/user`. |
| Token abilities | `App\Enums\TokenAbility`: `customer:access`, `customer:refresh`, `staff:access`, `staff:refresh`; one per token, never `*`. Issued only by `IssueTokenFamilyAction`. Enforced with `abilities:<ability>` (Sanctum `CheckAbilities`, alias in `bootstrap/app.php`) after the guard. Wrong ability → `403 forbidden`. |
| Refresh | `RotateRefreshTokenAction` (shared): atomic claim of the refresh row via `rotated_at`, delete the family's old access token, mint a new pair in the same family, audit `auth.token.rotated`; replay → delete the family, `401 refresh_invalid`. Endpoint limiter `auth.refresh` (per family). |
| Dashboard authorization | Unchanged: Spatie roles/permissions on `Staff` only, `staff.permission:<code>` middleware → `403 permission_denied`. Customers have no roles or permissions. |
| Rate limiting | One limiter per concern in `AppServiceProvider`: `auth.customer.register`, `auth.customer.login`, `auth.staff.login` (dashboard login), `auth.otp.send`, `auth.otp.verify`, `auth.password_reset.request`, `auth.refresh`. Identity buckets of the two login limiters throw `AuthApiException::accountLocked()` (`account_locked`); every other 429 is `too_many_requests`, rendered without looking at the URL. |
| Password hashing | `config/hashing.php`: `argon2id` default, `argon.verify=false` so legacy bcrypt verifies and is rehashed on sign-in. |
| OpenAPI | Attributes on the controllers; schemes `customerBearer`, `customerRefreshBearer`, `dashboardBearer`, `dashboardRefreshBearer` and shared schemas on `App\Http\Controllers\Controller` and the Resource/Request/DTO classes; tags `Customer Auth` / `Dashboard Auth`. |
| Tests | `tests/Feature/Auth/Shared/{PrincipalIsolation,TokenAbilities,RateLimiterSeparation,PasswordHashing,OpenApiGeneration}Test.php` plus the existing per-principal suites, all through the HTTP boundary. |

What is **not** built (see `tasks.md`): dashboard login / MFA / logout, customer OTP (new-device branch), password reset, email verification, suspension endpoints and `EnforceTradeAllowed`, founder-security sign-in integration. `contracts/openapi.yaml` flags each operation with `x-implemented`.

## Complexity Tracking

Constitution Check passed with no violations. This section is empty by design.

## Phase Outputs

- **Phase 0 · Research** → [research.md](./research.md)
- **Phase 1 · Design & Contracts** → [data-model.md](./data-model.md), [contracts/openapi.yaml](./contracts/openapi.yaml), [contracts/error-codes.md](./contracts/error-codes.md), [quickstart.md](./quickstart.md)
- **Phase 2 · Tasks** → generated by `/speckit-tasks` into `tasks.md`

## Post-Design Constitution Re-check

Executed after Phase 1 artifacts were written:

- Every endpoint in `contracts/openapi.yaml` has an owning Action; no controller does more than validate → dispatch → resource.
- Every entity in `data-model.md` maps to a table declared in `docs/Database schema/*.sql`, no invented columns.
- Rate-limit defaults in `config/dahab-auth.php` (`rate_limits.*`) match the spec (`FR-X-004`); they are file constants, not `.env` values — only the token TTLs, OTP tuning and password-check flag are environment-driven.
- No file in the plan calls for a Repository class or an interface layered over Eloquent (Constitution Principle IV / user brief).

**Re-check result**: PASS.
