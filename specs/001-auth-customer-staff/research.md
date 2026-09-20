# Phase 0 · Research — Authentication (Customer & Dashboard Staff)

Every item below closes a technology decision that the spec deliberately left implementation-neutral, or that the docs left silent. Each decision resolves what would otherwise be a `NEEDS CLARIFICATION` at plan time.

---

### R-01 · Session model on Sanctum

**Decision**: Use Sanctum personal-access tokens for the *access token* (short-lived, ~15 min TTL). Represent the *refresh token* as a second Sanctum token issued alongside, with a distinct ability (`<principal>:refresh` — only this token may hit the refresh endpoint; the access token carries `<principal>:access`, see R-11), and a `family_id` linking every access + refresh token issued in the same login. Rotation on refresh mints a new pair with the same `family_id`; replay of a rotated refresh token revokes the whole family and forces re-login.

**Rationale**: Sanctum already ships the storage, the middleware, and the ability system. Introducing a JWT layer just for refresh would be a second parallel token store — twice the surface for token theft and twice the code to maintain. Ability-tagged Sanctum tokens are a widely used pattern for this exact split.

**Alternatives considered**:
- *Pure Sanctum session cookies*: rejected because the customer clients are native mobile apps and Sanctum's stateful cookie flow is for first-party SPAs.
- *JWT (tymon or php-open-source-saver)*: rejected because it introduces its own key management and revocation store while duplicating Sanctum's token store.

**Implementation notes**:
- Access-token TTL configurable via `DAHAB_AUTH_ACCESS_TTL_MINUTES` (default 15), applied as each token's `expires_at`. (`SANCTUM_TOKEN_EXPIRATION_MINUTES` is not read by the code and was removed from `.env.example`; `config/sanctum.php` keeps `expiration => null`.)
- Refresh TTL configurable via `DAHAB_AUTH_REFRESH_TTL_DAYS` (default 30).
- `personal_access_tokens` gains a `family_id UUID` and a `rotated_at TIMESTAMP NULL` — added by our first auth migration, not by editing Sanctum's own migration.

---

### R-02 · OTP storage and rate-limit backing

**Decision**: OTP challenges live in Redis, keyed by a random `challenge_id` (opaque to the caller), storing `{ customer_id, phone, hash, expires_at, verify_attempts, sent_at }`. The 6-digit code is hashed with `hash('sha256', $code)` (fast, one-way, and adequate — OTPs are short-lived, single-use, and rate-limited on verify). Rate-limiter buckets ride on Laravel's `RateLimiter` (also Redis) with named limiters `auth.otp.send`, `auth.otp.verify`, `auth.customer.register`, `auth.customer.login`, `auth.staff.login`, `auth.password_reset.request`, `auth.refresh`.

**Rationale**: OTP data is ephemeral, and Redis TTLs are exactly the right primitive. Hashing prevents an operator with a Redis dump from reading live codes. Argon2id or bcrypt is overkill for a 6-digit, 5-minute, single-use secret — SHA-256 is chosen deliberately.

**Alternatives considered**:
- *Store OTPs in Postgres*: rejected — adds a hot write to every OTP send, and TTL cleanup becomes a scheduled job we don't need.
- *Encrypt OTPs with Laravel Crypt*: rejected — reversible storage is worse than hashing when the plaintext is short-lived and single-use.

---

### R-03 · TOTP for staff MFA

**Decision**: Add `pragmarx/google2fa` (^8.0) for TOTP secret generation and code verification. Store the shared secret encrypted at rest via Laravel's `encrypted` cast on `staff.mfa_secret_encrypted`. Enrollment returns a `otpauth://` URI and a set of one-time recovery codes; the recovery codes are hashed with Argon2id on save.

**Rationale**: `pragmarx/google2fa` is the canonical, unmaintained-in-a-good-way TOTP library for Laravel — small, no runtime deps. Encrypted-at-rest secrets fold into Laravel's key rotation.

**Alternatives considered**:
- *WebAuthn*: correct long term but out of scope for this feature and requires a client story we do not yet have.
- *Twilio Authy*: rejected — vendor lock-in, external dependency for an internal MFA.

---

### R-04 · Device fingerprints (customer + staff)

**Decision**: The client submits an opaque `device_id` header (`X-Device-Id`) — a 128-bit random value the app persists to secure storage on first run. On the server we combine that with a normalized `User-Agent` category (bucketed) and a hash of the mobile platform id when supplied (`X-Device-Platform`, one of `ios | android | web`) into a *fingerprint hash* stored in `customer_trusted_device.fingerprint_hash`. Absent `X-Device-Id`, the request is treated as a new-device sign-in — every time.

**Rationale**: A canonical device id supplied by the client is the only reliable signal on both mobile and web. UA-based heuristics alone flip the "known device" bit on trivial browser updates. Requiring the header degrades gracefully into an OTP loop instead of silently trusting a stranger.

**Alternatives considered**:
- *FingerprintJS server SDK*: rejected — third-party, license cost, adds a network dependency to every sign-in.
- *IP + UA*: rejected — customer's IP changes on every LTE handoff.

---

### R-05 · Password policy enforcement

**Decision**: Use `Password::defaults()` on the two FormRequests that set a password (registration, password reset). In `AppServiceProvider::boot()`:

```php
Password::defaults(fn () => app()->environment('testing')
    ? Password::min(10)                                 // fast in CI
    : Password::min(10)->uncompromised());              // hits pwned-passwords in real envs
```

**Rationale**: This is the exact NIST 800-63B posture the clarify session picked. `->uncompromised()` calls the k-anonymity Pwned Passwords API; skipping it in `testing` keeps the suite offline.

**Alternatives considered**:
- *Complexity classes*: rejected in clarify.
- *Rolled own regex*: rejected — reinventing the wheel with worse UX.

---

### R-06 · Row-level security (RLS) wiring

**Decision**: Migrations enable RLS on `customer`, `wallet` (schema present per docs, table not created here), and any downstream tables introduced by future features. The `SetDatabaseActor` middleware executes, at the top of each request transaction:

```sql
SET LOCAL app.current_customer_id = :cid;
SET LOCAL app.current_staff_id    = :sid;
```

RLS policies on `customer` (per `docs/Database schema/05_schema_security.sql`) check `current_setting('app.current_customer_id')::uuid = customer.id` for self-reads, and `current_setting('app.current_staff_id')::uuid IS NOT NULL` (plus role-grant) for staff reads. The Laravel connection MUST run under a Postgres role that is subject to those policies — a new role `dahab_app` is provisioned by the first migration and is not a superuser.

**Rationale**: The Constitution and Part 1 both require the engine to enforce isolation. Setting the local variable per request transaction is the standard Postgres RLS pattern.

**Alternatives considered**:
- *Global scope on Eloquent*: rejected — Constitution Principle II says the engine, not code.
- *Row-level views*: rejected — extra objects to maintain, no better guarantee than RLS.

---

### R-07 · Rate limiter naming and keys

**Decision**: Named limiters registered in `AppServiceProvider::boot()`:

| Limiter | Key | Limit |
|---|---|---|
| `auth.customer.register` | `phone` (or IP if phone absent) | 5/hour per identity + 10/hour per IP |
| `auth.customer.login` | `phone` (or IP if phone absent) | 5/15 min per identity (→ `account_locked`) + 20/min per IP (→ `too_many_requests`) |
| `auth.staff.login` (dashboard login) | `email` (or IP if email absent) | 5/30 min per identity (→ `account_locked`) + 20/min per IP (→ `too_many_requests`) |
| `auth.otp.send` | `phone` | 1 per 60 s + 5 per hour |
| `auth.otp.verify` | `challenge_id` | 5 per challenge (challenge is voided after) |
| `auth.password_reset.request` | `identity` | 3/hour per identity + 20/hour per IP |
| `auth.refresh` | `family_id` | 60/hour |

**Rationale**: Naming maps 1:1 to a `RateLimiter::for()` call and reads cleanly in tests (`Cache::spy()` → assert `hits()`). Keying by identity plus IP is the standard defense against distributed credential stuffing.

**Separation rule (2026-09-19 audit)**: one limiter per concern, never shared. Laravel hashes the limiter *name* into the bucket key, so two routes on the same named limiter share a budget — registration used to run on `auth.customer.login`, which let sign-ups consume a phone's login attempts. Registration now has `auth.customer.register`.

**429 code selection**: the identity limit of each login limiter carries its own `->response()` callback (`AppServiceProvider::lockout()`) that throws `AuthApiException::accountLocked($headers)`, rendered centrally with `Retry-After`. Every other limit falls through to the generic `ThrottleRequestsException` renderer in `bootstrap/app.php` → `too_many_requests`. Nothing inspects the URL. Identities are trimmed and lower-cased before they become bucket keys.

**Alternatives considered**:
- *One global auth limiter*: rejected — impossible to tune per surface.
- *nginx / edge limiter*: possible in production but not sufficient (needs identity-awareness).

---

### R-08 · Audit log write pattern

**Decision**: A single Action `RecordAuditLogAction` accepts an `AuditEvent` enum, an outcome (`success | failure`), and a JSON `payload`, and writes a row to `audit_log` with the current `RequestContext`'s actor, IP, and UA. Called synchronously inside the same transaction as the state change it describes (so a rollback removes both). Never queued.

**Rationale**: Audit rows that live in a queued job are worse than useless — they arrive out of order and can vanish silently. Same transaction, same commit.

**Alternatives considered**:
- *Model observers*: rejected — observers can't see the request context, and pushing context into a static bag is a footgun.
- *Queued audit events*: rejected as above.

---

### R-09 · Testing strategy for outbound notifications

**Decision**: `Notification::fake()` in every feature test that triggers an OTP, a password reset, or an email confirmation. Tests assert the notification was queued to the right notifiable with the expected payload. The real `Notifications` channel (Vonage/Twilio for SMS, mail driver for email) is configured but never invoked in the test suite.

**Rationale**: Matches Constitution Principle V (test the boundary) — the boundary is "was the notification queued", not "did the SMS gateway respond".

**Alternatives considered**:
- *Real gateway in tests*: rejected — cost, flakiness, credentials in CI.

---

### R-10 · OpenAPI generation

**Decision**: OpenAPI 3 attributes on each controller method (`#[OA\Post]`, `#[OA\Response]`, etc.), plus a shared `#[OA\Schema]` on each API Resource and Request. `L5_SWAGGER_GENERATE_ALWAYS=true` in local; a CI step runs `php artisan l5-swagger:generate` and fails if the generated JSON is not committed / matches.

**Rationale**: OpenAPI attributes co-located with the endpoint means the contract cannot drift from the code silently. Failing CI on uncommitted regeneration keeps `contracts/openapi.yaml` honest.

**Alternatives considered**:
- *Hand-written OpenAPI YAML*: rejected — always drifts.
- *Stoplight Elements / stoplight studio*: nice to have but doesn't change the source-of-truth question.

**Update (2026-09-19 audit)**: the two API surfaces are documented with separate security schemes declared on `App\Http\Controllers\Controller` — `customerBearer`, `customerRefreshBearer`, `dashboardBearer`, `dashboardRefreshBearer` — and tagged `Customer Auth` / `Dashboard Auth`. There is no generic `sanctum` scheme. Shared schemas (`CustomerProfile`, `StaffProfile`, `Session`, `ApiError`, the register/login request bodies) sit on the Resource / Request / DTO classes. `tests/Feature/Auth/Shared/OpenApiGenerationTest.php` generates the document into a temp dir and asserts the operation set, the per-operation scheme, the schema set, the absence of credential fields, and parity with the registered routes. "Fails CI if not committed" is still a CI wiring task (T134), not something the repo enforces yet.

---

### R-11 · Principal isolation: guards and token abilities

**Decision**: Two Sanctum guards in `config/auth.php` — `customer` (provider `customers` → `Customer`) and `staff` (provider `staff` → `Staff`). Customer routes are `auth:customer`, dashboard routes are `auth:staff`; no route uses `auth:sanctum`. Every token carries exactly one ability from `App\Enums\TokenAbility` — `customer:access`, `customer:refresh`, `staff:access`, `staff:refresh` — never `*`. Routes add Sanctum's `abilities:<ability>` middleware after the guard (`abilities` / `ability` aliases in `bootstrap/app.php`). Refresh rotation and replay detection live in `App\Actions\Auth\Shared\RotateRefreshTokenAction`, shared by both principals; access + refresh tokens are minted only by `IssueTokenFamilyAction`.

**Rationale**: Sanctum's guard rejects a token whose owner is not an instance of the guard's provider model, so a Staff token cannot authenticate on `auth:customer` (401) and vice versa — isolation comes from the guard, not from a role check. Abilities then separate the two token *kinds* within a principal: a refresh token authenticates on the right guard but fails `abilities:<kind>:access` with 403, so it can never act as an access token. Spatie stays on `Staff` only (guard name `staff`); Customers hold no roles or permissions.

**Alternatives considered**:
- *One `sanctum` guard plus ability prefixes only*: rejected — isolation would rest on every route remembering the right ability; a route that forgot it would silently accept the other principal.
- *Separate token tables per principal*: rejected — a second token store for no gain over the provider check.
- *Custom exact-match ability middleware*: rejected — Sanctum's `abilities:` treats a `*` token as matching everything, but no code path mints `*` (asserted in `TokenAbilitiesTest`), and staying on the stock middleware keeps `Sanctum::actingAs()` usable in tests.

**Error mapping**: wrong principal → `401 unauthenticated`; wrong ability → `403 forbidden` (Sanctum's `MissingAbilityException`, rendered in `bootstrap/app.php`).

---

### R-12 · Password hashing (Argon2id)

**Decision**: `config/hashing.php` sets `driver` to `argon2id` (`HASH_DRIVER`), 64 MiB / 4 passes / 1 lane by default (`ARGON_MEMORY`, `ARGON_TIME`, `ARGON_THREADS`), and `argon.verify` to `false`. `phpunit.xml` lowers the cost so the suite stays fast. Every `Hash::make()` in the application — registration, staff factory, seeders — therefore produces Argon2id.

**Rationale**: `argon.verify = false` lets `Hash::check()` fall back to `password_verify()`, so a legacy bcrypt hash still verifies (FR-X-007); `Hash::needsRehash()` is true for it and the sign-in action rewrites it as Argon2id.

**Alternatives considered**:
- *`Hash::driver('argon2id')` at each call site*: rejected — one forgotten call site silently stores bcrypt; the default driver is the single switch.
- *`verify = true`*: rejected — it makes Argon2id reject bcrypt hashes with an exception, breaking legacy sign-in.

---

### Resolved unknowns

- **Refresh token TTL** — 30 days (clarify session).
- **OTP TTL / attempts / cooldown** — 5 min / 5 verifies / 60 s send cooldown, 5 sends/hour (clarify session).
- **Password policy** — min 10 chars + `uncompromised()` outside test env (clarify session).
- **Customer login rate limit** — 5/15 min + 20/min per IP (clarify session).
- **Founder-role MFA re-enrollment on reset** — `ceo` and `coo` only (Assumption A-09; revisitable in `/speckit-analyze`).
- **Session model** — R-01.
- **OTP storage** — R-02.
- **TOTP library** — R-03.
- **Device fingerprint format** — R-04.
- **Password policy plumbing** — R-05.
- **RLS wiring** — R-06.
- **Rate-limiter map** — R-07.
- **Audit log write pattern** — R-08.
- **Test doubles for notifications** — R-09.
- **OpenAPI generation** — R-10.
- **Guards and token abilities** — R-11.
- **Argon2id configuration** — R-12.

No `NEEDS CLARIFICATION` remains.
