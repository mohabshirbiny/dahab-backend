---
description: "Task list for feature 001-auth-customer-staff"
---

# Tasks: Authentication — Customer & Dashboard Staff

**Input**: Design documents from `specs/001-auth-customer-staff/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md), [.specify/memory/constitution.md](../../.specify/memory/constitution.md).

**Tests**: Required — Constitution Principle V mandates feature-level Pest tests through the HTTP boundary. Each user-story phase writes tests **first**, then implements, then confirms green.

**Skill references**: Every implementation task names the Dahab helper skill it should be run through. `/speckit-implement` MUST invoke `Skill(<name>)` before executing that task. Names:
- `laravel-migrations` · `laravel-eloquent-models` · `laravel-actions-services` · `laravel-api-endpoints` · `laravel-auth-authorization` · `laravel-pest-testing` · `laravel-queues-notifications` · `laravel-quality-gates`.

## Format: `[ID] [P?] [Story?] Description (skill: <name>)`

- **[P]** — parallelizable (different files, no in-flight dependency).
- **[Story]** — user-story tag; present ONLY on Phase 3–10 tasks.

---

## Phase 1 · Setup

**Purpose**: prerequisites that unblock every subsequent phase.

- [X] T001 Amend `docs/Database schema/02_schema_identity.sql` and `docs/Database schema/05_schema_security.sql` with the six additions listed in `data-model.md` — `staff_password`, `customer_password`, `staff_mfa`, `staff_permission` (later replaced by Spatie's tables — see T009), `customer_trusted_device`, `staff_device_fingerprint`, `one_time_token`, and the three added columns on `personal_access_tokens`. Annotate each new block `-- Added by feature 001-auth-customer-staff`. (Constitution Principle III: docs first.)
- [X] T002 [P] Add `pragmarx/google2fa:^8.0` to `composer.json` and run `composer update --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`.
- [X] T003 [P] Create `config/dahab-auth.php` with the tunables enumerated in `research.md` R-07 (rate limits, access/refresh TTL, OTP TTL/attempts/cooldown, MFA roles, founder roles). Since the audit it also holds the `customer_register` limiter block (T147).
- [X] T004 [P] Extend `.env.example` with the `DAHAB_AUTH_*` defaults and, since the audit, `HASH_DRIVER` / `ARGON_*` (T149); copy to `.env`. (`SANCTUM_TOKEN_EXPIRATION_MINUTES` was listed here originally, but no code reads it — access-token TTL is `DAHAB_AUTH_ACCESS_TTL_MINUTES` — so it was removed.)
- [X] T005 Create empty test tree `tests/Feature/Auth/{Customer,Staff,Shared}` and extend `tests/Pest.php` to bind `Tests\TestCase` in the new namespace.

---

## Phase 2 · Foundational (BLOCKING PREREQUISITES)

**Purpose**: shared schema, enums, models, seeders, middleware, and shared Actions. **No user-story phase may start before this phase is complete.**

### Migrations (skill: laravel-migrations)

- [X] T006 [P] `database/migrations/2026_09_19_000010_create_identity_baseline.php` — create Postgres enum `staff_role`, table `staff` mirroring `docs/Database schema/02_schema_identity.sql` §4 (including `is_active BOOLEAN DEFAULT TRUE`, `CONSTRAINT igi_has_branch`), table `customer` §5 (including `suspended_needs_actor` CHECK, unique `phone`, unique `email`, `preferred_lang` CHECK IN (`ar`,`en`)), tables `account_freeze` and `founder_device_approval`. Reversible.
- [X] T007 [P] `2026_09_19_000020_create_audit_log.php` — create `audit_log` per §13 of `05_schema_security.sql` including `audit_has_actor` CHECK, three indexes, and the `audit_no_update` trigger via a raw `DB::unprepared` block. Reversible (drop trigger + table).
- [X] T008 [P] `2026_09_19_000030_create_auth_credentials.php` — `staff_password (staff_id PK FK, password_hash TEXT NOT NULL, password_changed_at, force_reenroll_mfa_at)`, `customer_password (customer_id PK FK, password_hash TEXT NOT NULL, password_changed_at)`, `staff_mfa (staff_id PK FK, mfa_secret_encrypted TEXT NOT NULL, enrolled_at NOT NULL, recovery_codes_hash JSONB)`. Reversible.
- [X] T009 [P] `2026_09_19_000040_create_staff_permission.php` — composite-PK `(role staff_role, permission_code TEXT)` table. **Superseded by Spatie**: dropped by `2026_09_19_154100_drop_staff_permission.php`; Spatie's standard tables come from `2026_09_19_154050_create_permission_tables.php` (`model_id` as `uuid`). Both reversible.
- [X] T010 [P] `2026_09_19_000050_create_auth_devices_and_tokens.php` — `customer_trusted_device`, `staff_device_fingerprint`, `one_time_token` (with the exact CHECK from `data-model.md` requiring exactly one of `actor_customer_id`/`actor_staff_id`).
- [X] T011 `2026_09_19_000060_alter_personal_access_tokens_add_family.php` — `ALTER TABLE personal_access_tokens` add `family_id UUID NULL`, `rotated_at TIMESTAMPTZ NULL`, `revoked_at TIMESTAMPTZ NULL`, `actor_kind TEXT CHECK IN (customer, staff) NOT NULL DEFAULT customer`. Index on `family_id`. Reversible.
- [X] T012 `2026_09_19_000070_enable_rls_and_grants.php` — CREATE ROLE `dahab_app` (NOLOGIN, `INHERIT`); GRANT on target tables; enable RLS on `customer`; create RLS policies matching §5.3 patterns; create SQL function `dahab_current_customer_id()` reading `current_setting('app.current_customer_id', true)`. Reversible via drop-in-reverse.

### Enums (skill: laravel-eloquent-models)

- [X] T013 [P] `app/Enums/StaffRole.php` — `ceo, coo, finance, operations, verification, igi_branch`. Include `isMfaRequired(): bool` (ceo|coo|finance) and `isFounder(): bool` (ceo|coo).
- [X] T014 [P] `app/Enums/SuspendedReason.php` — `fraud_suspected, policy_violation, kyc_failed, staff_request, other`.
- [X] T015 [P] `app/Enums/AuditEvent.php` — event kinds: `auth.customer.sign_in`, `auth.customer.sign_in_failed`, `auth.customer.otp_sent`, `auth.customer.otp_verified`, `auth.customer.otp_failed`, `auth.customer.password_reset_requested`, `auth.customer.password_reset_completed`, `auth.customer.email_verified`, `auth.customer.suspended`, `auth.customer.unsuspended`, `auth.staff.sign_in`, `auth.staff.sign_in_failed`, `auth.staff.mfa_verified`, `auth.staff.mfa_failed`, `auth.staff.password_reset_completed`, `auth.staff.permission_denied`, `auth.token.rotated`, `auth.token.family_revoked`, `auth.token.logout_all`.
- [X] T016 [P] `app/Enums/AuthErrorCode.php` — string enum matching `contracts/error-codes.md` verbatim.
- [X] T017 [P] `app/Enums/DevicePlatform.php` — `ios, android, web`.

### Models (skill: laravel-eloquent-models)

- [X] T018 [P] `app/Models/Customer.php` — extends `Authenticatable`, `HasApiTokens`, casts (`preferred_lang => 'string'`, `is_verified => bool`, `is_suspended => bool`, timestamps), `$hidden = ['*_password']` for safety, 1:1 relation `password()` to `CustomerPassword`, 1:many `trustedDevices()`, `oneTimeTokens()`.
- [X] T019 [P] `app/Models/CustomerPassword.php` — `$table='customer_password'`, PK `customer_id`, `password_hash` cast to `hashed`.
- [X] T020 [P] `app/Models/Staff.php` — extends `Authenticatable`, `HasApiTokens`, `role` cast to `StaffRole`, `is_active` cast bool, 1:1 `password()`, 1:1 `mfa()`, 1:many `deviceFingerprints()`, `activeFreeze()` (hasOne where `unfrozen_at IS NULL`).
- [X] T021 [P] `app/Models/StaffPassword.php`, `app/Models/StaffMfa.php` (with `encrypted` cast on `mfa_secret_encrypted`, `array` cast on `recovery_codes_hash`).
- [X] T022 [P] ~~`app/Models/StaffPermission.php`~~ — **superseded**: model removed; `Staff` uses Spatie `HasRoles` (guard `staff`), permission names live in `app/Enums/StaffPermission.php`.
- [X] T023 [P] `app/Models/AuditLog.php` — no timestamps other than `created_at`; JSON casts on `before_json`/`after_json`.
- [X] T024 [P] `app/Models/CustomerTrustedDevice.php`, `app/Models/StaffDeviceFingerprint.php`.
- [X] T025 [P] `app/Models/OneTimeToken.php` — `expires_at` datetime cast, scope `active()` (`consumed_at IS NULL AND expires_at > now()`).
- [X] T026 [P] `app/Models/AccountFreeze.php`, `app/Models/FounderDeviceApproval.php` (read-only for this feature).

### Factories (skill: laravel-eloquent-models)

- [ ] T027 [P] `database/factories/CustomerFactory.php` and `StaffFactory.php` exist with the states `verified()`, `suspended()`, `withPassword()` (customer) and `role()`, `disabled()`, `withPassword()` (staff). **Not built** (previously marked done): `CustomerPasswordFactory`, `StaffPasswordFactory`, `AuditLogFactory`, and the states `frozen()`, `withMfa()`, `founder()`. (`StaffPermissionFactory` is obsolete — the model was replaced by Spatie, T009.) Note `suspended()` needs a real staff id for `suspended_by` to satisfy the DB CHECK.

### Seeders (skill: laravel-eloquent-models)

- [X] T028 `database/seeders/DashboardRolesAndPermissionsSeeder.php` — the six Spatie roles and the permissions the current spec needs (`customer.suspend` → `ceo`, `coo`, per Part 1 §4.3), mapped by `App\Enums\StaffPermission::roles()`. Idempotent (`findOrCreate` + `syncPermissions`).
- [X] T029 `database/seeders/LocalStaffSeeder.php` — one staff row per role for local/dev with password `seeded-password-1`. `--env=production` short-circuits and does nothing.

### Support & value objects (skill: laravel-actions-services)

- [X] T030 [P] `app/Support/RequestContext.php` — immutable value object: `?customerId`, `?staffId`, `ip`, `userAgent`, `deviceFingerprintHash`, `deviceId`. Static factories `forCustomer()`, `forStaff()`, `anonymous()`; assertion `assertHasActor()`.
- [X] T031 ~~`app/Support/AuthorizationMatrix.php`~~ — **superseded by Spatie**: `$staff->can()` / `getAllPermissions()` and Spatie's own cache replace the custom matrix and cache.

### Middleware (skill: laravel-auth-authorization)

- [X] T032 `app/Http/Middleware/SetRequestContext.php` — reads Sanctum-authenticated actor, populates `$request->attributes->set('context', RequestContext::…)`, enforces XOR invariant (exactly one of customerId/staffId when authenticated).
- [X] T033 `app/Http/Middleware/SetDatabaseActor.php` — publishes the actor to Postgres with `set_config('app.current_customer_id', ?, false)` / `set_config('app.current_staff_id', ?, false)` (session-scoped, **not** `SET LOCAL` inside a request transaction as originally written); applied to every `/api/v1/*` route through the `api` middleware group in `bootstrap/app.php`. Whether RLS actually binds the application connection (it connects as the table-owning DB user) has not been verified and is an open question for the RLS work (Constitution Principle II).
- [ ] T034 `app/Http/Middleware/EnforceTradeAllowed.php` — refuses with 403 `account_suspended` when `customer.is_suspended` or `!customer.is_verified`. Payload includes `suspended_reason` only on `/auth/me`, never on refused actions.
- [X] T035 `app/Http/Middleware/EnforceStaffPermission.php` — parametric middleware `staff.permission:customer.suspend`; decision is Spatie's `$staff->can()`, denial → 403 `permission_denied` + `auth.staff.permission_denied` audit row.

### Shared actions (skill: laravel-actions-services)

- [X] T036 [P] `app/Actions/Auth/Shared/RecordAuditLogAction.php` — single method `execute(AuditEvent $event, string $outcome, array $payload=[])`; writes `audit_log` inside the current transaction; reads `RequestContext`.
- [X] T037 [P] Device fingerprint hashing (`sha256(X-Device-Id | X-Device-Platform)`, `null` when the header is absent) — implemented inline in `SetRequestContext::fingerprint()`; the separate `ResolveDeviceFingerprint` action class was never created and is not planned.
- [X] T038 [P] `app/Actions/Auth/Shared/IssueTokenFamilyAction.php` — inserts one access token (single ability `<kind>:access`) and one refresh token (single ability `<kind>:refresh`), both stamped with a shared `family_id` UUID and `actor_kind`; accepts an existing `family_id` for rotation. Returns a `SessionDto`. Abilities come from `App\Enums\TokenAbility` (T145); the wildcard `*` and the bare `refresh` ability are no longer used.
- [X] T039 [P] `app/Actions/Auth/Shared/RotateRefreshTokenAction.php` — atomically claims the presented refresh row (`rotated_at IS NULL` → now), deletes the family's old access token, mints a new pair in the same family, audits `auth.token.rotated`; a replay (claim updates 0 rows) deletes the whole family and throws `refresh_invalid`. **Was marked done but did not exist; implemented in the audit remediation (T146).**
- [X] T040 [P] `app/Actions/Auth/Shared/RevokeTokenFamilyAction.php` — `byFamily()`, `forActor()`, `byTokenId()`; revocation **deletes** the `personal_access_tokens` rows (that is what makes Sanctum refuse them) and audit-logs `auth.token.family_revoked` / `auth.token.logout_all`. It does not write `revoked_at` (column reserved, unused) as originally described.

### Rate limiters + password policy (skill: laravel-auth-authorization)

- [X] T041 Extend `app/Providers/AppServiceProvider.php::boot()` with one named limiter per concern — `auth.customer.register`, `auth.customer.login`, `auth.staff.login`, `auth.otp.send`, `auth.otp.verify`, `auth.password_reset.request`, `auth.refresh` (seven; `auth.customer.register` was added by T147, before that registration shared the login limiter). Each respects `config('dahab-auth.rate_limits.<name>.*')` (OTP limiters use `dahab-auth.otp.*`).
- [X] T042 In the same `boot()`: `Password::defaults(fn() => app()->environment('testing') ? Password::min(10) : Password::min(10)->uncompromised())`.

### Providers, config, and routes skeleton

- [X] T043 ~~`app/Providers/AuthServiceProvider.php` registering `refresh` / `mfa-pending` / `otp-pending` abilities and a `TrustedDevice` policy~~ — **superseded**: the provider is registered but intentionally empty. Token abilities are `App\Enums\TokenAbility` (T145) enforced by the `abilities` middleware alias in `bootstrap/app.php`; `mfa-pending` / `otp-pending` abilities and the `TrustedDevice` policy were never built and are not needed until US2/US3 decide their shape.
- [X] T044 Update `config/auth.php` guards: a `staff` guard (Sanctum driver, provider `staff` → `Staff`) and, since the audit (T143), a `customer` guard (Sanctum driver, provider `customers` → `Customer`).
- [X] T045 `routes/api.php` — `Route::prefix('v1')->name('api.v1.')` group; `SetRequestContext` and `SetDatabaseActor` are appended to the `api` middleware group in `bootstrap/app.php` (not per route group). Surface is `/api/v1/customer/auth/*` and `/api/v1/dashboard/auth/*` (T143).
- [ ] T046 `tests/Feature/Auth/Shared/HealthTest.php` — assert `GET /api/v1/health` still passes with the new middleware stack, and that a request with no `X-Device-Id` still succeeds against a non-auth-required route.

**Checkpoint**: `php artisan migrate:fresh --seed` succeeds; `./vendor/bin/pest tests/Feature/Auth/Shared/HealthTest.php` is green. User stories may now proceed.

---

## Phase 3 · US1 — Customer register + sign-in (known device) + `me` + logout (Priority: P1) 🎯 MVP

**Goal**: baseline customer session — the minimum surface every other Dahab feature depends on.

**Independent test**: create a customer, sign in from a known device, read `/auth/me`, sign out, `me` then returns 401.

### Tests (skill: laravel-pest-testing) — FAIL first

- [X] T047 [P] [US1] `tests/Feature/Auth/Customer/RegisterTest.php` — happy path (201, `data.customer.id`, session envelope, `Password` grep-free); duplicate phone → same 422 shape as validation failure (no enumeration); password below 10 chars → validation_failed; audit row `auth.customer.sign_in` written.
- [X] T048 [P] [US1] `tests/Feature/Auth/Customer/LoginKnownDeviceTest.php` — correct phone+password with a `X-Device-Id` seeded as trusted → 200 with `session` block, no `otp_required`; wrong password → 401 `invalid_credentials`; unknown phone → identical 401 shape; response body never contains the string `password_hash`.
- [X] T049 [P] [US1] `tests/Feature/Auth/Customer/MeTest.php` — `GET /auth/me` returns the customer's own row with `trade_allowed=false` (unverified), never a password, never internal columns; 401 without a valid token.
- [X] T050 [P] [US1] `tests/Feature/Auth/Customer/LogoutTest.php` — `POST /customer/auth/logout` returns 204 and deletes the current access + refresh pair; a subsequent request with the access token → 401; `POST /customer/auth/logout-all` deletes every token for the customer. (Revocation deletes rows; `revoked_at` is not written.)
- [X] T051 [P] [US1] `tests/Feature/Auth/Customer/RateLimitLoginTest.php` — after 5 failed logins on the same phone within 15 min the 6th returns 429 with `account_locked` and a `Retry-After` header.

### Implementation

- [X] T052 [US1] `app/Http/Requests/Auth/Customer/RegisterCustomerRequest.php` — validates `phone` (E.164), `password` (`Password::defaults()`), `email` nullable email, `preferred_lang` in {ar,en}. (skill: laravel-api-endpoints)
- [X] T053 [US1] `app/Http/Requests/Auth/Customer/LoginCustomerRequest.php` — required `phone`, `password`.
- [X] T054 [P] [US1] `app/Http/Resources/Customer/CustomerResource.php` — projects the columns listed in `contracts/openapi.yaml#/components/schemas/Customer`; adds derived `trade_allowed`.
- [X] T055 [P] [US1] ~~`app/Http/Resources/Auth/SessionResource.php`~~ — **superseded**: `app/Support/SessionDto.php::toArray()` produces the `Session` shape (`token_type`, `access_token`, `access_token_expires_at`, `refresh_token`, `refresh_token_expires_at`, `family_id`) and carries the `Session` OpenAPI schema.
- [X] T056 [US1] `app/Actions/Auth/Customer/RegisterCustomerAction.php` — inside `DB::transaction`: create `customer`, create `customer_password`, mark supplied device as trusted, invoke `IssueTokenFamilyAction`, audit-log `auth.customer.sign_in`. (skill: laravel-actions-services)
- [X] T057 [US1] `app/Actions/Auth/Customer/LoginCustomerAction.php` — phone + password; verifies against `customer_password.password_hash` with the default hasher (Argon2id since T149; a bcrypt hash still verifies and is rehashed on success); on success from a trusted device issues a token family and audit-logs; wrong password / unknown phone → identical `invalid_credentials`. **Untrusted device: currently refused with `401 invalid_credentials` (audited as `unknown_device`) — the `pending_otp` branch this task originally described is US3 (T088) and is not built.** Limiter `auth.customer.login`.
- [X] T058 [US1] `app/Actions/Auth/Customer/LogoutCustomerAction.php` — `current()` (revokes the token's family) and `all()` (revokes every token of the customer) via `RevokeTokenFamilyAction`. There is no separate `LogoutAllCustomerAction`. (skill: laravel-actions-services)
- [X] T059 [US1] `app/Http/Controllers/Api/V1/Customer/Auth/CustomerAuthController.php` (moved from `Api/V1/Auth/` by T143) — `register`, `login`, `refresh`, `me`, `logout`, `logoutAll`. Thin: validate → action → resource. **OpenAPI attributes on every method were missing although this task was marked done; added by T148.** (skill: laravel-api-endpoints)
- [X] T060 [US1] ~~`CustomerSessionController.php`~~ — **superseded**: `logoutAll` and `refresh` are methods on `CustomerAuthController`; no separate session controller was created.
- [X] T061 [US1] Wire endpoints in `routes/api.php` under `/customer/auth`: `POST register` (`throttle:auth.customer.register`), `POST login` (`throttle:auth.customer.login`); then behind `auth:customer`: `POST refresh` (`abilities:customer:refresh`, `throttle:auth.refresh`), and behind `abilities:customer:access`: `GET me`, `POST logout`, `POST logout-all`. Route names `api.v1.customer.auth.*` (T143).
- [X] T062 [US1] Run `./vendor/bin/pest --filter=Customer/(Register|LoginKnownDevice|Me|Logout|RateLimitLogin)` — every test green. (skill: laravel-quality-gates)

**Checkpoint**: baseline customer auth works end-to-end. MVP viable.

---

## Phase 4 · US2 — Dashboard staff sign-in with permission authorization (Priority: P1)

**Goal**: staff sign in, are recognized by role, and are refused actions their role does not permit.

**Independent test**: seeded staff of each role can sign in; a stub route gated on `customer.suspend` returns 200 for finance and 403 for operations.

### Tests

- [ ] T063 [P] [US2] `tests/Feature/Auth/Staff/StaffLoginTest.php` — `operations` role: correct email+password → 200 with session; wrong password → 401 `invalid_credentials`; `is_active=false` → identical 401 shape.
- [X] T064 [P] [US2] `tests/Feature/Auth/Staff/StaffDashboardAccessTest.php` — `GET /dashboard/auth/me` returns the staff row, Spatie `roles` and effective `permissions` (real bearer token).
- [ ] T065 [P] [US2] `tests/Feature/Auth/Staff/StaffLogoutTest.php` — logout + logout-all behave like customer variant.
- [X] T066 [P] [US2] `tests/Feature/Auth/Staff/StaffDashboardAccessTest.php` — a probe route (registered inside the test, not in `routes/api.php`) protected by `staff.permission:customer.suspend`: `ceo`/`coo` → 200; `finance`/`operations`/`verification`/`igi_branch` → 403 `permission_denied` (+ audit row). Docs Part 1 §4.3 gives suspend to the founders, not `finance`. Role/permission mechanics: `StaffRolesAndPermissionsTest.php`; customer boundary: `Shared/CustomerDashboardBoundaryTest.php`.
- [ ] T067 [P] [US2] `tests/Feature/Auth/Staff/StaffLoginRateLimitTest.php` — 5 fails / 30 min → 6th 429 `account_locked`.
- [ ] T068 [P] [US2] `tests/Feature/Auth/Staff/StaffMfaChallengeTest.php` — `finance` role signed-in for the first time: response is `mfa_enrollment_required` with `otpauth_url`; after `POST mfa/enroll` with a valid TOTP → session; subsequent logins issue `mfa_required` (not enrollment).
- [ ] T069 [P] [US2] `tests/Feature/Auth/Staff/StaffMfaVerifyTest.php` — with an enrolled `ceo`: login → `mfa_required`; `POST mfa/verify` with correct TOTP → session; wrong TOTP → 401 `mfa_invalid`.

### Implementation

- [ ] T070 [US2] `app/Http/Requests/Auth/Staff/{LoginStaffRequest,MfaVerifyRequest,MfaEnrollRequest}.php`. (skill: laravel-api-endpoints)
- [X] T071 [P] [US2] `app/Http/Resources/Staff/StaffResource.php` — `roles` and `permissions` arrays from Spatie (no separate permission resource).
- [ ] T072 [US2] `app/Actions/Auth/Staff/LoginStaffAction.php` — verify email + Argon2id password against `staff_password`; refuse if `staff.is_active=false` OR open `account_freeze` row; if role `isMfaRequired()`: emit `mfa_enrollment_required` if `staff_mfa` absent, else `mfa_required` (short-lived `mfa-pending` token issued as the `session_ref`); otherwise issue full token family. RateLimiter `auth.staff.login`. (skill: laravel-actions-services)
- [ ] T073 [US2] `app/Actions/Auth/Staff/EnrollMfaAction.php` — accepts `session_ref` + TOTP code; verifies against a freshly generated `Google2FA` secret held in the pending session; on success writes `staff_mfa` with encrypted secret + Argon2id-hashed recovery codes; issues session. Returns recovery codes exactly once.
- [ ] T074 [US2] `app/Actions/Auth/Staff/VerifyMfaAction.php` — reads `staff_mfa.mfa_secret_encrypted` (decrypt), verifies TOTP, checks recovery-code fallback (Argon2id compare against the hashed set); on success mints session; on failure audit-logs `auth.staff.mfa_failed`.
- [ ] T075 [US2] `app/Actions/Auth/Staff/LogoutStaffAction.php` + `LogoutAllStaffAction.php`.
- [ ] T076 [US2] `app/Http/Controllers/Api/V1/Dashboard/Auth/StaffAuthController.php` — login/me/refresh/logout endpoints. (skill: laravel-api-endpoints) — **`me` and `refresh` are done** (OpenAPI attributes included, `dashboardBearer` / `dashboardRefreshBearer`); login and logout remain.
- [ ] T077 [US2] `app/Http/Controllers/Api/V1/Dashboard/Auth/StaffMfaController.php` — `verify`, `enroll`.
- [ ] T078 [US2] Wire routes under `/dashboard/auth/*` with rate limiters. Authenticated routes already sit behind `auth:staff` (Sanctum driver, provider `staff`, which rejects customer tokens with 401) plus `abilities:staff:access` (`abilities:staff:refresh` for refresh); `GET /dashboard/auth/me` and `POST /dashboard/auth/refresh` are wired this way. **Remaining: `POST login` (`throttle:auth.staff.login`), MFA, logout routes.**
- [X] T079 [US2] ~~Add stub route `/api/v1/dashboard/_probe`~~ — done inside the tests instead, so no stub route ships in `routes/api.php`.
- [ ] T080 [US2] Run `./vendor/bin/pest --filter=Staff/(StaffLogin|StaffMe|StaffLogout|PermissionGate|StaffLoginRateLimit|StaffMfa)` — green. (skill: laravel-quality-gates)

**Checkpoint**: both P1 stories work independently. Staff can operate the dashboard.

---

## Phase 5 · US3 — New-device OTP for customers (Priority: P2)

**Goal**: sign-in from an unrecognized device is gated by an SMS OTP; successful verification remembers the device for next time.

### Tests

- [X] T081 [P] [US3] `tests/Feature/Auth/Customer/OtpChallengeIssuedTest.php` — login with a fresh `X-Device-Id` → 200 with `otp_required=true`, `challenge_id`, `expires_at`, `resend_available_at`; no session; `OtpCodeNotification` was queued exactly once. (skill: laravel-pest-testing) — *Tests T081–T084 live in `tests/Feature/Auth/Customer/NewDeviceOtpTest.php`.*
- [X] T082 [P] [US3] `tests/Feature/Auth/Customer/OtpVerifySuccessTest.php` — with a valid challenge and correct code → session issued; the `X-Device-Id` is now in `customer_trusted_device`; a second login from the same device skips the challenge.
- [X] T083 [P] [US3] `tests/Feature/Auth/Customer/OtpVerifyFailureTest.php` — wrong code returns 401 `otp_invalid`; after 5 wrong codes the challenge is voided and the next verify returns `otp_invalid` even for the right code; `Carbon::setTestNow(+6 minutes)` returns `otp_expired`.
- [X] T084 [P] [US3] `tests/Feature/Auth/Customer/OtpResendRateLimitTest.php` — resending within 60 s → 429 `too_many_requests`; sixth send within an hour → 429.

### Implementation

- [X] T085 [US3] `app/Services/OtpChallengeService.php` — Redis hash keyed by `challenge_id`; fields `{customer_id, phone, code_hash, expires_at, verify_attempts, sends}`. Methods `issue()`, `verify()`, `resend()`. Uses `Cache::store('redis')` in prod / `Cache::store('array')` in tests (TTL honored via `Carbon::now()`). (skill: laravel-actions-services) — *Done as `app/Services/CustomerLoginChallengeStore.php` (encrypted payload on the default cache store, like `CustomerRegistrationSessionStore`) + `app/Actions/Auth/Customer/CustomerLoginChallengeAction.php` (issue/resend).*
- [X] T086 [US3] `app/Notifications/Customer/OtpCodeNotification.php` — SMS channel (via a small `SmsChannel` shim in `app/Notifications/Channels/SmsChannel.php` that queues the message; in tests `Notification::fake()` captures it). (skill: laravel-queues-notifications) — *Done as `app/Notifications/CustomerLoginOtpNotification.php` on the existing `sms` channel.*
- [X] T087 [US3] `app/Actions/Auth/Customer/VerifyOtpAction.php` — hashed compare, TTL check, attempts counter; on success upserts `customer_trusted_device` and calls `IssueTokenFamilyAction`; audit-logs the outcome. (skill: laravel-actions-services) — *Done as `VerifyCustomerLoginOtpAction`; the challenge is also bound to the requesting device fingerprint.*
- [X] T088 [US3] Wire the `LoginCustomerAction` new-device branch to `OtpChallengeService::issue()` and dispatch the notification.
- [X] T089 [US3] `app/Http/Controllers/Api/V1/Auth/CustomerOtpController.php` — `verify`, `resend`. (skill: laravel-api-endpoints) — *Done as `app/Http/Controllers/Api/V1/Customer/Auth/CustomerLoginOtpController.php`.*
- [X] T090 [US3] Add routes `POST /auth/otp/verify` and `POST /auth/otp/resend` with the `auth.otp.verify` and `auth.otp.send` limiters. — *Routes are `/customer/auth/otp/verify` (limiter `auth.otp.verify`) and `/customer/auth/otp/resend` (cooldown and hourly cap enforced per challenge in the action, since the request carries no phone).*
- [X] T091 [US3] Run `./vendor/bin/pest --filter=Customer/Otp` — green. (skill: laravel-quality-gates)

---

## Phase 6 · US6 — Session refresh and revocation (Priority: P2)

**Goal**: rotate the refresh token on every use; contain a leaked refresh by revoking the whole family on replay.

### Tests

- [X] T092 [P] [US6] Refresh rotation test — covered by `tests/Feature/Auth/Shared/TokenAbilitiesTest.php` ("rotates the pair on refresh…", both principals): a valid refresh token returns a new pair with the same `family_id`, correct abilities, previous access token retired, `auth.token.rotated` audited. (Planned file name `RefreshRotateTest.php` was not used.)
- [X] T093 [P] [US6] Refresh replay test — covered by `tests/Feature/Auth/Shared/TokenAbilitiesTest.php` ("treats a replayed refresh token as theft…", both principals): replay → 401 `refresh_invalid` and the whole family is deleted; another family of the same actor survives. (Planned file name `RefreshReplayTest.php` was not used.)
- [ ] T094 [P] [US6] `tests/Feature/Auth/Shared/LogoutAllRevocationTest.php` — logout-all revokes every family belonging to the actor for both principals. **Customer logout-all is covered by `Customer/LogoutTest.php`; staff logout-all does not exist yet.**

### Implementation

- [X] T095 [US6] `RotateRefreshTokenAction` is wired behind `POST /customer/auth/refresh` and `POST /dashboard/auth/refresh`. Middleware: the principal's guard (`auth:customer` / `auth:staff`) + `abilities:<principal>:refresh`; the matching access token is refused with 403 `forbidden`. (skill: laravel-auth-authorization)
- [X] T096 [US6] `CustomerAuthController::refresh()` and `StaffAuthController::refresh()` (no separate `*SessionController` classes). Response: `data` is the `Session`.
- [X] T097 [US6] Refresh routes carry `throttle:auth.refresh` (60/hour per `family_id`).
- [ ] T098 [US6] Run the refresh/revocation tests — green for rotate and replay (`TokenAbilitiesTest`); stays open until T094 (staff logout-all) exists.

---

## Phase 7 · US4 — Password reset for both principals (Priority: P2)

**Goal**: forgotten password → SMS/email token → new password → old sessions revoked.

### Tests

- [ ] T099 [P] [US4] `tests/Feature/Auth/Customer/PasswordResetRequestTest.php` — request with a known phone queues `PasswordResetNotification`; request with unknown phone returns the same 204 shape and queues nothing; both branches are indistinguishable to the caller.
- [ ] T100 [P] [US4] `tests/Feature/Auth/Customer/PasswordResetTest.php` — valid token + new password → 204; every prior token family for the customer is revoked; old password fails; new password succeeds.
- [ ] T101 [P] [US4] `tests/Feature/Auth/Customer/PasswordResetTokenReuseTest.php` — a consumed token → 401 `token_invalid`; an expired token → 401 `token_expired`.
- [ ] T102 [P] [US4] `tests/Feature/Auth/Staff/StaffPasswordResetTest.php` — staff variant; for `ceo`/`coo` accounts, `staff_password.force_reenroll_mfa_at` is set; next login returns `mfa_enrollment_required` even if `staff_mfa` was already present (row is deleted on reset).

### Implementation

- [ ] T103 [US4] `app/Services/OneTimeTokenService.php` — issue/consume tokens for `password_reset_customer`, `password_reset_staff`, `email_verification`; token payload is `Str::random(64)`; stored as sha256. (skill: laravel-actions-services)
- [ ] T104 [P] [US4] `app/Notifications/Customer/PasswordResetNotification.php` (SMS) and `app/Notifications/Staff/StaffPasswordResetNotification.php` (mail). (skill: laravel-queues-notifications)
- [ ] T105 [US4] `app/Actions/Auth/Customer/RequestPasswordResetAction.php` + `app/Actions/Auth/Staff/RequestStaffPasswordResetAction.php` — issue token, dispatch notification, audit-log. RateLimiter `auth.password_reset.request`.
- [ ] T106 [US4] `app/Actions/Auth/Customer/ResetPasswordAction.php` + `app/Actions/Auth/Staff/ResetStaffPasswordAction.php` — consume token; update `*_password.password_hash`; invoke `RevokeTokenFamilyAction` on every family for that actor; for founder-role staff (`ceo`|`coo`) set `staff_password.force_reenroll_mfa_at=now()` AND delete `staff_mfa` row so next login demands enrollment.
- [ ] T107 [US4] `CustomerPasswordController` (`request-reset`, `reset`) and `StaffPasswordController` (same shape).
- [ ] T108 [US4] Wire routes under `/auth/password/*` and `/dashboard/auth/password/*`.
- [ ] T109 [US4] Run `./vendor/bin/pest --filter="Customer/PasswordReset|Staff/StaffPasswordReset"` — green.

---

## Phase 8 · US5 — Suspended customer can read but not trade (Priority: P2)

**Goal**: `EnforceTradeAllowed` middleware refuses state-changing trade actions for suspended customers; staff with the right permission can suspend/unsuspend.

### Tests

- [ ] T110 [P] [US5] `tests/Feature/Auth/Customer/SuspendedCanSignInTest.php` — an `is_suspended=true` customer signs in and `/auth/me` returns `is_suspended=true`, `trade_allowed=false`, `suspended_reason` populated.
- [ ] T111 [P] [US5] `tests/Feature/Auth/Customer/SuspendedCannotTradeTest.php` — a stub route `/api/v1/customer/_trade_probe` guarded by `EnforceTradeAllowed` returns 403 `account_suspended` when the customer is suspended; 200 for a verified, non-suspended customer.
- [ ] T112 [P] [US5] `tests/Feature/Auth/Customer/SuspendCheckConstraintTest.php` — a raw insert setting `is_suspended=true` without `suspended_by` OR without `suspended_reason` throws a `QueryException` (DB CHECK `suspended_needs_actor`).
- [ ] T113 [P] [US5] `tests/Feature/Auth/Staff/SuspendCustomerActionTest.php` — a `ceo` or `coo` staff (holding `customer.suspend`) calls `POST /dashboard/customers/{id}/suspend` with `reason=policy_violation` → 200, customer suspended, `audit_log` row `auth.customer.suspended`; an `operations` staff (no permission) → 403 `permission_denied`.
- [ ] T114 [P] [US5] `tests/Feature/Auth/Staff/UnsuspendCustomerActionTest.php` — inverse.

### Implementation

- [ ] T115 [US5] `app/Actions/Auth/Suspension/SuspendCustomerAction.php` and `UnsuspendCustomerAction.php` — set `is_suspended`, `suspended_by=$ctx->staffId`, `suspended_reason`, `suspended_at`; audit-log. (skill: laravel-actions-services)
- [ ] T116 [US5] `app/Http/Requests/Auth/Staff/{SuspendCustomerRequest,UnsuspendCustomerRequest}.php` — validate reason in `SuspendedReason`.
- [ ] T117 [US5] `app/Http/Controllers/Api/V1/Dashboard/Customers/CustomerSuspensionController.php` — `suspend`, `unsuspend`. Route middleware includes `staff.permission:customer.suspend`. (skill: laravel-api-endpoints)
- [ ] T118 [US5] Add stub route `/api/v1/customer/_trade_probe` guarded by `EnforceTradeAllowed` used only by tests; keep it behind an `env('APP_ENV') === 'testing'` gate.
- [ ] T119 [US5] Wire dashboard suspension routes with OpenAPI attributes.
- [ ] T120 [US5] Run `./vendor/bin/pest --filter="Customer/Suspend|Staff/SuspendCustomer|Staff/UnsuspendCustomer"` — green.

---

## Phase 9 · US7 — Email verification primitive (Priority: P3)

**Goal**: a customer can set/change an email address and confirm it via a one-time link.

### Tests

- [ ] T121 [P] [US7] `tests/Feature/Auth/Customer/SetEmailTest.php` — `POST /auth/email` stores email as unverified and dispatches `EmailVerificationNotification`.
- [ ] T122 [P] [US7] `tests/Feature/Auth/Customer/ConfirmEmailTest.php` — token consumes; `email_verified_at` set; reused/expired token → 401.

### Implementation

- [ ] T123 [US7] `app/Actions/Auth/Customer/SetOrChangeEmailAction.php` + `ConfirmEmailAction.php`, reusing `OneTimeTokenService`. (skill: laravel-actions-services)
- [ ] T124 [US7] `app/Notifications/Customer/EmailVerificationNotification.php`. (skill: laravel-queues-notifications)
- [ ] T125 [US7] `app/Http/Controllers/Api/V1/Auth/CustomerEmailController.php` (`store`, `verify`). (skill: laravel-api-endpoints)
- [ ] T126 [US7] Wire routes: `POST /auth/email` (auth:sanctum), `POST /auth/email/verify` (public).
- [ ] T127 [US7] Run `./vendor/bin/pest --filter="Customer/(SetEmail|ConfirmEmail)"` — green.

---

## Phase 10 · US8 — Founder-security primitives (Priority: P3)

**Goal**: staff sign-in reads `account_freeze` and records device fingerprints so later founder-security features can plug in.

### Tests

- [ ] T128 [P] [US8] `tests/Feature/Auth/Staff/FrozenLoginRefusedTest.php` — open row in `account_freeze` for a `ceo` → sign-in refused with 403 `account_frozen`; `unfrozen_at` set → sign-in succeeds again.
- [ ] T129 [P] [US8] `tests/Feature/Auth/Staff/StaffDeviceFingerprintCapturedTest.php` — successful staff sign-in with a fresh `X-Device-Id` writes a row to `staff_device_fingerprint`; second sign-in with the same id updates `last_seen_at` but does not create a duplicate.

### Implementation

- [ ] T130 [US8] Extend `LoginStaffAction`: query `account_freeze` for an open row on the staff id; if present, refuse with `AuthErrorCode::AccountFrozen`. (skill: laravel-auth-authorization)
- [ ] T131 [US8] After successful staff sign-in, upsert `staff_device_fingerprint` on `(staff_id, fingerprint_hash)`. (skill: laravel-actions-services)
- [ ] T132 [US8] Also insert a `founder_device_approval` request row when a founder signs in from a new fingerprint (`requested_at` only; the approval workflow is out of scope).
- [ ] T133 [US8] Run `./vendor/bin/pest --filter="Staff/(FrozenLoginRefused|StaffDeviceFingerprintCaptured)"` — green.

**Checkpoint**: all eight user stories are independently green.

---

## Phase 11 · Polish & cross-cutting concerns

- [ ] T134 [P] Run `php artisan l5-swagger:generate`; commit `storage/api-docs/api-docs.json`; diff against `specs/001-auth-customer-staff/contracts/openapi.yaml` — any drift is a bug in either. (skill: laravel-api-endpoints, laravel-quality-gates) — **Partly done:** generation works (`composer swagger:generate`), and `OpenApiGenerationTest` asserts the generated operations equal the registered routes; the contract file marks planned operations `x-implemented: false`, so a full-file diff is expected to differ until those endpoints exist. Not wired into CI.
- [ ] T135 [P] Add `scripts/grep-secrets-in-openapi.sh` (Bash): `grep -E "password_hash|token_hash|otp_hash|mfa_secret|recovery_codes_hash" storage/api-docs/api-docs.json && exit 1 || exit 0`. Wire into `composer test`.
- [ ] T136 [P] Add `tests/Feature/Auth/Shared/AuditLogCoverageTest.php` — exhaustive test iterating over every `AuditEvent` enum case and asserting at least one code path in the auth surface produces it (uses `AuditLog::whereAction()->exists()` after running representative flows).
- [ ] T137 [P] Add `tests/Feature/Auth/Shared/EnvelopeConsistencyTest.php` — every auth endpoint returns either an `ApiResponse` envelope OR a 204 with empty body; no raw exceptions leak.
- [ ] T138 [P] Add `tests/Feature/Auth/Shared/EnumerationParityTest.php` — for each of `/auth/register`, `/auth/login`, `/auth/password/reset-request`, `/auth/otp/resend`, `/dashboard/auth/login`, `/dashboard/auth/password/reset-request`, assert response body + HTTP status are byte-identical between "identity exists" and "identity does not exist" cases.
- [ ] T139 Run `./vendor/bin/pint --test` — clean. (skill: laravel-quality-gates)
- [ ] T140 Run `php artisan migrate:fresh --seed` then `php artisan migrate:rollback --step=7` — every migration reverses cleanly. (skill: laravel-quality-gates)
- [ ] T141 Run the full suite: `./vendor/bin/pest` — green.
- [ ] T142 Walk `quickstart.md` end-to-end with `php artisan serve` — every listed shape matches.

---

## Phase 12 · Architecture audit remediation (2026-09-19)

**Purpose**: fix the findings of the authentication architecture audit without touching business modules. Every task below is implemented and covered by tests; earlier tasks whose description or status was wrong were corrected in place (T003, T004, T027, T033, T037–T041, T043–T045, T050, T055, T057–T061, T076, T078, T092–T098, T134).

- [X] T143 Customer / Dashboard separation (skill: laravel-auth-authorization, laravel-api-endpoints). Add the Sanctum `customer` guard over the `customers` provider in `config/auth.php`; move the customer surface to `/api/v1/customer/auth/*` on `auth:customer` (controller moved to `App\Http\Controllers\Api\V1\Customer\Auth\CustomerAuthController`, route names `api.v1.customer.auth.*`); dashboard routes stay on `auth:staff`; delete the generic `/api/v1/auth/*` routes and route names. Tests: `Shared/PrincipalIsolationTest.php` (Staff token → Customer API 401, Customer token → Dashboard API 401, removed routes 404, route-table audit).
- [X] T144 Remove `GET /api/v1/user` and every use of `auth:sanctum` from `routes/api.php` (`PrincipalIsolationTest` asserts no route uses it).
- [X] T145 Token abilities (skill: laravel-auth-authorization). `App\Enums\TokenAbility` (`customer:access`, `customer:refresh`, `staff:access`, `staff:refresh`); `IssueTokenFamilyAction` issues exactly one per token; `abilities` / `ability` middleware aliases registered in `bootstrap/app.php`; access routes require `<principal>:access`, refresh routes `<principal>:refresh`; a wrong-ability token is `403 forbidden` (renderer in `bootstrap/app.php`). Tests: `Shared/TokenAbilitiesTest.php`.
- [X] T146 Refresh endpoints and rotation (skill: laravel-actions-services, laravel-api-endpoints). `RotateRefreshTokenAction` (T039), `POST /customer/auth/refresh`, `POST /dashboard/auth/refresh`, `AuthApiException::refreshInvalid()`. Response `data` is the `Session`. Tests: `Shared/TokenAbilitiesTest.php`, `Customer/SessionFlowTest.php`.
- [X] T147 Rate-limit separation (skill: laravel-auth-authorization). New `auth.customer.register` limiter (`dahab-auth.rate_limits.customer_register`: 5/hour per phone, 10/hour per IP) so registration no longer consumes the login budget; identities normalised (trim + lower-case); identity buckets of the login limiters throw `AuthApiException::accountLocked($headers)` from their `->response()` callback; the `ThrottleRequestsException` renderer in `bootstrap/app.php` no longer matches URLs and always answers `too_many_requests`. Tests: `Shared/RateLimiterSeparationTest.php`, `Customer/RateLimitLoginTest.php`.
- [X] T148 OpenAPI (skill: laravel-api-endpoints). Attributes on every `CustomerAuthController` and `StaffAuthController` method; four security schemes (`customerBearer`, `customerRefreshBearer`, `dashboardBearer`, `dashboardRefreshBearer`), tags `Customer Auth` / `Dashboard Auth`, shared schemas (`CustomerProfile`, `StaffProfile`, `Session`, `ApiError`, `RegisterCustomerRequest`, `LoginCustomerRequest`) on `Controller` and the Resource/Request/DTO classes; the generic `sanctum` scheme is gone. `contracts/openapi.yaml` updated (paths, schemes, `x-implemented`). Tests: `Shared/OpenApiGenerationTest.php`.
- [X] T149 Argon2id (skill: laravel-auth-authorization). `config/hashing.php` (`driver = argon2id`, 64 MiB / 4 passes / 1 lane, `argon.verify = false` so legacy bcrypt verifies and is rehashed on sign-in); `.env.example` and `phpunit.xml` (low-cost test parameters). Tests: `Shared/PasswordHashingTest.php`.
- [X] T150 Test-suite alignment (skill: laravel-pest-testing). Existing tests moved to `/customer/auth/*` and `Sanctum::actingAs(..., ['customer:access'], 'customer')`; probe routes in the boundary/permission tests now use `auth:staff` + `abilities:staff:access`; `Tests\TestCase::bearer()` resets cached guard users between requests.
- [X] T151 Documentation alignment: `spec.md` (clarifications, FR-S-004, FR-X-002/003/004/007/008/010/011, SC-005/011/014–018, implementation status), `plan.md`, `research.md` (R-01, R-02, R-07, R-10–R-12), `data-model.md`, `quickstart.md`, `contracts/openapi.yaml`, `contracts/error-codes.md`, this file, `postman/`, `docs/Technical Spec/dahab-dashboard-authorization.md`, `docs/Technical Spec/dahab-spec-part2-api.md`, `docs/Database schema/05_schema_security.sql`, and the `laravel-auth-authorization`, `laravel-api-endpoints`, `laravel-pest-testing` skills.

**Checkpoint**: `./vendor/bin/pest` green; `composer swagger:generate` succeeds; `php artisan route:list --path=api/v1` shows every customer/dashboard route on its own guard + ability.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 · Setup** — no dependencies.
- **Phase 2 · Foundational** — depends on Phase 1. Blocks Phases 3–10.
- **Phase 3 (US1)** and **Phase 4 (US2)** — can proceed in parallel once Phase 2 is complete.
- **Phase 5 (US3)** — depends on Phase 3 (extends the customer login flow).
- **Phase 6 (US6)** — depends on Phase 3 + Phase 4 (needs an actor of either kind holding tokens).
- **Phase 7 (US4)** — depends on Phase 3 + Phase 4 (needs actors to reset).
- **Phase 8 (US5)** — depends on Phase 3 (customer + `/me`) and Phase 4 (staff acts on customer).
- **Phase 9 (US7)** — depends on Phase 3.
- **Phase 10 (US8)** — depends on Phase 4.
- **Phase 11 · Polish** — depends on every prior phase.

### Parallel opportunities

- Every task tagged `[P]` in Phase 2 is a different file (migrations, enums, models, factories, support classes) and may run in parallel.
- Tests inside each user-story phase are all `[P]` — write them together, then implement one Action at a time.
- Users stories US1/US2 may be pursued by different developers once foundational is done.
- Polish tasks T134–T138 are `[P]`.

### MVP scope

**Ship after Phase 3 only.** US1 by itself delivers register + login + me + logout — the minimum a customer needs to hold an account. Everything after that is additive.

---

## Notes

- Every task lists an exact file path. `/speckit-implement` MUST NOT invent paths.
- Every implementation task names a Dahab helper skill. `/speckit-implement` MUST call `Skill(<name>)` before starting each such task.
- Do NOT commit `storage/api-docs/*.json` until Phase 11; the file is regenerated then and reviewed against `contracts/openapi.yaml`.
- After every checkpoint (`Run pest --filter=…` task) the suite MUST pass before advancing.
- Migrations MUST be reversible (Constitution / Development Workflow); T140 proves it.
