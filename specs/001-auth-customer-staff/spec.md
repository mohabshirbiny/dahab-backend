# Feature Specification: Authentication — Customer & Dashboard Staff

**Feature Directory**: `specs/001-auth-customer-staff/`

**Created**: 2026-09-19

**Status**: In progress — see [Implementation status](#implementation-status) for what is built, and [tasks.md](./tasks.md) for the task-level truth. Aligned with the code after the 2026-09-19 architecture audit.

**Source of truth**: [`docs/Technical Spec/dahab-spec-part1-auth.md`](../../docs/Technical%20Spec/dahab-spec-part1-auth.md), plus the applied schema in `docs/Database schema/02_schema_identity.sql` and `05_schema_security.sql`.

**Input**: User description: "Authentication for the Dahab Backend covering two logically separated principals — customers and dashboard staff — on Laravel Sanctum, per Part 1 of the technical spec."

## Clarifications

### Session 2026-09-19

- Q: What are the customer login rate-limit defaults? (Part 1 §2.3 explicitly calls this an open item.) → A: 5 per 15 min per identity + 20 per minute per IP.
- Q: What are the OTP TTL, max verify attempts, and send cooldown? (Part 1 §2.3 says "short-lived hash in a rate-limited cache with a TTL" but never specifies numbers.) → A: TTL 5 minutes, max 5 verify attempts before challenge is voided, 60 s send cooldown per phone, cap 5 sends per hour.
- Q: What password policy applies to both customers and staff? → A: Minimum 10 characters, must not appear in known-breach corpora (Laravel `Password::defaults()->uncompromised()`), no forced complexity classes. Storage remains Argon2id.
- Q: What is the refresh-token TTL and rotation policy? → A: 30 days, rotate on every use, and revoke the entire token family on replay of an already-rotated refresh token.

### Session 2026-09-19 (architecture audit)

- Q: How are the Customer and Dashboard API surfaces separated? → A: By URL prefix, guard and token. Customer endpoints live under `/api/v1/customer/auth/*` behind the Sanctum `customer` guard (`auth:customer`, provider `customers`); dashboard endpoints under `/api/v1/dashboard/auth/*` behind the Sanctum `staff` guard (`auth:staff`, provider `staff`). The generic `/api/v1/auth/*` routes and `/api/v1/user` do not exist. `auth:sanctum` is not used by any route. A token of the other principal is `401 unauthenticated`.
- Q: What abilities do tokens carry? → A: Exactly one per token: `customer:access`, `customer:refresh`, `staff:access`, `staff:refresh`; never `*`. Access endpoints require `<principal>:access`; refresh endpoints require `<principal>:refresh`. A right-principal token with the wrong ability is `403 forbidden`, so a refresh token never works as an access token.
- Q: Does customer registration share the login rate limit? → A: No. Each concern has its own limiter: customer register (5 per hour per phone + 10 per hour per IP), customer login, staff (dashboard) login, OTP send/verify, password-reset request, refresh. Failed logins never consume registration budget and registrations never lock login.
- Q: Which 429 code is returned? → A: `account_locked` when the *identity* bucket of a login limiter (customer phone, staff email) is exhausted; `too_many_requests` for every other limiter, including the per-IP bucket of a login limiter. The code is attached to the limiter, never inferred from the request URL.
- Q: How is Argon2id (FR-X-007) configured? → A: `config/hashing.php` defaults to `argon2id` (64 MiB, 4 passes, 1 lane; lowered in `phpunit.xml`). `argon.verify` is false so legacy bcrypt hashes still verify; sign-in rehashes them to Argon2id.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Customer signs up, signs in, and reads their own profile (Priority: P1)

A prospective customer visits Dahab, creates an account with their phone number and a password, signs in, and can see who they are (`is_verified`, `is_suspended`, whether trading is allowed).

**Why this priority**: This is the smallest viable auth surface. Without it, no other Dahab feature can attribute an action to a customer, so nothing else can ship. It also unblocks the "browse before verify" flow the prototype promises.

**Independent Test**: A new customer can register, sign in from the same device, receive a session, call `me`, and sign out. No other Dahab data is required.

**Acceptance Scenarios**:

1. **Given** no account for phone `+201000000001`, **When** the customer registers with that phone and a password meeting policy, **Then** an account is created with `is_verified=false` and `is_suspended=false`, and a session is issued (device is trusted for that first sign-in).
2. **Given** an existing account, **When** the customer signs in with the correct phone + password from a known device, **Then** an access token and a refresh token are issued immediately with no OTP challenge.
3. **Given** a signed-in customer, **When** they read their own profile, **Then** they receive their own row (phone, optional email, verification and suspension state, and a derived `trade_allowed` flag), and never a password field.
4. **Given** a signed-in customer, **When** they sign out, **Then** the current access token and its paired refresh token are revoked and subsequent requests with either token are refused.
5. **Given** an account exists, **When** a stranger attempts to register with the same phone, **Then** the request is refused with a generic error that does not confirm whether the phone is already registered.

---

### User Story 2 — Dashboard staff signs in and is authorized against the permission matrix (Priority: P1)

A staff member (any of `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`) signs into the dashboard with their email and password, sees who they are and what they may do, and is refused from actions their role does not permit.

**Why this priority**: The staff surface is what makes the audit log meaningful — every state-changing operation later in the product must resolve to exactly one named staff actor. It also unblocks staff-driven customer moderation (suspension) that is part of this feature's dashboard scope.

**Independent Test**: A seeded staff member of each role can sign in, read their own row and effective permission set, and receive a `403 permission_denied` when calling an endpoint gated to a role they do not hold.

**Acceptance Scenarios**:

1. **Given** an active staff row with role `operations`, **When** the staff member signs in with correct email and password, **Then** a staff session is issued.
2. **Given** a signed-in staff member, **When** they read the authenticated staff endpoint, **Then** they receive their staff row and an effective permission list derived from the permission matrix for their role.
3. **Given** a staff member whose role does not include a given permission, **When** they call a dashboard endpoint gated on that permission, **Then** the request is refused with `403 permission_denied`.
4. **Given** a staff row with `is_disabled=true`, **When** they attempt to sign in, **Then** sign-in is refused with a generic invalid-credentials error (no enumeration of the disabled state).
5. **Given** a staff member with a founder role (`ceo`, `coo`) or `finance`, **When** they sign in for the first time or after a password reset, **Then** MFA enrollment is required before the session becomes usable.
6. **Given** a staff account, **When** the account experiences 5 failed sign-in attempts within 30 minutes, **Then** further sign-ins are refused with `429 account_locked` until the lock window elapses.
7. **Given** a staff member with the `Suspend Customer` permission, **When** they suspend a customer with a reason from the fixed list, **Then** the customer's row is updated with `is_suspended=true`, `suspended_by=<staff_id>`, and `suspended_reason`, and an audit-log entry names the actor.

---

### User Story 3 — Customer signs in from a new device and completes an OTP challenge (Priority: P2)

A customer already registered on their phone signs in from a browser or app instance the system has never seen; they receive an SMS one-time code, enter it, and their session is then issued while that new device is remembered as trusted for next time.

**Why this priority**: This is the promise the prototype makes to the customer — "we send a code every time you sign in from a new device." Without it, credential-stuffing on stolen passwords is undefended. It is P2 because a functioning single-device experience (P1) already delivers value while this ships.

**Independent Test**: A customer known on one device attempts sign-in from a different device fingerprint, is prompted to complete an OTP, does so, and receives a session; on the next sign-in from the same device, no OTP is prompted.

**Acceptance Scenarios**:

1. **Given** correct phone + password from a device fingerprint never trusted for that customer, **When** the customer submits the sign-in, **Then** no session is issued yet, an SMS one-time code is sent, and the response says an OTP is required.
2. **Given** a pending OTP challenge, **When** the customer submits the correct code before it expires, **Then** the session is issued and the device fingerprint is recorded as trusted for that customer.
3. **Given** a pending OTP challenge, **When** the customer submits an incorrect code, **Then** the challenge is refused; after N wrong attempts the challenge is voided and a new sign-in is required.
4. **Given** an issued OTP, **When** the TTL has elapsed, **Then** attempts to submit it are refused with `otp_expired`.
5. **Given** repeated OTP-send requests for the same phone from the same IP, **When** the send-rate exceeds the configured limit, **Then** further sends are refused with `429 too_many_requests`.

---

### User Story 4 — Password reset for both principals (Priority: P2)

A customer who forgot their password requests a reset by phone number, receives a one-time SMS token, and uses it to set a new password. A staff member does the same by email. In both cases, all existing sessions for that account are terminated on success.

**Why this priority**: Standard hygiene. Without it, a user who forgets their password is locked out and has to be reset by staff, which is an operational drag and a social-engineering vector.

**Independent Test**: An account with a known password can be reset via the phone/email flow, the new password works on sign-in, the old password does not, and existing sessions are gone.

**Acceptance Scenarios**:

1. **Given** any request from a phone/email address, **When** the reset-request endpoint is called, **Then** the response is uniform whether or not the account exists (no enumeration).
2. **Given** an issued reset token, **When** the caller submits the token with a new password meeting policy, **Then** the password is updated, the token is invalidated, every existing session for that account is revoked, and a subsequent sign-in with the new password succeeds while the old password fails.
3. **Given** a reset token that has already been consumed, **When** the caller submits it again, **Then** the request is refused as invalid.
4. **Given** an expired reset token, **When** the caller submits it, **Then** the request is refused as expired.
5. **Given** a founder-role staff member (`ceo`, `coo`) completing a password reset, **When** they next sign in, **Then** MFA re-enrollment is required.

---

### User Story 5 — Suspended customer can read but cannot trade (Priority: P2)

A customer whose account has been suspended by a staff member can still sign in and read their own profile and open orders (so they can withdraw a remaining balance and wind down), but every state-changing trade action is refused with `account_suspended`.

**Why this priority**: The docs explicitly require this shape: suspension is not the same as ban. Getting this wrong is a customer-service disaster.

**Independent Test**: A suspended customer signs in, receives their profile with `is_suspended=true`, and any request to a trade endpoint (once trade endpoints exist) is refused with `403 account_suspended`. In this feature the check is validated on a stub endpoint that requires `trade_allowed`.

**Acceptance Scenarios**:

1. **Given** `is_suspended=true` on a customer, **When** they sign in with correct credentials, **Then** the sign-in succeeds and their profile shows `is_suspended=true`, `trade_allowed=false`, and the suspension reason.
2. **Given** a suspended customer with a session, **When** they call any endpoint gated by `trade_allowed`, **Then** the request is refused with `403 account_suspended`.
3. **Given** a suspension row without both `suspended_by` and `suspended_reason`, **When** the write is attempted, **Then** the database refuses it (the CHECK constraint `customer.suspended_needs_actor` is enforced).

---

### User Story 6 — Session refresh and revocation (Priority: P2)

A customer's short-lived access token expires; the client presents the refresh token and receives a new pair. Refresh tokens rotate on every use, and a stolen refresh token cannot be reused after the next successful refresh.

**Why this priority**: The prototype requires "sign-out from anywhere" and the docs mandate rotate-on-use so a leaked refresh token is contained. Without it, either sessions live too long (P0 risk) or the user is repeatedly logged out (poor UX).

**Independent Test**: With a valid refresh token, a client can obtain a new access/refresh pair; using the old refresh token after that is refused. A logout-all revokes every token for the actor.

**Acceptance Scenarios**:

1. **Given** a valid refresh token, **When** the refresh endpoint is called, **Then** a new access token and a new refresh token are issued and the previous refresh token is invalidated.
2. **Given** a refresh token that has already been rotated, **When** it is presented again, **Then** the request is refused as invalid (`401 refresh_invalid`) and (as a safety measure) every token in that token family is revoked. Other families of the same actor are unaffected; `logout-all` is what revokes every token of the actor.
3. **Given** a signed-in actor (customer or staff), **When** they invoke logout-all, **Then** every access and refresh token issued to them is revoked and subsequent use of any of them is refused.
4. **Given** an access token, **When** it is presented to the refresh endpoint — or a refresh token to any access endpoint — **Then** the request is refused with `403 forbidden` and nothing is rotated or revoked.

---

### User Story 7 — Email verification primitive for withdrawal (Priority: P3)

A customer sets or changes their email address; the system issues a one-time signed link to that email. Confirmation marks the email as verified; the same primitive is later used as the "second check on withdrawal" (§2.4), but the withdrawal flow itself is out of scope.

**Why this priority**: We must ship the primitive now because the withdrawal spec depends on it, but there is no user-visible workflow for it yet in this feature; hence P3.

**Independent Test**: A customer can add or change an email, receive a link, confirm it, and see `email_verified_at` set on their profile. An expired or reused link is refused.

**Acceptance Scenarios**:

1. **Given** a signed-in customer without a verified email, **When** they submit an email address, **Then** a one-time signed link is emailed and the customer's `email` is stored as unverified.
2. **Given** a valid verification link, **When** the customer opens it before it expires, **Then** the email is marked verified and the link is invalidated.
3. **Given** a link that was already consumed or that has expired, **When** it is opened, **Then** the request is refused.

---

### User Story 8 — Founder-security primitives are in place for later features (Priority: P3)

The database and the read-side auth layer already know how to represent founder freezes and staff device fingerprints, even though the workflows that use them (mutual freeze on sensitive staff actions) are not part of this feature.

**Why this priority**: Later money-moving features cannot land without these columns and tables in place. Shipping the primitives here avoids a schema migration mid-flight later.

**Independent Test**: Staff rows carry an `is_frozen` flag (default false) that, when true, refuses sign-in with a distinct error. Device fingerprints for staff can be recorded on sign-in even if no approval flow reads them yet.

**Acceptance Scenarios**:

1. **Given** a staff row with `is_frozen=true`, **When** they attempt to sign in, **Then** sign-in is refused with `account_frozen` and no session is issued.
2. **Given** a staff sign-in from a new device fingerprint, **When** the sign-in succeeds, **Then** the fingerprint is recorded against the staff row for later reference.

---

### Edge Cases

- Register with a phone number that already exists → refused with a generic error (no enumeration).
- Reset-request for a phone/email that does not exist → responds with the same shape as a successful request (no enumeration).
- OTP requested for a phone that does not correspond to any account → responds with the same shape as a successful send (no enumeration); no SMS is actually sent.
- Sign-in with correct password for a suspended customer → succeeds; sign-in with correct password for a disabled staff member → refused with a generic invalid-credentials error.
- Sign-in with correct password for a frozen staff member → refused with `account_frozen`.
- Sign-in with correct password but from a new device for a customer → returns `otp_required` and issues no session.
- Access token expired but refresh token valid → refresh endpoint issues a new pair.
- Refresh token replay (rotated then replayed) → refused with `refresh_invalid`, AND the whole token family is revoked as a safety measure.
- A customer token presented on the Dashboard API, or a staff token on the Customer API → `401 unauthenticated` (wrong principal), never `403`.
- A refresh token on an access endpoint, or an access token on the refresh endpoint → `403 forbidden` (wrong ability).
- Password reset link opened twice → second attempt refused as consumed.
- Staff sign-in success/failure — every attempt written to the audit log with actor (if resolvable), IP, and user-agent.
- A password field never appears in any response body, log line, exception message, or OpenAPI schema.

## Requirements *(mandatory)*

### Functional Requirements — Customer

- **FR-C-001**: The system MUST allow a prospective customer to register with phone number and password. Password MUST be stored using Argon2id (`docs/Part 1 §2.1`) and never returned.
- **FR-C-002**: The system MUST allow a customer to sign in with phone + password on every sign-in (both factors required, no phone-only or password-only path).
- **FR-C-003**: The system MUST issue an access token and a refresh token on successful sign-in. Access tokens live ~15 minutes (configurable). Refresh tokens live 30 days (configurable), rotate on every use, and the entire token family MUST be revoked on replay of an already-rotated refresh token.
- **FR-C-004**: The system MUST recognize known devices by fingerprint and skip the OTP challenge for them on sign-in.
- **FR-C-005**: The system MUST require an OTP challenge on customer sign-in from a device fingerprint not previously trusted for that customer, and MUST record the fingerprint as trusted on successful OTP.
- **FR-C-006**: OTPs MUST expire after 5 minutes, be single-use, be stored only as a hash, be verifiable at most 5 times before the challenge is voided, have a 60-second send cooldown per phone number, and cap at 5 sends per hour per phone.
- **FR-C-007**: The system MUST allow a signed-in customer to read their own profile, returning at minimum: phone, optional email and its verified state, `is_verified`, `is_suspended`, `suspended_reason` when suspended, and a derived `trade_allowed = is_verified && !is_suspended`.
- **FR-C-008**: The system MUST allow a signed-in customer to sign out (revoking the current access token and its paired refresh token) and to sign out from every device (revoking every token for the actor).
- **FR-C-009**: The system MUST support password reset by phone: request → SMS with a one-time signed token → submit token + new password. On success, the password is updated, the token is invalidated, and ALL existing sessions for the account are revoked.
- **FR-C-010**: The system MUST allow a signed-in customer to set or change an email address; changing email MUST issue a one-time signed verification link to the new address and MUST mark the email unverified until the link is consumed.
- **FR-C-011**: A suspended customer (`is_suspended=true`) MUST be able to sign in and read their own profile, but every state-changing trade action MUST be refused with error code `account_suspended` (HTTP 403).
- **FR-C-012**: Suspension writes MUST carry both `suspended_by` (a staff_id) and `suspended_reason` (from a fixed list). This is enforced by the database CHECK `customer.suspended_needs_actor` per the applied schema.
- **FR-C-013**: Registration, sign-in, reset-request, and OTP-send endpoints MUST return a uniform response shape whether or not the target account exists (user-enumeration protection).

### Functional Requirements — Dashboard staff

- **FR-S-001**: The system MUST recognize exactly six staff roles, spelled `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`. Roles are schema, not admin-panel data.
- **FR-S-002**: The system MUST allow staff sign-in by email + password.
- **FR-S-003**: The system MUST require MFA (TOTP) enrollment for `ceo`, `coo`, `finance` roles; other roles MAY optionally enroll.
- **FR-S-004**: The system MUST throttle failed staff sign-ins at 5 failures / 30 minutes per identity (email) and 20 requests / minute per IP. Once the identity bucket is exhausted, further attempts return `429` with error code `account_locked` until the window elapses; an exhausted IP bucket returns `429 too_many_requests` (§3.4 default; configurable). The dashboard login has its own limiter, `auth.staff.login`, shared with no other endpoint.
- **FR-S-005**: The system MUST refuse sign-in for staff with `is_disabled=true` using a generic invalid-credentials error (no enumeration).
- **FR-S-006**: The system MUST refuse sign-in for staff with `is_frozen=true` using a distinct error code `account_frozen` — the frozen state is a founder-security primitive that later features will drive.
- **FR-S-007**: The system MUST allow a signed-in staff member to read their own profile and their effective permission set held through Spatie roles and permissions on the `staff` guard (matrix per docs §4; see `docs/Technical Spec/dahab-dashboard-authorization.md`).
- **FR-S-008**: The system MUST allow a signed-in staff member to sign out and to sign out from every device.
- **FR-S-009**: The system MUST support staff password reset by email, using the same primitive shape as customer password reset, and MUST force MFA re-enrollment on the next sign-in for founder-role accounts.
- **FR-S-010**: The system MUST enforce authorization on every dashboard endpoint through Spatie permissions (`staff.permission:<code>` middleware, `can()`, Policies); role literals MUST NOT be scattered across handlers. A denial returns `403 permission_denied`. Customers never hold roles or permissions; a customer token is not a valid dashboard credential.
- **FR-S-011**: The system MUST allow a staff member holding the `customer.suspend` permission to suspend or unsuspend a customer with a reason from the fixed list; the write MUST populate `suspended_by`, `suspended_reason`, and MUST be audit-logged.
- **FR-S-012**: The system MUST record every staff sign-in success or failure to the audit log with actor (when resolvable), IP, user-agent, and outcome.

### Functional Requirements — Shared / cross-cutting

- **FR-X-001**: Every state-changing request MUST resolve to exactly one `customer_id` OR exactly one `staff_id` before it commits. This resolution is the responsibility of the auth layer.
- **FR-X-002**: The API surface for this feature MUST live under `/api/v1/customer/auth/*` (customer) and `/api/v1/dashboard/auth/*` (staff), returning the project's uniform `ApiResponse` envelope. There MUST be no generic `/api/v1/auth/*` customer route and no `/api/v1/user` route.
- **FR-X-003**: Error responses MUST use documented codes: `unauthenticated`, `invalid_credentials`, `account_suspended`, `account_frozen`, `account_locked`, `permission_denied`, `forbidden`, `otp_required`, `otp_invalid`, `otp_expired`, `too_many_requests`, `validation_failed`.
- **FR-X-004**: Rate limits MUST apply to registration, sign-in, OTP send, OTP verify, password-reset request, and refresh, and each concern MUST have its own limiter and buckets (no limiter is shared between two of them). Documented defaults (all configurable in `config/dahab-auth.php`):
    - Customer registration (`auth.customer.register`): 5 per hour per phone, plus 10 per hour per IP.
    - Customer login (`auth.customer.login`): 5 attempts per 15 minutes per phone identity (→ `account_locked`), plus 20 requests per minute per IP (→ `too_many_requests`).
    - Staff / dashboard login (`auth.staff.login`): 5 attempts per 30 minutes per email identity (→ `account_locked`), plus 20 requests per minute per IP (→ `too_many_requests`) (per Part 1 §3.4).
    - OTP send (`auth.otp.send`): 60-second cooldown per phone, cap 5 sends per hour per phone.
    - OTP verify (`auth.otp.verify`): 5 attempts per challenge (challenge is then voided).
    - Password-reset request (`auth.password_reset.request`): 3 per hour per identity, 20 per hour per IP.
    - Refresh (`auth.refresh`): 60 per hour per token family.
  The 429 error code MUST be determined by the limiter that tripped (identity lockout vs. anything else), never by matching the request URL.
- **FR-X-005**: The audit log MUST record: sign-in success/failure, OTP send/verify outcome, password reset request/success, token revocation, staff role/permission denial, customer suspension/unsuspension, and every founder-account sign-in.
- **FR-X-006**: No endpoint response, log line, exception message, or OpenAPI schema MUST expose password hashes, refresh tokens by value, OTP values, or reset-token values.
- **FR-X-007**: Password storage MUST be Argon2id, configured as the application's default hasher (`config/hashing.php`, `HASH_DRIVER=argon2id`). Bcrypt MUST be accepted for legacy hashes only, and any successful bcrypt sign-in MUST rehash to Argon2id transparently.
- **FR-X-008**: All authentication endpoints MUST be documented via OpenAPI attributes so `/api/documentation` renders the current contract. The two API surfaces MUST use separate security schemes — `customerBearer` / `customerRefreshBearer` for the Customer API and `dashboardBearer` / `dashboardRefreshBearer` for the Dashboard API — and no operation may reference a generic `sanctum` scheme.
- **FR-X-010**: Customer and Dashboard principals MUST be isolated by guard. Customer routes MUST use the `customer` guard (`auth:customer`, Sanctum driver over the `customers` provider); dashboard routes MUST use the `staff` guard (`auth:staff`, Sanctum driver over the `staff` provider). No route may use `auth:sanctum`. A Staff token MUST be `401` on the Customer API and a Customer token MUST be `401` on the Dashboard API. Customers MUST NOT have Spatie roles or permissions; dashboard authorization stays on Spatie (FR-S-010).
- **FR-X-011**: Every token MUST carry exactly one ability — `customer:access`, `customer:refresh`, `staff:access` or `staff:refresh` — and MUST NOT carry the wildcard `*`. Access endpoints MUST require `<principal>:access` and refresh endpoints MUST require `<principal>:refresh` (Sanctum `abilities:` middleware after the guard). A right-principal token with the wrong ability MUST be refused with `403 forbidden`, so a refresh token can never act as an access token.
- **FR-X-009**: All password inputs (registration, password change, password reset) MUST enforce a policy of: minimum 10 characters, and not present in a known-breach corpus (Laravel's `Password::defaults()->uncompromised()` in local/production; the check MAY be relaxed in the test environment). No forced complexity classes are required. The same policy applies to customers and staff.

### Key Entities *(data involved)*

- **Customer** — a natural person who may hold a wallet and trade. Attributes carried by this feature: `phone` (unique identifier for sign-in), `password_hash` (Argon2id, never returned), optional `email` and `email_verified_at`, `is_verified` (identity KYC — governed by a later feature; read here), `is_suspended`, `suspended_by`, `suspended_reason`, `suspended_at`. Referenced schema: `docs/Database schema/02_schema_identity.sql`.
- **Staff** — a Dahab employee. Attributes: `email` (unique), `password_hash`, `role` (Postgres enum of the six roles), `is_disabled`, `is_frozen`, `mfa_enrolled_at`, `mfa_secret_encrypted`, `last_signed_in_at`. Referenced schema: `docs/Database schema/02_schema_identity.sql` and `05_schema_security.sql`.
- **Staff roles & permissions** — Spatie `roles` / `permissions` (guard `staff`); reference data seeded from the docs matrix, source of truth for authorization gates. Referenced schema: `docs/Database schema/05_schema_security.sql`; behaviour: `docs/Technical Spec/dahab-dashboard-authorization.md`.
- **Session token** — a Sanctum personal access token representing an access token; short-lived; owned by exactly one Customer or Staff row and carrying the single ability `<principal>:access`.
- **Refresh token** — a longer-lived Sanctum token with the single ability `<principal>:refresh`, exchangeable only at the refresh endpoint for a new access + refresh pair. Rotates on use; its family is revoked on suspected replay; all of an actor's tokens are revoked on password reset and logout-all.
- **Trusted device** — a `(customer_id, fingerprint)` record that lets subsequent sign-ins skip the OTP challenge. Recorded on successful OTP.
- **Staff device fingerprint** — a `(staff_id, fingerprint)` record captured on staff sign-in. This feature only writes it; later features read it for founder-security workflows.
- **OTP challenge** — an ephemeral, hashed, TTL-bound, single-use code paired with the pending sign-in.
- **Password-reset token** — a signed, TTL-bound, single-use token issued to phone (SMS, customers) or email (staff).
- **Email verification token** — a signed, TTL-bound, single-use token issued to email; consumption marks `email_verified_at`.
- **Audit log entry** — one row per security event; carries actor (customer_id XOR staff_id), IP, user-agent, event kind, outcome, and a JSON payload. Referenced schema: `docs/Database schema/05_schema_security.sql`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer can complete the register → sign-in → read profile → sign-out journey with valid inputs in at most 4 request/response round trips (register, login, me, logout).
- **SC-002**: A returning customer signing in from a known device receives their session with no OTP challenge in a single request/response round trip.
- **SC-003**: A returning customer signing in from a new device receives a challenge and completes sign-in with exactly two request/response round trips (login + OTP verify).
- **SC-004**: A staff member's authorization decision is made by Spatie (seeded from the permission matrix) in every dashboard request; no dashboard code path decides authorization on a role literal (verified by code review checklist).
- **SC-005**: After 5 failed sign-ins in 30 minutes for the same staff identity, the 6th attempt returns `429 account_locked` (verified by feature test; until the dashboard login exists, against the real `auth.staff.login` limiter on a probe route).
- **SC-006**: After a password reset, every prior session for the account is unusable on the first request that tries to use it (verified by feature test).
- **SC-007**: For every user-enumeration-sensitive endpoint (register, login, reset-request, otp-send), the response body and HTTP status are identical between "target exists" and "target does not exist" (verified by feature test).
- **SC-008**: Every response returned by the auth layer conforms to the project's `ApiResponse` envelope; no auth endpoint leaks a raw exception (verified by feature test and by asserting no plain 500 responses).
- **SC-009**: No auth endpoint's OpenAPI schema, feature test, log line, or exception message includes a password, OTP value, refresh-token value, or reset-token value (verified by static grep in CI and by code review).
- **SC-010**: All security-sensitive events (sign-in success/failure, OTP send/verify, password reset request/success, token revocation, suspension, denial by permission gate) land in the audit log with the actor and the request's IP and user-agent (verified by feature test).
- **SC-011**: After 5 customer login failures in 15 minutes for the same phone, the 6th attempt is refused with `429 account_locked` and a `Retry-After` header (verified by feature test).
- **SC-012**: An OTP submitted more than 5 minutes after issuance, or after 5 wrong attempts on the same challenge, is refused; a subsequent correct code from a fresh challenge succeeds (verified by feature test).
- **SC-013**: A refresh token that has been rotated once is refused on any subsequent presentation, and every other token in its family is also revoked (verified by feature test).
- **SC-014**: A Customer token on any Dashboard route, and a Staff token on any Customer route, is `401 unauthenticated`; every route under `/api/v1/customer/*` and `/api/v1/dashboard/*` is guarded by its own guard and an ability, and none uses `auth:sanctum` (verified by `PrincipalIsolationTest`, including a route-table audit).
- **SC-015**: A refresh token is refused (`403 forbidden`) on every access endpoint and an access token is refused on the refresh endpoint, for both principals; issued tokens carry exactly `<principal>:access` / `<principal>:refresh` (verified by `TokenAbilitiesTest`).
- **SC-016**: Registration attempts never consume a phone's login budget and failed logins never consume its registration budget; the 429 code follows the limiter, not the URL (verified by `RateLimiterSeparationTest`).
- **SC-017**: Newly stored password hashes are Argon2id; a legacy bcrypt hash still signs in and is rewritten as Argon2id (verified by `PasswordHashingTest`).
- **SC-018**: The generated OpenAPI document contains exactly the implemented Customer and Dashboard auth operations, secured by the four separate schemes, matches the registered routes, and exposes no credential field (verified by `OpenApiGenerationTest`).

## Implementation status

*As of 2026-09-19. [tasks.md](./tasks.md) is the task-level record; this table is the user-story summary. `contracts/openapi.yaml` marks every operation `x-implemented: true|false`.*

| Story | Status | Built | Not built yet |
|---|---|---|---|
| US1 Customer register / sign-in (known device) / `me` / logout | **Implemented** | `POST /customer/auth/register`, `login`, `GET me`, `POST logout`, `logout-all` | — |
| US2 Dashboard staff sign-in + permission matrix | **Partial** | `GET /dashboard/auth/me`; Spatie roles/permissions; `staff.permission:<code>` gate (`403 permission_denied` + audit); `auth.staff.login` limiter | staff login, MFA enroll/verify, logout / logout-all, disabled/frozen handling |
| US3 New-device OTP | Not implemented | `auth.otp.*` limiters and config only; a correct password from an untrusted device is refused with `401 invalid_credentials` | OTP service, notification, verify/resend endpoints |
| US4 Password reset | Not implemented | `one_time_token` table, `auth.password_reset.request` limiter | request/reset endpoints, notifications |
| US5 Suspended customer | **Partial** | sign-in does not block suspended customers and `me` returns `is_suspended`, `suspended_reason`, `trade_allowed` (no dedicated test yet, T110); DB CHECK `suspended_needs_actor` | `EnforceTradeAllowed`, staff suspend/unsuspend endpoints, their tests |
| US6 Refresh + revocation | **Partial** | `POST /customer/auth/refresh` and `POST /dashboard/auth/refresh` (rotation, replay → family revoked, per-family limiter); customer `logout-all` | staff logout / logout-all |
| US7 Email verification | Not implemented | `one_time_token` table | endpoints, notification |
| US8 Founder-security primitives | Not implemented | `account_freeze`, `founder_device_approval`, `staff_device_fingerprint` tables | sign-in integration (needs staff login) |

## Assumptions

- **A-01**: The Dahab technical spec Part 1 (`docs/Technical Spec/dahab-spec-part1-auth.md`) is the authoritative source; where the description above conflicts with Part 1, Part 1 wins.
- **A-02**: The applied SQL schema (`docs/Database schema/*.sql`) is authoritative for column shapes and CHECK constraints; Laravel migrations in this feature mirror those files rather than re-derive them.
- **A-03**: The SMS gateway and the outbound email provider are pluggable and mocked in tests. Real credentials are configured through `.env` and are not required for feature verification.
- **A-04**: "Device fingerprint" is treated as opaque input from the client for the purposes of this feature. Its production shape (hashed UA + platform + install-id, per §2.3) is a detail of the plan, not of this specification.
- **A-05**: MFA is TOTP (RFC 6238) for staff, using a client authenticator app; SMS is not a second factor for staff.
- **A-06**: The Spatie roles/permissions are seeded from a canonical mapping derived from `docs/dahab-admin-roles.docx` / Part 1 §4 (`App\Enums\StaffPermission` + `DashboardRolesAndPermissionsSeeder`). Only permissions the current spec needs are materialised. Adding a permission is an enum + seeder change, not a runtime write.
- **A-07**: The withdrawal flow itself is not delivered here; the email verification token primitive is delivered so the withdrawal feature can consume it later without another schema change.
- **A-08**: Founder mutual-freeze workflow is not delivered here; the `is_frozen` column and the staff device fingerprint capture are the only footprint from that sub-layer that this feature owns.
- **A-09**: "Founder-role" as used in this spec means `ceo` and `coo` only. MFA re-enrollment on password reset is triggered for these two roles; `finance` requires MFA on ordinary sign-in but does not force re-enrollment on reset. (Docs are silent on this precise boundary; carried forward as an assumption to revisit in `/speckit-analyze`.)

## Out of Scope *(mandatory for this feature)*

- Identity-document / passport upload and KYC verification workflow (Part 3).
- The withdrawal flow that consumes the email verification token.
- Founder mutual-freeze workflow (approve / freeze / unfreeze between CEO and COO).
- Any Dahab business module: Gold Items, Buy Requests, Inspections, Wallets, Payments, Settlements, Commissions, Seller/Buyer surfaces, Marketplace endpoints.
- Any endpoint outside `/api/v1/customer/auth/*` and `/api/v1/dashboard/auth/*` except the staff-driven `suspend / unsuspend customer` action delivered under `/api/v1/dashboard/*`.
