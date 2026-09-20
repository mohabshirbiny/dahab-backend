# Phase 1 · Data Model — Authentication (Customer & Dashboard Staff)

> **Source of truth**: `docs/Database schema/02_schema_identity.sql` and `docs/Database schema/05_schema_security.sql`.
> This document names every column this feature reads or writes, and calls out **new** tables/columns that this feature must add. Every new object requires a matching Laravel migration and a matching row in the amendment PR to `docs/Database schema/` if the schema evolves (Constitution Principle III).

All identifiers are Postgres. `search_path = dahab, public`. All timestamps are `TIMESTAMPTZ`. UUIDs are `gen_random_uuid()`. Enum types match the applied schema exactly.

---

## Existing tables (mirrored from `docs/Database schema/`)

### `staff` (from 02_schema_identity.sql §4)

| Column | Type | Notes |
|---|---|---|
| `staff_id` | UUID PK | `DEFAULT gen_random_uuid()` |
| `role` | `staff_role` enum | One of `ceo | coo | finance | operations | verification | igi_branch` |
| `full_name` | TEXT | |
| `email` | CITEXT UNIQUE | Sign-in identity |
| `phone` | TEXT | Optional |
| `is_active` | BOOLEAN NOT NULL DEFAULT TRUE | **Canonical "enabled" flag.** The spec's "disabled" language maps to `is_active = false`. |
| `branch_id` | SMALLINT REFERENCES `branch` | Only for `igi_branch` role (CHECK `igi_has_branch`) |
| `created_by`, `created_at` | | audit fields |

### `customer` (from 02_schema_identity.sql §5)

| Column | Type | Notes |
|---|---|---|
| `customer_id` | UUID PK | |
| `display_ref` | TEXT UNIQUE | Public reference (e.g. `"4417"`) |
| `phone` | TEXT UNIQUE | Sign-in identity |
| `email` | CITEXT UNIQUE nullable | |
| `full_name` | TEXT nullable | Populated on verification (Part 3, out of scope) |
| `preferred_lang` | TEXT default `'ar'` CHECK IN (`ar`, `en`) | |
| `is_verified` | BOOLEAN default FALSE | Identity KYC — this feature READS only |
| `is_suspended` | BOOLEAN default FALSE | This feature READS + WRITES via dashboard endpoints |
| `suspended_reason`, `suspended_by`, `suspended_at` | | CHECK `suspended_needs_actor` |
| `created_at` | | |

### `account_freeze` (from 02_schema_identity.sql)

| Column | Type | Notes |
|---|---|---|
| `freeze_id` | UUID PK | |
| `frozen_staff_id` | UUID FK → staff | |
| `frozen_by` | UUID FK → staff | CHECK `no_self_freeze` |
| `frozen_at` | | |
| `unfreeze_confirm_1`, `unfreeze_confirm_2` | UUID FK nullable | requires **both** founders to unfreeze |
| `unfrozen_at` | nullable | |

**This feature reads** `account_freeze` on staff sign-in: sign-in is refused with `account_frozen` when there exists a row with `frozen_staff_id = staff.staff_id AND unfrozen_at IS NULL`. Writes to `account_freeze` (freeze / confirm-unfreeze) are out of scope; they belong to the founder-security workflow.

### `founder_device_approval` (from 02_schema_identity.sql)

| Column | Type | Notes |
|---|---|---|
| `approval_id` | UUID PK | |
| `staff_id` | UUID FK → staff | |
| `device_fingerprint` | TEXT | |
| `requested_at`, `approved_by`, `approved_at` | | CHECK `approver_is_not_self` |

**This feature writes** a `requested_at` row on every staff sign-in from a fingerprint not previously seen for that staff row (INSERT if not exists). The approval workflow itself is out of scope.

### `audit_log` (from 05_schema_security.sql §13)

| Column | Type | Notes |
|---|---|---|
| `audit_id` | BIGSERIAL PK | |
| `actor_staff_id` | UUID FK → staff nullable | |
| `actor_customer_id` | UUID FK → customer nullable | CHECK `audit_has_actor` — at least one is non-null |
| `action` | TEXT | e.g. `auth.customer.sign_in`, `auth.staff.sign_in_failed`, `auth.otp.sent` |
| `entity_type`, `entity_id` | | |
| `before_json`, `after_json`, `reason` | JSONB / TEXT | |
| `ip_address` | INET | |
| `device_fingerprint` | TEXT | |
| `created_at` | | |

**This feature writes** `audit_log` rows synchronously in the same transaction as every state change (R-08 in `research.md`). Never queued. The `audit_no_update` trigger enforces append-only at the engine.

---

## New tables (this feature adds; also amends `docs/Database schema/`)

### `staff_password` — password credential for staff

| Column | Type | Notes |
|---|---|---|
| `staff_id` | UUID PK FK → staff | 1:1 with staff |
| `password_hash` | TEXT NOT NULL | Argon2id string; bcrypt accepted only on legacy migration |
| `password_changed_at` | TIMESTAMPTZ NOT NULL | |
| `force_reenroll_mfa_at` | TIMESTAMPTZ | Set on founder password reset; consumed on next sign-in |

**Why a separate table**: The applied schema deliberately excludes credentials from `staff` — this keeps the staff row itself readable by dashboards without exposing hash existence. RLS on `staff_password` restricts SELECT to the actor themselves plus the sign-in service role.

### `customer_password` — password credential for customer

| Column | Type | Notes |
|---|---|---|
| `customer_id` | UUID PK FK → customer | |
| `password_hash` | TEXT NOT NULL | Argon2id |
| `password_changed_at` | TIMESTAMPTZ NOT NULL | |

### `staff_mfa` — TOTP secret for staff

| Column | Type | Notes |
|---|---|---|
| `staff_id` | UUID PK FK → staff | |
| `mfa_secret_encrypted` | TEXT NOT NULL | encrypted-at-rest (Laravel cast) |
| `enrolled_at` | TIMESTAMPTZ NOT NULL | |
| `recovery_codes_hash` | JSONB | Array of Argon2id hashes, single-use |

Row exists only after enrollment. `ceo`, `coo`, `finance` roles: absence of a row prevents sign-in (forces enrollment first sign-in).

### Spatie roles & permissions — dashboard authorization (reference data)

Replaces the earlier custom `staff_permission` table. Tables are the standard `spatie/laravel-permission` set — `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` — with `guard_name = 'staff'` and `model_id` typed `uuid` (matches `staff.staff_id`). Only `Staff` uses `HasRoles`; `Customer` never does.

| Item | Value |
|---|---|
| Roles | the six `StaffRole` values, one Spatie role each |
| Permissions | only those the current spec needs — `customer.suspend` (roles `ceo`, `coo`) |
| Canonical map | `App\Enums\StaffPermission::roles()`, applied by `DashboardRolesAndPermissionsSeeder` (idempotent) |

Adding a permission is an enum + seeder change — never a runtime write. Behaviour and operations: `docs/Technical Spec/dahab-dashboard-authorization.md`.

### `customer_trusted_device` — fingerprints trusted for a customer

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `customer_id` | UUID FK → customer NOT NULL | |
| `fingerprint_hash` | TEXT NOT NULL | sha256 of `(X-Device-Id, platform)` |
| `first_seen_at`, `last_seen_at` | | |
| `UNIQUE (customer_id, fingerprint_hash)` | | |

Written on successful OTP verify for that fingerprint (R-04).

### `staff_device_fingerprint` — fingerprints seen for staff

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `staff_id` | UUID FK → staff NOT NULL | |
| `fingerprint_hash` | TEXT NOT NULL | |
| `first_seen_at`, `last_seen_at` | | |
| `UNIQUE (staff_id, fingerprint_hash)` | | |

Written on every staff sign-in. Read by later founder-security features.

### `one_time_token` — password reset and email verification

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `token_hash` | TEXT NOT NULL UNIQUE | sha256 of the signed token payload |
| `purpose` | TEXT NOT NULL CHECK IN (`password_reset_customer`, `password_reset_staff`, `email_verification`) | |
| `actor_customer_id` | UUID FK → customer nullable | |
| `actor_staff_id` | UUID FK → staff nullable | |
| `payload` | JSONB nullable | e.g. new email for email verification |
| `expires_at` | TIMESTAMPTZ NOT NULL | |
| `consumed_at` | TIMESTAMPTZ nullable | |
| `CHECK` | `(actor_customer_id IS NOT NULL) <> (actor_staff_id IS NOT NULL)` | exactly one actor |

### Extension to `personal_access_tokens` (Sanctum-owned)

Add via a new migration (`ALTER TABLE`):

| Added column | Type | Notes |
|---|---|---|
| `family_id` | UUID nullable, indexed | groups access + refresh tokens issued in one sign-in |
| `rotated_at` | TIMESTAMPTZ nullable | set when a refresh token is exchanged; used to detect replay |
| `actor_kind` | TEXT CHECK IN (`customer`, `staff`) NOT NULL DEFAULT `customer` | disambiguates `tokenable_type` at the API layer |

Each row also carries exactly one ability (Sanctum `abilities` column):

| Token | `abilities` | `expires_at` |
|---|---|---|
| Customer access | `["customer:access"]` | now + `dahab-auth.access_ttl_minutes` |
| Customer refresh | `["customer:refresh"]` | now + `dahab-auth.refresh_ttl_days` |
| Staff access | `["staff:access"]` | now + `dahab-auth.access_ttl_minutes` |
| Staff refresh | `["staff:refresh"]` | now + `dahab-auth.refresh_ttl_days` |

The wildcard `*` is never issued. `tokenable_type` is `App\Models\Customer` or `App\Models\Staff`; the `customer` / `staff` guards only resolve tokens owned by their own model.

Rotation atomically claims the presented refresh row (`UPDATE ... SET rotated_at = now() WHERE id = ? AND rotated_at IS NULL`), deletes the family's other rows (the previous access token), and mints a new pair with the same `family_id`; the rotated refresh row stays so a replay is recognisable. A refresh replay is a request whose token has `rotated_at IS NOT NULL` (the claim updates 0 rows): the response is `401 refresh_invalid` and every row with that `family_id` is **deleted**. Revocation deletes rows (that is what makes Sanctum refuse the token); `revoked_at` exists in the schema but is not written by the current code.

---

## Relationships (feature-scope diagram)

```
customer                                       staff
  │ 1                                            │ 1
  ├─── 1..1 customer_password                    ├─── 1..1 staff_password
  ├─── 0..1 email + email_verified_at            ├─── 0..1 staff_mfa
  ├─── 0..N customer_trusted_device              ├─── 0..N staff_device_fingerprint
  ├─── 0..N personal_access_tokens (customer)    ├─── 0..N personal_access_tokens (staff)
  ├─── 0..N one_time_token (password_reset_customer, email_verification)
  ├─── 0..N audit_log (actor_customer_id)        ├─── 0..N audit_log (actor_staff_id)
  ├─── suspended_by ─────────────────────────────►
                                                 ├─── 0..N account_freeze (frozen_staff_id, frozen_by)
                                                 └─── 0..N founder_device_approval
                                                 
                                        Spatie: roles / permissions (guard staff)
                                            model_has_roles, role_has_permissions (reference data)
```

## State transitions

### Customer sign-in

```
[fresh request] --phone+password valid------------------→ [device known?]
                --phone+password invalid------→ 401 invalid_credentials, audit=failure
[device known?] --yes--→ issue token family --→ audit=success, RETURN tokens
                --no --→ create OTP challenge --→ send SMS --→ audit=otp_sent, RETURN otp_required(challenge_id)
[OTP verify] --code valid, within TTL, verify_attempts ≤ 5--→ mark device trusted → issue token family → RETURN tokens
             --code invalid------------→ verify_attempts++; if > 5 void challenge → 401 otp_invalid
             --TTL expired--------------→ 401 otp_expired
```

### Staff sign-in

```
[fresh request] --email+password valid AND is_active AND NOT frozen-→ [MFA required?]
                --invalid credentials OR is_active=false----→ 401 invalid_credentials
                --active freeze row exists-------------------→ 403 account_frozen
[MFA required?] --no --→ issue token family → RETURN tokens
                --yes AND enrolled--→ hold session → await totp code
                --yes AND NOT enrolled--→ RETURN mfa_enrollment_required (enroll flow)
[TOTP verify] --valid------→ issue token family → RETURN tokens
              --invalid----→ 401 mfa_invalid
```

### Refresh

```
[refresh request with refresh_token]
  --guard: auth:<principal> + abilities:<principal>:refresh (else 401 / 403)
  --token.rotated_at IS NULL--→ rotate: mint new access+refresh with same family_id, mark old rotated_at=now, delete the family's old access token → RETURN new pair
  --token.rotated_at IS NOT NULL (replay)--→ revoke whole family (delete every row with that family_id) → 401 refresh_invalid
```

### Password reset

```
[request-reset]
  --identity present--→ create one_time_token(purpose=password_reset_*, expires_at=+1h) → notify (SMS/email)
  --identity absent---→ NO-OP but SAME response shape (enumeration protection)

[submit-reset (token, new_password)]
  --token exists AND consumed_at IS NULL AND expires_at > now--→ update password_hash → mark consumed_at=now → revoke every token family for actor → for CEO/COO on staff reset: set staff_password.force_reenroll_mfa_at=now → audit=success
  --any other case-----------------------------------------→ 401 token_invalid
```

## Validation summary — mapped to FR-*

| FR | Enforcement |
|---|---|
| FR-C-001 | Migration: `customer_password.password_hash NOT NULL`. FormRequest `RegisterCustomer` uses `Password::defaults()`. |
| FR-C-002 | FormRequest `LoginCustomer` requires both `phone` and `password`. |
| FR-C-003 | `IssueTokenFamilyAction` (abilities `<principal>:access` / `<principal>:refresh`) + `RotateRefreshTokenAction`; config `dahab-auth.access_ttl_minutes`, `refresh_ttl_days`. |
| FR-C-004 / FR-C-005 | Middleware reads `X-Device-Id`, hashes with platform; matches `customer_trusted_device.fingerprint_hash`. |
| FR-C-006 | `OtpChallengeService` on Redis; TTL/attempt bounds in `dahab-auth.otp.*`. |
| FR-C-007 | `CustomerResource` returns derived `trade_allowed`. Password fields hidden by resource and by model `$hidden`. |
| FR-C-008 | `LogoutCustomerAction::current()` / `::all()` delete every row in the family / for the actor via `RevokeTokenFamilyAction`. |
| FR-C-009 | `one_time_token(purpose=password_reset_customer)` + `ResetPasswordAction` revokes all token families. |
| FR-C-010 | `one_time_token(purpose=email_verification)` on email set/change; `ConfirmEmailAction` writes `email_verified_at`. |
| FR-C-011 | `EnforceTradeAllowed` middleware reads `is_suspended` + `is_verified` from the request-context customer row. |
| FR-C-012 | Enforced by DB CHECK `customer.suspended_needs_actor`. |
| FR-C-013 | Every enumeration-sensitive endpoint returns a stable `{ ok: true }`-shaped envelope; no HTTP status divergence. |
| FR-S-001 | Postgres enum `staff_role` mirrored in `App\Enums\StaffRole`. |
| FR-S-002 | FormRequest `LoginStaff` requires `email` + `password`. |
| FR-S-003 | `LoginStaffAction` refuses when role ∈ `{ceo, coo, finance}` and `staff_mfa` row absent. |
| FR-S-004 | Named limiter `auth.staff.login` — 5/30 min per email (`account_locked`) + 20/min per IP (`too_many_requests`). |
| FR-S-005 | `LoginStaffAction` refuses when `staff.is_active = false`; same generic `invalid_credentials` response. |
| FR-S-006 | `LoginStaffAction` refuses when `account_freeze` open row exists; response `account_frozen`. |
| FR-S-007 | `StaffResource` lists `roles` and effective `permissions` from Spatie (`getRoleNames()`, `getAllPermissions()`). |
| FR-S-008 | Same primitives as customer, on `StaffAuthController`. |
| FR-S-009 | Founder-role reset sets `force_reenroll_mfa_at`. |
| FR-S-010 | `EnforceStaffPermission` (`staff.permission:<code>`) delegates to Spatie `$staff->can()`; denial → `permission_denied` |
| FR-S-011 | `SuspendCustomerAction` and `UnsuspendCustomerAction` under `/dashboard/customers/{id}/suspend|unsuspend`. |
| FR-S-012 | `RecordAuditLogAction` invoked from every sign-in code path. |
| FR-X-001 | `SetRequestContext` middleware; `RequestContext` value object holds exactly one actor. |
| FR-X-002 | Routes under `/api/v1/customer/auth/*` and `/api/v1/dashboard/auth/*`; `ApiResponse` envelope used throughout. |
| FR-X-003 | `App\Enums\AuthErrorCode` is the sole source of error strings; grep in CI blocks free-form strings. |
| FR-X-004 | `AppServiceProvider::boot()` registers one limiter per concern (register, customer login, staff login, OTP send/verify, reset, refresh) per R-07; identity lockouts throw `AuthApiException::accountLocked()`. |
| FR-X-005 | Feature test `Shared/AuditLogTest.php` asserts one row per event kind. |
| FR-X-006 | `Sensitive::class` cast on password/token/otp fields; static grep of `openapi.yaml` and JSON responses forbids the strings `password_hash`, `token_hash`, `otp_hash`, `mfa_secret`. |
| FR-X-007 | `config/hashing.php` (`driver = argon2id`, `argon.verify = false`); `Hash::needsRehash()` on a successful bcrypt sign-in triggers `Hash::make()` in the same request. |
| FR-X-008 | OpenAPI attributes on each controller method; four security schemes on `Controller`; `OpenApiGenerationTest` (and `l5-swagger:generate` in CI, T134). |
| FR-X-010 | `customer` and `staff` Sanctum guards in `config/auth.php`; `auth:customer` / `auth:staff` on every route; `PrincipalIsolationTest`. |
| FR-X-011 | `App\Enums\TokenAbility`; `abilities:<principal>:access` / `abilities:<principal>:refresh` middleware alias in `bootstrap/app.php`; `TokenAbilitiesTest`. |
| FR-X-009 | `Password::defaults()` in `AppServiceProvider` per R-05. |

## Deviations from `docs/Database schema/`

The following are **new** relative to the applied schema and MUST land as an amendment to `docs/Database schema/`:

1. `staff_password`, `customer_password` tables (credentials were absent).
2. `staff_mfa` table.
3. Spatie `roles` / `permissions` / `model_has_roles` / `model_has_permissions` / `role_has_permissions` tables (docs treated the mapping as external reference data; it is now Spatie-managed — an amendment).
4. `customer_trusted_device`, `staff_device_fingerprint` (docs mention "device fingerprint" but do not table it).
5. `one_time_token` table for password reset + email verification.
6. `personal_access_tokens` extensions (`family_id`, `rotated_at`, `actor_kind`).

All six are documented in this data-model; the tasks phase adds a `docs/` amendment task.

## Spec ↔ schema terminology reconciliation

| Spec term | Applied-schema term |
|---|---|
| `is_disabled = true` (staff) | `is_active = false` |
| `is_frozen = true` (staff) | An open row in `account_freeze` for that `staff_id` (i.e. `unfrozen_at IS NULL`) |

`FR-S-005` and `FR-S-006` remain semantically correct; controllers and Actions read the applied-schema shape, not the spec's shorthand. The spec will be corrected in `/speckit-analyze` if desired; for now the mapping above is authoritative.
