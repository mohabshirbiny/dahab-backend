# Implementation Plan: Founder-account security

**Branch**: `feature/founder-security` in Backend and Dashboard (created from each `main` **only when the owner asks**; this planning session wrote to the Backend branch it was given) | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Status**: Plan only. Nothing is implemented, committed or pushed. D21-1…4 and C1–C2 are the owner's. Q1–Q3 answered by the owner (2026-10-11): the planned defaults. The test databases `dahab_wt021` / `dahab_wt021_dev` exist on the owner's machine.

**Bases verified**: Backend `main` 86ffb8e (spec 020 merged), Dashboard `main` e364eb6 (read from GitHub; only the Backend is checked out in this planning container). The Customer app is not touched.

## Summary

Three protections from Part 1 §8, on tables that exist but have never been written:

1. **Held new-device sign-in** (Story 1). After password **and** MFA, a founder on an unknown device gets **no token**. The answer is `200 { device_approval_required, approval_id, approval_ref, expires_at, poll_interval_seconds }`. The device polls `POST /dashboard/auth/device-approval/status`; the other founder approves or refuses from the *Security* page. The first poll after approval returns the normal `staff` + `session` and consumes the approval (single use, 15 minutes, D21-4). "Known device" is the existing `staff_device_fingerprint` (R2). The Dashboard must start sending `X-Device-Id` (R3). The first device of each founder is trusted by a server command (C2, R10).
2. **Freeze and dual unfreeze** (Story 2). A founder freezes the other founder with a reason: every token is deleted and every request answers `403 account_frozen`. Unfreezing takes two confirmations in order (C1, R8):
   - **slot 1** is the frozen founder through a tokenless call (password + TOTP from a known device), or a holder of `founder.unfreeze_second`;
   - **slot 2**, always last, is the founder who is not frozen.

   The database enforces the order and the "two different people" rule (CHECKs + DH022).
3. **Real-time notice** (Story 3). One hook in `RecordAuditLogAction`: after commit, an event in the founder-alert set sends every active founder an email and a Dashboard notice (new `staff_notice` table + `GET /dashboard/notices`), plus an SMS for a freeze (D21-3). No secret is ever in a notice (R11). The table, channel and endpoints are generic so spec 024 can add its own kinds.

## What a held sign-in looks like to the member

1. The founder types email and password: `200 { mfa_required, session_ref }`, unchanged.
2. The founder types the authenticator code. A non-founder, or a founder on a known device, gets the session as today. A founder on an unknown device gets:

   ```json
   200 { "data": { "device_approval_required": true, "approval_id": "…", "approval_ref": "…",
                   "expires_at": "…+15 min", "poll_interval_seconds": 5 } }
   ```

   **No access token, no refresh token, no `staff` profile.** It's a keyed variant like `mfa_required`, not an error code. The Dashboard shows the *Waiting for approval* screen: the other founder's name isn't shown, only "the other founder". It shows the expiry countdown, this device's id with *Copy* (for C2), and *Cancel*.
3. The device polls every 5 s:
   - `{status: "pending"}` while waiting;
   - `{status: "approved", staff, session}` once;
   - `403 device_approval_refused` on a refusal ("The other founder refused this sign-in. If this was you, call them.");
   - `401 device_approval_invalid` when expired, already used or polled from elsewhere ("This request has ended. Sign in again.").
   - If the account is frozen meanwhile, `403 account_frozen`.
4. A founder sign-in **without** `X-Device-Id` gets `422 device_id_required`. Only an old Dashboard build would do that.

## What happens when both founders are frozen or unreachable

Full table in research R9. In short:

- **Both frozen: cannot happen.** Freezes are serialised by an advisory lock plus row locks. A frozen founder is refused at `staff.standing` and again under the lock (`403 account_frozen`), so of two crossing freezes the second always fails.
- **One frozen, the other unreachable: stays frozen.** Slot 2 has no stand-in, by design: otherwise a compromised founder plus one staff member could unfreeze. Non-founder staff keep working. The way out is manual, outside the system (D21-1): a controlled database operation. DH022 refuses an unfreeze without a founder's confirmation, so that operation either records a real founder as slot 2 or disables the guard for the transaction. No command is built for it.
- **Both unreachable, neither frozen.** Device requests expire after 15 minutes; known devices still sign in; the rest of the platform runs. A founder who lost every known device uses `founder:trust-device` on the server.
- **A compromised founder freezes the honest one first.** The system can't tell who is honest. The honest founder is told at once by email, SMS and notice, can give slot 1 but not slot 2, and recovers manually (D21-1). This is the main residual risk; see Risks.

## Impact analysis

```
Backend:          YES — FounderDeviceGate + 4 Actions (approve/refuse device, freeze, confirm unfreeze), CollectDeviceApprovalAction,
                  ConfirmFrozenFounderUnfreezeAction, FounderAlerts + FounderSecurityNotification + StaffNoticeChannel, notices actions,
                  StaffTotpVerifier (extracted from VerifyStaffMfaAction), middleware staff.founder / staff.founder_or, console
                  founder:trust-device; Login/Verify/Enroll staff actions call the gate; RecordAuditLogAction hook; 3 controllers, FormRequests,
                  Resources, #[OA]; limiter; config
Database:         YES — 1 migration: founder_device_approval + 9 columns, 5 CHECKs, guard DH021; account_freeze + 4 columns, 9 CHECKs,
                  unique open-freeze index, guard DH022; new table staff_notice; permission founder.unfreeze_second (Q1)
API:              YES — 12 new endpoints (2 public auth, 7 founder, 3 notices — see contracts); a 4th keyed variant on login / mfa/verify /
                  mfa/enroll; 422 device_id_required for a founder sign-in without X-Device-Id
Dashboard:        YES — send X-Device-Id on every request; held-sign-in screen + polling; frozen-founder sign-in message + "Confirm my
                  unfreeze"; Security page (pending approvals, founders' devices, freeze/unfreeze, history); notices bell in AppTopbar;
                  types, services, endpoints, router, permission constant
Customer App:     NO — nothing under /customer/*, /market/* or /reference/* changes; no customer-visible text. Verified: the Flutter app
                  never calls /dashboard/*.
Auth:             YES — a held step after MFA for founders; tokens revoked on freeze; a tokenless credential check for the frozen founder
Permissions:      YES — founder.unfreeze_second (new). Founder-only routes gate on staff.is_founder (not a permission, R13)
API models/types: YES — Dashboard types/staff.ts (StaffLoginResult variant, held state), types/security.ts (new), types/notices.ts (new),
                  types/api.ts; PERMISSIONS.founderUnfreezeSecond
```

**Classification.** See contracts §6.

- **Potentially breaking**, founders only, shipped together with the Dashboard:
  - the new variant on `mfa/verify` / `mfa/enroll` (the Dashboard expects a session);
  - `422 device_id_required` (the Dashboard sends no `X-Device-Id` today).
- Everything else is non-breaking.

**Consumers checked** (Dashboard e364eb6):

- `src/api/axios.ts` sends no `X-Device-Id`.
- `src/services/staff-auth.service.ts` `verifyMfa`/`enrollMfa` map the answer straight to a session.
- `src/stores/auth.ts` `LoginOutcome` has three values.
- `src/router/index.ts` has the guest routes `/login`, `/mfa`, `/mfa/setup`, `/forgot-password`, `/set-password`.
- `src/components/app/AppTopbar.vue` has no notices; the unused `DashboardLayout.vue` has a static bell.
- `StaffTable.vue` already hides *Disable* for founders.

Flutter: no use of `/dashboard/*` and no change.

## Technical Context

- **Language/Version**: PHP 8.4 locally (composer platform 8.3+), Laravel 12; Vue 3 + TypeScript (Vuetify 4, Pinia, TanStack Vue Query).
- **Primary Dependencies**: Sanctum, Spatie Permission, Pest 3, l5-swagger, pragmarx/google2fa. Reused: `CompleteStaffSignInAction`, `StaffMfaSessionStore` (pattern), `RecordAuditLogAction`, `ReasonRule`, `LocksStaffAuthorization` (pattern; a separate lock key), `SmsChannel`, `InboxChannel` (pattern), `FounderAlertNotification` (pattern), the `idempotent` middleware, `db.elevate:bootstrap`, `RequestContext::forSystem()`.
- **Storage**: PostgreSQL 16. Tests run on **`dahab_wt021`** and dev data on **`dahab_wt021_dev`**. Both are to be created by the owner (the `dahab` user has no CREATEDB). **Never the primary `dahab`.** `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` are passed on the command line. Nothing runs until the owner confirms they exist.
- **Testing**: Pest at the HTTP boundary; Dashboard `type-check`, `lint` (touched files only), `build`.
- **Constraints**:
  - no token for a held or frozen founder;
  - no secret (`approval_ref`, device id, codes) in any notice, log or audit payload;
  - the device ref in answers is the first 8 hex characters of the hash;
  - no RLS weakened; public routes keep `db.elevate:bootstrap`;
  - `down()` refuses once spec 021 data exists.
- **Scale/Scope**: 12 endpoints, 1 console command, 1 table, 2 guards, about 70 Pest cases, 1 new Dashboard page, 2 auth screens and 1 topbar menu.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I Named actor | Every approve, refuse, freeze and confirmation names the acting staff member. The held request is attributed to the founder signing in. The console trust is attributed to the system actor with the founder as entity. A frozen founder's narrow call is attributed to them after the password check, like a sign-in. | PASS |
| II Isolation / permission data | `founder.unfreeze_second` is a catalogue permission held through roles. Founder-only gating uses the immutable flag (R13), never a role name. No customer table is touched, and no RLS change. | PASS |
| III Docs are the source of truth | Technical Spec Part 1 §8, §9 (codes), §10 (OI-1.2/1.3 partly answered for founders), Part 2 §10, `dahab-dashboard-authorization.md` get "Changed by spec 021" notes. Also `docs/platform/api-contract.md`, `05_schema_security.sql`, `CLAUDE.md` current state, `docs/features/founder-security.md`. | PASS (gated by tasks) |
| IV Foundation before modules | Builds on specs 001, 002, 019 and 020. Every endpoint has an Action, FormRequest/Resource, `#[OA]`, and happy + refusal Pest tests. | PASS |
| V Test the boundary and the ledger | Every state change goes through HTTP (and the console through `artisan()`). No money moves. The DB guards are tested by direct `UPDATE`s expecting DH021/DH022. | PASS |

Post-design re-check: **PASS**.

## Project Structure

### Documentation (this feature)

```text
specs/021-founder-security/
├── spec.md            # + decisions D21-1…4, clarifications C1–C2
├── plan.md            # this file
├── research.md        # R1–R18
├── data-model.md
├── contracts/founder-security-api.md
├── quickstart.md
└── tasks.md
```

### Source code (planned)

```text
Backend  app/Actions/Auth/Staff/          FounderDeviceGate, CollectDeviceApprovalAction, ConfirmFrozenFounderUnfreezeAction,
                                          (Login|VerifyStaffMfa|EnrollStaffMfa)Action call the gate; CompleteStaffSignInAction keeps first_seen_at
         app/Actions/FounderSecurity/     DecideDeviceApprovalAction, FreezeFounderAction, ConfirmUnfreezeAction, TrustFounderDeviceAction,
                                          ListDeviceApprovalsAction, ListFreezesAction, ListFounderDevicesAction
         app/Actions/Notices/             ListStaffNoticesAction, MarkStaffNoticeReadAction
         app/Support/Auth/StaffTotpVerifier.php        (extracted from VerifyStaffMfaAction, shared with the narrow path)
         app/Support/FounderSecurity/FounderAlerts.php (recipients, channels, content)
         app/Notifications/FounderSecurityNotification.php, app/Notifications/Channels/StaffNoticeChannel.php
         app/Http/Middleware/EnsureFounder.php (staff.founder, staff.founder_or:<perm>)
         app/Http/Controllers/Api/V1/Dashboard/Auth/FounderDeviceApprovalStatusController.php, FrozenFounderController.php
         app/Http/Controllers/Api/V1/Dashboard/FounderSecurityController.php, StaffNoticeController.php
         app/Http/Requests/…  (DeviceApprovalStatusRequest, FrozenConfirmUnfreezeRequest, DecideDeviceApprovalRequest, FreezeFounderRequest,
                               ConfirmUnfreezeRequest, ListDeviceApprovalsRequest, ListFreezesRequest, ListStaffNoticesRequest)
         app/Http/Resources/  (DeviceApprovalResource, AccountFreezeResource, FounderDeviceResource, StaffNoticeResource)
         app/Console/Commands/TrustFounderDevice.php
         app/Models/{FounderDeviceApproval, AccountFreeze, StaffNotice, Staff}; factories
         app/Enums/{AuditEvent, AuthErrorCode, StaffPermission, StaffNoticeKind}; app/Exceptions/DomainApiException
         config/dahab-auth.php; AppServiceProvider limiter; routes/api.php
         database/migrations/2026_10_14_000010_founder_security.php
Dashboard src/api/{axios.ts (X-Device-Id), endpoints.ts, device.ts (new: id in localStorage)}
         src/types/{staff.ts, api.ts, security.ts (new), notices.ts (new)}
         src/services/{staff-auth.service.ts, security.service.ts (new), notices.service.ts (new)}
         src/stores/auth.ts (held state + polling), src/router/index.ts (guest /device-approval, /frozen; /dashboard/security)
         src/pages/{device-approval.vue, frozen.vue, login.vue, mfa.vue, mfa-enroll.vue, security/index.vue}
         src/components/security/{PendingApprovals, FreezePanel, FreezeModal, UnfreezeModal, FounderDevices, SecurityHistory}.vue
         src/components/app/{AppTopbar.vue, NoticesMenu.vue (new)}, src/components/app/AppSidebar.vue (Security item, founders + holders)
         src/mock/* only where the existing mock layer requires a stub
```

## Phases and order

| Phase | Content | Depends on |
|---|---|---|
| **1 Setup** | Databases exist and Q1–Q3 are answered (verify with `psql -l`); branches when asked; worktree `vendor/` + `.env`; feature doc | — |
| **2 Foundational** | Migration, schema mirror, models + factories, enums/codes/events/permission, config, limiter, `staff.founder` middleware, notice table + channel + `FounderAlerts` + the audit hook | 1 |
| **3 US1 Held sign-in** (P1) | gate → collect → approve/refuse → console → controllers/routes/OA → Pest → Postman → Dashboard: `X-Device-Id`, held screen, Security page approvals | 2 |
| **4 US2 Freeze / unfreeze** (P1) | freeze → confirm (staff) → confirm (frozen founder, narrow) → controllers/routes/OA → Pest → Postman → Dashboard: freeze/unfreeze UI, frozen sign-in screen | 2; the narrow path uses US1's known-device check |
| **5 US3 Notices** (P2) | founder-alert set wired to the hook → notification content → notices endpoints → Pest → Postman → Dashboard bell | 2 (hook); events from 3 and 4 |
| **6 Docs and verify** | OpenAPI, inventories, api-contract, Technical Spec notes, `CLAUDE.md`, one full Pest run in the background, Dashboard checks, Step 5 report | all |

Inside each phase the order is Backend → contract → Dashboard.

## Risks and watch-points

- **The first-mover problem (Story 2).** A compromised founder can freeze the honest one first, and the system can't tell them apart. Mitigation: freeze and unfreeze alert both founders by email + SMS + notice instantly, and slot 2 has no stand-in, so the attacker also can't unfreeze themself if frozen. Recovery is manual (D21-1). **This should be written into OI-1.2 when the owner writes the recovery procedure.**
- **Deploy order.** The Backend must not reach production before the Dashboard sends `X-Device-Id`, or every founder sign-in is refused `device_id_required`. Ship both together, and run `founder:trust-device` for each founder on the server right after the migration (quickstart §3). Locally, `DAHAB_AUTH_FOUNDER_DEVICE_APPROVAL=false` avoids being held.
- **Device id = browser storage.** Clearing site data makes the browser a new device, which means a new approval. That is intended; it's covered in the held screen's text.
- **Known devices are never removed in the system** (not in the spec). After a confirmed compromise, removing a stolen device's row is part of the manual recovery (D21-1). The Security page lists the devices so the founders can see them.
- **Existing tests** that sign founders in through `/dashboard/auth/*` will be held. T019 finds and fixes them with `withKnownDevice()`.
- **The notice hook runs on every audit write.** It's an in-memory `in_array` on the event set; the founder lookup only happens for those events. Nothing is sent inside the transaction (afterCommit).
- **SMS needs `staff.phone`.** It's nullable; the quickstart checks both founders have one. Without one, the founder gets email + notice and the app log gets a warning.
- **`account_frozen` is still the message a frozen founder sees at sign-in.** The Dashboard adds the plain text and the *Confirm my unfreeze* form. The freeze reason is **not** shown to the frozen account, since it may be read by the attacker.

## Questions for the owner (answered 2026-10-11: the planned defaults)

| ID | Question | Answer |
|---|---|---|
| **Q1** | `founder.unfreeze_second` can only be granted by someone who holds it (`RoleEscalationGuard`). Give it to the `ceo` role in the migration so the CEO can name a stand-in from the Roles page? Holding it never lets a founder confirm slot 1 for someone else; founders always confirm as slot 2. **Yes**, `ceo` role only (research R14) |
| **Q2** | Story 3 lists created and disabled staff. Also alert on `authz.staff.enabled` and on role/permission changes made by a founder (`authz.staff.roles_changed`, `authz.role.permissions_changed`)? **No**, the spec list only (R11) |
| **Q3** | A device refusal: offer *Freeze* right next to it on the Security page, as a separate confirmed action (never automatic)? | **Yes** |
