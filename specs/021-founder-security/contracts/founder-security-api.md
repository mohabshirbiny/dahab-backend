# API contract: spec 021 (planning artefact; the code and generated OpenAPI win on disagreement)

The envelope is as in `docs/platform/api-contract.md`: success `{ "data": … }`, errors `{ "message", "code", "errors"? }` (flat).

- **Staff routes** carry `auth:staff`, `abilities:staff:access`, `staff.standing`, then `staff.founder` or the parameterised `staff.founder_or:founder.unfreeze_second` (research R13). Writes carry `idempotent`, and everything is audited through `RecordAuditLogAction`.
- **Public auth routes** carry `db.elevate:bootstrap`.
- **`X-Device-Id`.** Every Dashboard request now sends `X-Device-Id` (and `X-Device-Platform: web`). For founders it's required at sign-in (R3).

## 1. The held sign-in (founders only; changes three existing answers)

### `POST /dashboard/auth/mfa/verify` · `POST /dashboard/auth/mfa/enroll` · `POST /dashboard/auth/login`

Request bodies are unchanged. For a founder whose device is not known, after a correct password **and** MFA (login only when MFA is off locally), the answer is a fourth keyed variant. The status is `200`, as for the MFA variants, and **no token is issued**:

```json
{ "data": {
    "device_approval_required": true,
    "approval_id": "9b1c…-uuid",
    "approval_ref": "q3Jx…43 chars base64url…",
    "expires_at": "2026-10-14T10:15:00+03:00",
    "poll_interval_seconds": 5
} }
```

| Status · code | When |
|---|---|
| 422 `device_id_required` | a founder reached the gate with no `X-Device-Id` (no row is created; the password and MFA results are not revealed beyond what the earlier step already did) |

Everything else is unchanged:

- `invalid_credentials`, `account_frozen`, `mfa_invalid`, `account_locked`, `too_many_requests` as before;
- non-founders and founders on a known device get the session as before.

**Side effects.**

- A `founder_device_approval` row is created and any earlier pending row for the same founder and device is ended.
- Audit `founder.device_approval_requested`, with the founder as actor: IP, platform, device ref.
- The other founder(s) get an email and a Dashboard notice, and so does the requester (R11).

### `POST /dashboard/auth/device-approval/status` (public)

Middleware: `db.elevate:bootstrap`, `throttle:auth.staff.device_approval`. The request needs the **same** `X-Device-Id` as the held sign-in.

```json
{ "approval_ref": "q3Jx…" }
```

| Answer | Body |
|---|---|
| `200` pending | `{ "data": { "status": "pending", "expires_at": "…" } }` |
| `200` approved (first poll only) | `{ "data": { "status": "approved", "staff": StaffProfile, "session": Session } }`, the same `staff` + `session` shape as `mfa/verify` |
| `403 device_approval_refused` | the other founder refused |
| `401 device_approval_invalid` | unknown ref, expired, already collected, or another device |
| `403 account_frozen` | the founder was frozen while waiting (nothing minted) |
| `401 invalid_credentials` | the founder was disabled while waiting (nothing minted) |
| `422 validation_failed` | `approval_ref` missing |
| `429 too_many_requests` | |

**On approval.**

- `consumed_at` is set.
- `CompleteStaffSignInAction` runs with `via: device_approval`, which records the device as known and audits `auth.staff.sign_in`.
- Audit `founder.device_approval_collected`.

## 2. Device approvals (founders, `staff.founder`)

### `GET /dashboard/founder/device-approvals`

Query: `status=pending|approved|refused|expired|all` (default `pending`), `page`, `per_page` (≤ 50). It lists **every founder's** requests, so both founders see the same history, each row with `can_decide`.

```json
{ "data": [ {
    "id": "uuid",
    "founder": { "id": "uuid", "full_name": "…" },
    "status": "pending",
    "platform": "web",
    "user_agent": "Mozilla/5.0 …",
    "ip_address": "41.x.x.x",
    "device_ref": "a1b2c3d4",
    "requested_at": "…", "expires_at": "…",
    "decided_by": null, "decided_at": null, "decision_note": null,
    "collected_at": null,
    "can_decide": true
} ], "meta": { "current_page": 1, "per_page": 20, "total": 1, "pending_for_me": 1 } }
```

`can_decide` is true when the request is pending, it isn't mine, and I'm not frozen. Not audited (a read).

### `POST /dashboard/founder/device-approvals/{id}/approve` · `/refuse`

`idempotent`.

```json
{ "note": "optional, ≤ 500" }
```

→ `200 { "data": <the row as above> }`

| Status · code | When |
|---|---|
| 403 `founder_self_action` | it is your own request (backed by `approver_is_not_self` / `refuser_is_not_self`) |
| 409 `device_approval_not_pending` | already decided, collected or expired |
| 404 `not_found` | unknown id |

Audit `founder.device_approved` / `founder.device_refused` (reason = note), and the founder notice (R11).

### `GET /dashboard/founder/devices`

Founder-only. Each founder's known devices: `device_ref`, `first_seen_at`, `last_seen_at`. There's no removal endpoint (not in the spec; D21-1 keeps recovery manual). Not audited.

## 3. Freeze and unfreeze

### `POST /dashboard/founder/freeze` (`staff.founder`, `idempotent`)

```json
{ "staff_id": "uuid of the other founder", "reason": "required, ≤ 1000" }
```

→ `201`

```json
{ "data": {
    "id": "uuid",
    "frozen_staff": { "id": "…", "full_name": "…" },
    "frozen_by": { "id": "…", "full_name": "…" },
    "frozen_at": "…",
    "reason": "…",
    "stage": "awaiting_first",
    "confirmations": [],
    "sessions_ended": 3,
    "pending_approvals_ended": 1
} }
```

| Status · code | When |
|---|---|
| 422 `reason_required` | no reason |
| 422 `freeze_target_invalid` | not a founder, unknown, the system actor, or disabled |
| 403 `founder_self_action` | yourself (`no_self_freeze`) |
| 409 `already_frozen` | an open freeze exists |
| 403 `account_frozen` | you are frozen (by `staff.standing`, or re-checked under the lock) |

**Effects.**

- Every token of the target is deleted at once.
- The target's pending device approvals end.
- Audit `founder.frozen` (reason stored).
- Email, Dashboard notice **and SMS** to every active founder (D21-3).

### `GET /dashboard/founder/freezes` (`staff.founder_or:founder.unfreeze_second`)

Query: `state=open|all` (default `open`), pagination. Rows as above, with `confirmations`:

```json
[ { "slot": 1, "kind": "frozen_founder|second_approver", "by": { "id": "…", "full_name": "…" }, "at": "…" },
  { "slot": 2, "kind": "founder", "by": { … }, "at": "…" } ]
```

Each row also has `can_confirm` and `my_slot` (1, 2 or null) for the viewer. Not audited.

### `POST /dashboard/founder/unfreeze` (`staff.founder_or:founder.unfreeze_second`, `idempotent`)

One confirmation per call (spec Surfaces).

```json
{ "freeze_id": "uuid", "note": "optional, ≤ 500" }
```

**Who fills which slot.** It's decided by the server, never chosen by the client:

- a founder who isn't frozen → **slot 2**, which requires slot 1 to be filled;
- otherwise a holder of `founder.unfreeze_second` → **slot 1** (`second_approver`).

→ `200 { "data": <freeze row> }`. After slot 2, `stage: "unfrozen"` and `unfrozen_at` is set.

| Status · code | When |
|---|---|
| 409 `not_frozen` | the freeze is already unfrozen |
| 409 `unfreeze_order` | a founder confirms before slot 1 |
| 409 `unfreeze_already_confirmed` | slot 1 is already filled (second approver), or you already gave slot 1 |
| 403 `founder_self_action` | the frozen founder can't use this route anyway (`staff.standing`); kept for the DB CHECKs' mapping |
| 403 `permission_denied` | neither a founder nor a holder of the permission |

**Effects.**

- Audit `founder.unfreeze_confirmed` (slot, kind, note as reason); slot 2 also writes `founder.unfrozen`.
- Email and Dashboard notice to every active founder.
- No session is restored.

### `POST /dashboard/auth/frozen/confirm-unfreeze` (public, the frozen founder's slot 1)

Middleware: `db.elevate:bootstrap`, `throttle:auth.staff.login`, `throttle:auth.staff.mfa`. The request needs an `X-Device-Id` known for this founder.

```json
{ "email": "coo@dahab.test", "password": "…", "code": "123456", "note": "optional" }
```

(`recovery_code` may replace `code`.) → `200`

```json
{ "data": { "freeze_id": "uuid", "slot": 1, "waiting_for": "founder" } }
```

**No token is ever issued.**

| Status · code | Order of checks |
|---|---|
| 401 `invalid_credentials` | unknown email, wrong password, disabled, non-founder (uniform with login) |
| 409 `not_frozen` | the founder has no open freeze |
| 422 `device_id_required` / 403 `device_not_known` | no header / not a known device for this founder |
| 401 `mfa_invalid` | wrong or replayed TOTP / recovery code |
| 409 `unfreeze_already_confirmed` | slot 1 already filled |
| 429 `account_locked` / `too_many_requests` | the login buckets |

**Effects.**

- On success: audit `founder.unfreeze_confirmed` (actor = the frozen founder, kind `frozen_founder`) and email + notice to the founders.
- Failures are audited `founder.unfreeze_confirm_failed` with the reason and no secret.

## 4. Dashboard notices (any staff, own only)

| Route | Answer |
|---|---|
| `GET /dashboard/notices?unread=1&page=` | `{ "data": [ { "id", "kind", "severity", "title", "body", "link", "created_at", "read_at" } ], "meta": { …pagination, "unread_count": 3 } }` |
| `POST /dashboard/notices/{id}/read` | `200 { "data": <notice> }`; someone else's notice → `404 not_found` |
| `POST /dashboard/notices/read-all` | `200 { "data": { "marked": 3 } }` |

Not audited, no permission.

## 5. Console (no HTTP)

`php artisan founder:trust-device {email} {device-id} {--platform=web} {--force}` (research R10). It's audited `founder.device_trusted_by_console` (system actor) and alerts the founders.

## 6. Classification

| Change | Class |
|---|---|
| New endpoints (§1 status, §2, §3, §4) | non-breaking |
| New `device_approval_required` variant on `login`, `mfa/verify`, `mfa/enroll` | **potentially breaking**: the Dashboard's `verifyMfa`/`enrollMfa` assume a session; founders only; ships with the Dashboard change |
| `422 device_id_required` for a founder without `X-Device-Id` | **potentially breaking**: tightened auth for founders; the Dashboard must send the header first (ship together) |
| New `via` value `device_approval` in the audit payload | non-breaking (audit viewer shows payloads as JSON) |
| New permission `founder.unfreeze_second` | non-breaking |
| New error codes | each only on new endpoints, except `device_id_required` (above) |
| Customer app | not affected: no `/customer/*`, `/market/*` or `/reference/*` change |
