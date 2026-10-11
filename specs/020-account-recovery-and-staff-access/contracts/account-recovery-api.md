# API contract — spec 020 (planning artefact; the code and generated OpenAPI win on disagreement)

Envelope as in `docs/platform/api-contract.md`: success `{ "data": … }`; errors `{ "message", "code", "errors"? }` (flat). Throttled requests answer `429 too_many_requests`. Staff routes: `auth:staff`, `staff.standing`, permission middleware, `idempotent` on writes, audited.

## 1. Customer (public, `db.elevate:bootstrap`, no token)

### `POST /customer/auth/password/reset-request`
Throttle `auth.password_reset.request` (3 / h per phone, 10 / h per device, 20 / h per IP).

```json
{ "phone": "+201000000001" }
```
→ `202`
```json
{ "data": { "expires_in_seconds": 300, "resend_available_at": "2026-10-13T09:01:00+02:00" } }
```
- The answer is the same for a registered, unregistered, closed or cooling-down phone (C1). Only an existing, not-closed account gets an SMS (`auth.password_reset_code.sms`) and an audit row (`auth.customer.password_reset_requested`). A second request replaces the code and resets the attempt counter.
- `422 validation_failed` — `phone` missing or not E.164 (the same rule as sign-in; it does not check that the phone exists).
- `429 too_many_requests`.

### `POST /customer/auth/password/reset-confirm`
Throttle `auth.password_reset.confirm` (10 / h per phone, 30 / h per IP) **and** the per-code cap: `otp.max_verify_attempts` (5) wrong tries void the code.

```json
{ "phone": "+201000000001", "code": "482913", "password": "…", "password_confirmation": "…" }
```
→ `200`
```json
{ "data": { "password_changed": true,
            "signed_out_sessions": 2,
            "withdrawals_paused_until": "2026-10-14T09:00:00+02:00",
            "cancelled_withdrawals": 1 } }
```
`withdrawals_paused_until` is `null` when the setting is 0. No token is issued; the customer signs in normally (a device code is asked once because trusted devices were forgotten).

| Status · code | When |
|---|---|
| 401 `otp_invalid` | wrong code, no code on file, voided after 5 tries, already used, unknown phone, closed account (C1) |
| 401 `otp_expired` | a real code, past `otp.ttl_seconds` |
| 422 `validation_failed` | `code` not 6 digits; password breaks the rule (min 10, not compromised) or `password_confirmation` differs |
| 429 `too_many_requests` | throttled |

Effects, one transaction, after the code is spent: password hash + `password_changed_at`; every token family deleted; every trusted device forgotten; `WithdrawalSafetyStop` (unreleased withdrawals cancelled, pause `trigger_kind = password_reset`); sign-in lockout cleared; audit `auth.customer.password_reset_completed` (counts: sessions, devices, cancelled withdrawals, pause until); after commit `AccountNotification(PASSWORD_RESET)` on SMS + email (if any) + inbox.
Allowed for `active`, `pending_verification`, `rejected` and `suspended` accounts. Not idempotent-keyed (public, single-use code).

## 2. Staff (public, `db.elevate:bootstrap`, no token)

### `POST /dashboard/auth/password/reset-request`
Throttle `auth.password_reset.request`.
```json
{ "email": "name@dahab.eg" }
```
→ `202 { "data": { "message": "If that address belongs to an active staff account, a link was sent." } }` — identical for unknown, disabled, system and active accounts. An active, non-system member gets a mail with a 30-minute single-use link `…/set-password?token=…`; older open links of theirs are voided; audit `auth.staff.password_reset_requested`. A frozen member also receives it (they still cannot sign in while frozen).
`422 validation_failed` — `email` missing/invalid.

### `POST /dashboard/auth/password/reset-confirm`
Throttle `auth.staff.password_reset.confirm` (20 / h per IP).
```json
{ "token": "…", "password": "…", "password_confirmation": "…" }
```
→ `200 { "data": { "password_changed": true } }`. No session is issued; the next sign-in is email + password **+ the authenticator** exactly as today (MFA rows untouched, R4).

| Status · code | When |
|---|---|
| 422 `reset_link_invalid` | unknown, used, voided, expired, or the member is disabled (one code) |
| 422 `validation_failed` | password rule / confirmation |
| 429 `too_many_requests` | throttled |

Effects: `staff_password` created or updated; the link consumed atomically; every other open link of the member consumed; every staff token deleted; staff sign-in lockout cleared; audit `auth.staff.password_reset_completed` with `origin` (`recovery|invitation|enabled`).

## 3. Staff lifecycle (`auth:staff`, `staff.manage`, CEO only by seed)

### `POST /dashboard/staff` — `Idempotency-Key` required
```json
{ "full_name": "Amr Salah", "email": "amr@dahab.eg", "phone": "+201000000009",
  "roles": ["operations"], "branch_id": 3, "reason": "New hire, Cairo branch" }
```
`phone`, `branch_id` optional. → `201 { "data": DashboardStaffMember }` with `is_active: true`, `is_founder: false`, `password_set: false`; the invitation mail (48 h link) goes out after commit.
- `403 permission_denied` (no `staff.manage`) · `403 account_frozen`
- `403 escalation_denied` — a role grants a permission the actor does not hold
- `422 validation_failed` — name (2–120), email format or **already used** (`errors.email`), phone, `roles` (1+, existing names), `branch_id` (an enabled branch)
- `422 reason_required`
Audit `authz.staff.created` (roles, branch, reason, `created_by`). Founder status can never be set here.

### `POST /dashboard/staff/{staff}/disable` · `POST /dashboard/staff/{staff}/enable` — `Idempotency-Key` required
```json
{ "reason": "Left the company" }
```
→ `200 { "data": DashboardStaffMember }`.

| Status · code | When |
|---|---|
| 403 `escalation_denied` | the target is the actor (cannot disable / enable yourself) |
| 403 `founder_protected` | the target is a founder (disable) |
| 404 `not_found` | unknown id or the system actor |
| 409 `staff_already_disabled` / `staff_not_disabled` | wrong current state |
| 409 `last_role_manager` | disabling the last active `roles.manage` holder (spec 002 FR-023) |
| 422 `reason_required` | no reason |

Disable: `is_active = false`; every token deleted; open links consumed; sign-in answers `invalid_credentials`; the member stays in the list under `status=disabled`; history keeps their name. Enable: `is_active = true`; password deleted; 48 h link mailed; `password_set: false` until used. Audit `authz.staff.disabled` / `authz.staff.enabled` with reason and before/after.

### `GET /dashboard/staff` (existing, `staff.view`) — additions
- optional query `status=active|disabled` (omitted = all, as today).
- `DashboardStaffMember` gains optional `password_set: boolean`.

## 4. Existing contract touched

- Customer file (`GET /dashboard/customers/{id}`) `withdrawal_pause.trigger_kind` enum gains `password_reset` (potentially breaking for exhaustive maps — Dashboard updated in the same phase).
- `POST /customer/me/password` (change) is unchanged.

## 5. New error codes (to `docs/platform/api-contract.md` in the phase that ships them)

`reset_link_invalid` (422) · `founder_protected` (403) · `staff_already_disabled` (409) · `staff_not_disabled` (409). Reused: `otp_invalid`, `otp_expired`, `validation_failed`, `reason_required`, `escalation_denied`, `last_role_manager`, `permission_denied`, `account_frozen`, `too_many_requests`.

## 6. New permission

`staff.manage` — *Create, disable or enable staff accounts* — group Access control — held by `ceo` only.

## 7. Notifications

| Key | Channel | Editable |
|---|---|---|
| `auth.password_reset_code.sms` | SMS to the phone | no (code, D7) |
| `account.password_reset.*` (sms, mail subject/body, inbox) | SMS + email + inbox | yes |
| staff invitation / recovery / enabled link | email, English, PHP (`StaffPasswordLinkNotification`) | no (link) |
