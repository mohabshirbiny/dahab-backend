# Research: Founder-account security (spec 021)

Every decision below was checked against the code at Backend `main` 86ffb8e and Dashboard `main` e364eb6. "Owner" means D21-1…4 and C1–C2 in [spec.md](spec.md).

## R1 — Where the held sign-in sits

**Decision.** The hold is one gate, `FounderDeviceGate`, called by the three places that today end in `CompleteStaffSignInAction::execute()`: `LoginStaffAction` (no MFA step, so only when `mfa_enforced` is off), `VerifyStaffMfaAction` and `EnrollStaffMfaAction`. For a founder whose device is not known, the gate creates the `founder_device_approval` row and returns the *held* shape **instead of** calling `CompleteStaffSignInAction`. No token is minted, and no `auth.staff.sign_in` row is written; `founder.device_approval_requested` is written instead.

**Why.** All three paths converge on `CompleteStaffSignInAction`, which is the only place that mints staff tokens and records `staff_device_fingerprint`. Holding there keeps "after the password **and** MFA" (Story 1.1) true for every path, and the password/MFA failure answers stay exactly as they are.

**Rejected.** Holding before MFA. That would leak "this founder's password is right" to a device that has not passed MFA, and it would make the other founder approve requests from people who don't have the authenticator.

## R2 — "Known device" = `staff_device_fingerprint`

**Decision.** A founder's device is known when a `staff_device_fingerprint` row exists for (`staff_id`, `fingerprint_hash`). There is no new table. The fingerprint is the existing one from `SetRequestContext`: `sha256(X-Device-Id | X-Device-Platform)`.

For a founder, a row is only ever written by a completed sign-in, so after spec 021 every founder row comes from one of three places: a known device, an approval, or the C2 command. Rows written before spec 021 stay known (they came from password + MFA sign-ins). On the primary database there are probably none, because the Dashboard has never sent `X-Device-Id` (R3); the quickstart shows the query to check.

**Found while reading (fixed in the same task).** `CompleteStaffSignInAction` calls `updateOrCreate(..., ['first_seen_at' => now(), 'last_seen_at' => now()])`, which overwrites `first_seen_at` on every sign-in. The Security page shows "known since", so the update keeps `first_seen_at` on existing rows. It's a one-line change inside code this spec touches.

## R3 — The Dashboard must send `X-Device-Id`

**Finding.** `dahab-dashboard/src/api/axios.ts` sends `Authorization` and `Accept-Language` only, with no `X-Device-Id`. So `ctx->deviceFingerprintHash` is `null` for every Dashboard request, and no staff device fingerprint has ever been recorded from the Dashboard.

**Decision.** This is a Dashboard requirement. It generates a random id once (`crypto.randomUUID()`), keeps it in `localStorage` (`dahab.device_id`), and sends `X-Device-Id` and `X-Device-Platform: web` on every request.

A **founder** sign-in that reaches the gate without `X-Device-Id` is refused with `422 device_id_required`. It's never silently held: the approval row needs a fingerprint (`device_fingerprint TEXT NOT NULL`), and with no id the device could never be recognised later. Non-founders are unaffected; a missing header stays allowed for them.

**Risk, accepted.** The id is a bearer value stored in the browser. Anyone who can read that browser's storage can present it, but they would still need the password and the authenticator. The audit log stores only the hash.

## R4 — How the held device learns the outcome (polling, single use)

**Decision.** The held answer carries an opaque `approval_ref`: 32 random bytes, base64url. Only its HMAC is stored (`ref_hash`, the spec 013/017 link pattern). The device polls `POST /dashboard/auth/device-approval/status` with `approval_ref` and the **same** `X-Device-Id`:

- `pending` → `200 {status: "pending"}`
- approved → `200 {status: "approved", staff, session}`. This is the only moment a token is minted. `consumed_at` is set under a row lock, so a second poll gets `401 device_approval_invalid` (single use, D21-4). `CompleteStaffSignInAction` then runs with `via = device_approval` and records the device as known.
- refused → `403 device_approval_refused`
- expired, unknown, already used, or polled from another device → `401 device_approval_invalid`, one answer for all of them.

**Why polling.** The Dashboard has no push channel, and the spec says the held sign-in **completes** (Story 1.2), so the device shouldn't have to type the password and code again. The 15-minute window (D21-4) is `expires_at = requested_at + 15 min`, and the approval must happen before `expires_at`. The poll must also come before `expires_at`, so an approval at minute 14 still leaves the device time to collect it.

**Account state is re-checked at collection time.** If the member was disabled or frozen after approval, the answer is `401 invalid_credentials` (disabled, same as login) or `403 account_frozen`, and nothing is minted.

## R5 — One open request per founder device

**Decision.** A new held sign-in from the same founder and fingerprint ends any earlier pending request: its `expires_at` is set to `now()`, which the guard trigger allows, since it may only move earlier. The approver therefore never sees two live requests for one device. Different devices may each have one.

Freezing a founder ends all of that founder's pending requests the same way.

## R6 — Who may approve or refuse

**Decision.** Only an active, not-frozen founder other than the requester. Self-approval is refused in the action (`403 founder_self_action`) and in the database by the existing CHECK `approver_is_not_self`, plus a new `refuser_is_not_self`. Approve and refuse are allowed only while the request is pending and not expired; otherwise `409 device_approval_not_pending`. A refusal takes an optional note, which is stored on the row and in the audit `reason`.

A refusal doesn't freeze anything: "no automatic freezing" is out of scope. The Security page offers *Freeze* next to a refused request as a separate, deliberate action.

## R7 — Freeze

**Decision.** `POST /dashboard/founder/freeze {staff_id, reason}`:

- the actor is a founder who is not frozen;
- the target is **another founder**. Freezing non-founders isn't in the spec; spec 020 *Disable* covers them. A non-founder target → `422 freeze_target_invalid`; the actor themself → `403 founder_self_action`, backed by the CHECK `no_self_freeze`;
- `reason` is required (`ReasonRule`, `422 reason_required`) and stored in `account_freeze.reason`.

Under a transaction-level advisory lock (`dahab.founder_security`) and row locks on both staff rows, in `staff_id` order, the action:

1. re-checks that the actor is not frozen, and refuses an existing open freeze with `409 already_frozen`;
2. inserts the freeze;
3. deletes **every** token of the target (Story 2.1, "old sessions stay revoked");
4. ends the target's pending device approvals (R5).

`staff.standing` already refuses every later request (`403 account_frozen`), and `LoginStaffAction` already refuses a frozen founder after the password check.

**Both founders frozen cannot happen through the system.** The lock serialises two crossing freezes. The second one finds its own actor frozen and gets `403 account_frozen`, and a frozen founder can't reach any founder endpoint. A partial unique index (one open freeze per staff) backs this up.

## R8 — Unfreeze: two confirmations, an order, two different people (D21-2, C1)

| Confirmation | Who | How |
|---|---|---|
| **1** | the frozen founder, **or** a staff member holding `founder.unfreeze_second` (not the frozen founder, active, not frozen) | frozen founder: `POST /dashboard/auth/frozen/confirm-unfreeze` (no token: email + password + TOTP or recovery code, from a device already known for that founder); second approver: `POST /dashboard/founder/unfreeze` with their own session |
| **2** (always last) | a founder who is **not** the frozen one (the one who froze, normally) | `POST /dashboard/founder/unfreeze` with their own session |

The database enforces this through the CHECKs and the DH022 trigger in data-model.md:

- `confirm_2` needs `confirm_1`;
- `confirm_1 ≠ confirm_2`;
- `confirm_2 ≠ frozen_staff_id`;
- `confirm_2` is a founder;
- `unfrozen_at` is set exactly when `confirm_2` is.

The second confirmation sets `unfrozen_at`. The account works again, but no session comes back, because they were deleted at freeze time. The founder signs in again: a known device goes straight through, and a new device is held for approval as usual.

One person never gives both confirmations: a founder who is not frozen always confirms second, and the frozen founder or the second approver confirms first. A founder who holds `founder.unfreeze_second` through a role still confirms as a founder (slot 2), because the action decides by `is_founder` first.

**Why the frozen founder's call issues no token.** A frozen account must not get a session (Story 2.1). The narrow call proves the person still controls the credentials and a known device, and it records that, nothing more. It shares the `auth.staff.login` limiter (5 failures / 30 min per email) and the MFA replay protection: the same TOTP time-step is never accepted twice, through `VerifyStaffMfaAction`'s helper, which is extracted to a small `StaffTotpVerifier`.

**When the frozen founder is unreachable.** The second approver gives confirmation 1, then the other founder confirms last.

**When the founder who is not frozen is unreachable.** No confirmation 2 is possible, so the account stays frozen. There is deliberately no stand-in for slot 2: otherwise a compromised founder plus one staff member could unfreeze.

## R9 — What happens when both founders are frozen or unreachable

Stated plainly for the owner:

| Situation | Inside the system | Way out |
|---|---|---|
| **Both frozen** | Cannot happen (R7): the freeze is serialised and refuses a frozen actor. | — |
| **One frozen, the other unreachable** | Nobody can give confirmation 2. The frozen founder stays frozen. Non-founder staff keep working within their permissions. Pending device approvals of the frozen founder were ended at freeze time. | Manual and outside the system (D21-1): a controlled database operation by the server owner. The DH022 trigger refuses an `UPDATE` that sets `unfrozen_at` without `confirm_2`, so the operation must either supply a real founder `staff_id` as `confirm_2` or disable the trigger for that transaction, as with any other DH guard. No command is built for this. |
| **Both unreachable (neither frozen)** | Device approvals expire after 15 minutes. Founders can still sign in from known devices, and nobody else can approve a founder device. Everything else runs. | None needed. A founder who has lost every known device uses the C2 command `founder:trust-device`. |
| **Both lost every known device** | Every founder sign-in is held and nobody can approve it. | The C2 command on the server, for each founder. |
| **A compromised founder freezes the real one first** | The real founder is frozen. They can give confirmation 1 but not confirmation 2. Both founders get the email and SMS at once (R11), so the real founder learns of it immediately. | Manual (D21-1). The system can't tell which founder is honest; the freeze is a brake, not a verdict. This risk is in the plan's risk list. |

## R10 — The C2 command `founder:trust-device`

**Decision.** `php artisan founder:trust-device {email} {device-id} {--platform=web}`:

- refuses a non-founder, a disabled member or a frozen founder;
- computes the same fingerprint as `SetRequestContext`;
- upserts the `staff_device_fingerprint` row;
- ends any pending approval for that device;
- writes `founder.device_trusted_by_console` with the **system actor** (`RequestContext::forSystem()`) and the founder as the entity;
- sends the founder notice (R11).

It's interactive (asks to confirm) unless `--force`. There's no HTTP route.

**How a founder finds their device id.** The held screen on the Dashboard shows the id stored in that browser, with a *Copy* button. It's only shown on the held screen.

## R11 — Story 3: the notice (D21-3), built to be reused by spec 024

**Decision.** There are no per-action edits. `RecordAuditLogAction` gains one hook: after a row is written, if the event is in `AuditEvent::founderAlert()`, it schedules `FounderAlerts::dispatch($auditRow)` with `DB::afterCommit` (directly when no transaction is open). A rolled-back action therefore never alerts.

The set:

| Group | Events | Condition |
|---|---|---|
| Sensitive actions (Story 3 list) | `wallet.adjusted`; `pricing.setting.changed`; `pricing.adjustment.changed` (rates); `promo.created`, `promo.updated`; `pricing.manual_price.entered`, `pricing.manual_price.confirmed`; `authz.staff.created`, `authz.staff.disabled` | the **actor is a founder** ("on either founder account") |
| Founder security | `founder.device_approval_requested`, `founder.device_approved`, `founder.device_refused`, `founder.device_trusted_by_console`, `founder.frozen`, `founder.unfreeze_confirmed`, `founder.unfrozen` | always, whoever the actor |

**Recipients.** Every active founder, the actor included, and including a frozen one: the real person behind a frozen account must know.

**Channels.**

- **Email** for every event in the set (`FounderSecurityNotification`, English, staff-facing like `FounderAlertNotification`).
- **Dashboard notice**: a row in the new `staff_notice` table through a new `StaffNoticeChannel`, modelled on the customer `InboxChannel`.
- **SMS** only for `founder.frozen`, to `staff.phone`. A founder without a phone gets email and notice only, and an app-log warning (`founder_alert.sms_skipped`).

**Content.**

- Included: what happened (the event label), who (actor name, or "System" for the console), when (Cairo time), the IP and device ref (the first 8 characters of the fingerprint hash), and for a device request the platform and the approve/refuse link to the Security page.
- **Never included**: passwords, codes, `approval_ref`, the `X-Device-Id` itself, amounts or setting values. The founder opens the audit entry for detail; the notice links to `/audit?entry=<audit_id>`.

**Reuse by spec 024.** `staff_notice.kind` is free text with a catalogue in `App\Enums\StaffNoticeKind`, and the founder group is one `kind`. Spec 024 adds its own kinds, recipients and conditions to the same table, channel and read endpoints. Nothing in spec 021 depends on spec 024.

**Not in the Story 3 list, so not alerted:** `authz.staff.enabled`, role or permission changes, legal publishing, category stops (the stop-everything email from spec 019 stays as it is). Adding any of them later is a one-line change to the set. The owner decided not to add them (plan Q2, 2026-10-11).

## R12 — The Dashboard notice: endpoints

`GET /dashboard/notices` (own notices only, newest first, paginated, `unread_count` in `meta`), `POST /dashboard/notices/{id}/read`, `POST /dashboard/notices/read-all`.

- No permission is needed; it's `auth:staff` + `staff.standing`. A frozen founder can't read notices until unfrozen, but the email reaches them.
- Not audited (reading your own notices), like the customer inbox.

## R13 — Founder-only routes

**Decision.** New middleware `staff.founder` (after `staff.standing`) → `403 founder_only` when `is_founder` is false. It goes on: device approvals (list/approve/refuse) and freeze.

Two routes don't take it:

- `GET /dashboard/founder/freezes` and `POST /dashboard/founder/unfreeze` are also open to holders of `founder.unfreeze_second`. They use `staff.founder_or:founder.unfreeze_second`, one middleware with a parameter.
- `GET /dashboard/founder/devices` (known devices per founder) is founder-only.

**Why not a permission.** Founder status is a flag that no endpoint can change (spec 002 FR-043). A permission could be granted to anyone through roles, which would let a non-founder approve a founder's device.

## R14 — `founder.unfreeze_second`, the one new permission

Label: "Give the first unfreeze confirmation when a frozen founder cannot". Group: *Access control*. **No seed role**, so the CEO is not given it either: the CEO is a founder and confirms as one. The migration only creates the code. The CEO names someone by adding it to a role (spec 002 Roles page); `RoleEscalationGuard` applies as usual, so only someone who holds it can grant it.

**This is a gap.** The CEO doesn't hold it, so through `roles.manage` nobody could grant it: the guard allows only permissions you hold. Options:

1. The migration gives it to the `ceo` role. The CEO holds it, but slot 1 is decided by `is_founder` first, so the CEO still confirms as a founder (slot 2), and holding it only lets them grant it.
2. Grant it by a manual data operation only.

**Option 1**, confirmed by the owner (plan Q1, 2026-10-11): it keeps the stand-in nameable from the Dashboard.

## R15 — Guards in the database

- **DH021** `founder_device_approval_guard` (BEFORE UPDATE OR DELETE):
  - no DELETE;
  - `staff_id`, `device_fingerprint`, `requested_at`, `ref_hash` never change;
  - a decision (`approved_*` or `refused_*`) is set once, only on an undecided row, and only while `now() < expires_at`;
  - `expires_at` may only move earlier;
  - `consumed_at` is set once, only on an approved row.
- **DH022** `account_freeze_guard` (BEFORE INSERT OR UPDATE OR DELETE):
  - no DELETE;
  - on INSERT, both `frozen_staff_id` and `frozen_by` are founders;
  - `frozen_staff_id`, `frozen_by`, `frozen_at`, `reason` never change;
  - each confirmation column is set once;
  - `confirm_2` is a founder;
  - a row with `unfrozen_at` never changes again.

`illegal_*` mapping follows the DH006–DH020 pattern: SQLSTATE → `DomainApiException`, though in practice the actions refuse first with the codes above.

**Effect on the existing factory.** `StaffFactory::frozen()` freezes a **non-founder** (the role it's called on) with a fresh founder as `frozen_by`. The DH022 INSERT rule (target must be a founder) would break it and every test using it. Decision: the INSERT rule checks `frozen_by` is a founder and `frozen_staff_id ≠ frozen_by`, but **not** that the target is a founder; the action (R7) enforces "the other founder". `staff.standing` and the authorization docs already treat a freeze as something any staff row can have, and the spec doesn't forbid a database-level freeze of a non-founder. The factory keeps working unchanged.

## R16 — Errors (new codes)

| Code | HTTP | Where |
|---|---|---|
| `device_id_required` | 422 | founder sign-in without `X-Device-Id` (R3); `frozen/confirm-unfreeze` without it |
| `device_approval_refused` | 403 | poll after a refusal |
| `device_approval_invalid` | 401 | poll: unknown / expired / used / other device |
| `device_approval_not_pending` | 409 | approve/refuse a decided or expired request |
| `founder_only` | 403 | `staff.founder` |
| `founder_self_action` | 403 | approve or refuse your own device, freeze yourself |
| `freeze_target_invalid` | 422 | freeze a non-founder or an unknown staff id |
| `already_frozen` | 409 | freeze an already frozen founder |
| `not_frozen` | 409 | unfreeze or confirm with no open freeze |
| `unfreeze_order` | 409 | a founder confirms before confirmation 1 exists |
| `unfreeze_already_confirmed` | 409 | the slot is taken, or the same person tries both slots |
| `device_not_known` | 403 | `frozen/confirm-unfreeze` from a device not known for that founder |

Existing codes reused: `account_frozen`, `invalid_credentials`, `mfa_invalid`, `reason_required`, `permission_denied`, `validation_failed`, `too_many_requests`, `account_locked`.

**Order of checks on `frozen/confirm-unfreeze`.**

1. Password: `401 invalid_credentials`, uniform with login.
2. Not frozen: `409 not_frozen`, which reveals no more than login's `account_frozen` does after the password.
3. Known device: `403 device_not_known`.
4. TOTP: `401 mfa_invalid`.
5. Slot: `409 unfreeze_already_confirmed`.

## R17 — Local development and tests

**Config.** `dahab-auth.founder_device_approval_enforced` (`DAHAB_AUTH_FOUNDER_DEVICE_APPROVAL`) defaults to true and is **forced on in production**, the same rule as `mfa_enforced` (`config/dahab-auth.php:144`). It can be switched off locally only. Tests run with it on and, as with MFA, use helpers to sign a founder in from a pre-trusted device: `StaffFactory::founder()->withKnownDevice('dev-1')`.

**Existing tests.** Tests that sign founders in through `/dashboard/auth/*` would start being held. The task list includes finding them (`grep -rl "founder()" tests/Feature/Auth`) and giving them a known device. Tests that issue tokens directly (`IssueTokenFamilyAction`, `actingAs`) are unaffected.

## R18 — OpenAPI shape of the held answer

`login`, `mfa/verify` and `mfa/enroll` gain a `oneOf` member `StaffDeviceApprovalPending`:

```json
{
  "device_approval_required": true,
  "approval_ref": "<opaque>",
  "approval_id": "<uuid>",
  "expires_at": "<ISO 8601>",
  "poll_interval_seconds": 5
}
```

`login` already uses this keyed-variant pattern for MFA. For `verify` and `enroll` it's a **new variant on an existing response**, classified as potentially breaking for the Dashboard, which today assumes a session there (`staff-auth.service.ts` `verifyMfa`/`enrollMfa`). It only happens for founders, and the Dashboard change ships with it.
