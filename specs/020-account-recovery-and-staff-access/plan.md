# Implementation Plan: Account recovery and staff access

**Branch**: `feature/account-recovery` in all three repositories (to be created from each `main` **only when the owner asks**; this planning session works on the Backend branch it was given) | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)

**Status**: Plan only. Nothing is implemented, committed or pushed. Decisions D20-1…5 are the owner's; C1–C6 in the spec are planned on the recommended answer and wait for confirmation.

**Bases verified**: Backend `main` 72424da (spec 019 merged), Dashboard `main` 5101f84, Customer app `main` b2b9b96 (all read from GitHub `origin/main`; the local-only merges named in the session prompt are now pushed).

## Summary

Three small flows on top of machinery that already exists.

- **Customer forgot password** (US1): `reset-request` texts a 6-digit code to the phone on file and always answers the same; `reset-confirm` takes phone + code + new password. The code lives in an encrypted cache challenge (like the sign-in and contact-change codes), not in `one_time_token` (R1). A success changes the hash, ends **every** session and forgets **every** trusted device, runs the spec 013 safety stop (cancels unreleased withdrawals, opens the 24 h pause, new trigger `password_reset` — D20-2, C2), clears the sign-in lockout, tells the customer on SMS + email + inbox, and is audited.
- **Staff recovery** (US2): `reset-request` mails a single-use link (`one_time_token`, purpose `password_reset_staff`, HMAC at rest — the spec 013/017 link pattern); `reset-confirm` consumes it, sets the password, ends every staff session. MFA is untouched, so the next sign-in still needs the authenticator (R4).
- **Staff lifecycle** (US3): `POST /dashboard/staff`, `POST /dashboard/staff/{id}/disable|enable`, new permission `staff.manage` (CEO only). Creation makes a staff row with **no password row** and mails the same link; roles go through the existing escalation guard and reason rule; disabling is `is_active = false` + token revocation, with the self / founder / last-role-manager guards.

## Impact analysis

```
Backend:          YES — Actions/Auth/Customer/{RequestPasswordResetAction, ConfirmPasswordResetAction}, Actions/Auth/Staff/{RequestStaffPasswordResetAction,
                  ConfirmStaffPasswordResetAction, IssueStaffPasswordLinkAction}, Actions/Authorization/{CreateStaffAction, DisableStaffAction,
                  EnableStaffAction}, Services/PasswordResetChallengeStore, 3 notifications (reset code SMS, staff link mail, account notice event),
                  2 controllers extended + routes, 4 FormRequests, limiters, config, ListStaffAction filter, StaffMemberResource field
Database:         YES — 1 migration: widen withdrawal_pause.trigger_kind CHECK ('password_reset'); seed permission staff.manage to the CEO role.
                  No new table, no new column (staff.is_active, staff_password, one_time_token already fit — see data-model.md)
API:              YES — 7 new endpoints (4 public auth, 3 dashboard; see contracts), 1 new optional list filter, 1 new optional response field,
                  1 new value in an existing response enum (withdrawal_pause.trigger_kind)
Dashboard:        YES — Forgot password? + set-password pages (public), Staff page: Add staff member, Disable / Enable, Disabled tab; types, services, mock
Customer App:     YES — Forgot password? two-step flow (phone → code + new password), error mapping, text keys (EN + AR-DRAFT), tests; the "not available yet" toast goes
Auth:             YES — two public flows, token revocation on reset/disable; no change to login, MFA or refresh
Permissions:      YES — staff.manage (new, CEO only by sync). Existing: roles.manage / staff.view unchanged
API models/types: YES — Dashboard types/staff.ts, types/access.ts, types/customer.ts (WithdrawalPauseTrigger + label map); Flutter lib/services/auth/auth_api.dart + a reset model
```

**Classification**
- New endpoints, new optional `password_set` field, new optional `status` filter: **non-breaking**.
- New `trigger_kind` value `password_reset` in the Customer-file response: **potentially breaking** — the Dashboard has an exhaustive label map (`src/types/customer.ts:187-192`); the Dashboard task lands with the Backend change. Flutter does not read `trigger_kind` (verified by grep).
- New permission `staff.manage`: non-breaking (nobody holds it until the migration gives it to the CEO).
- New error codes `reset_link_invalid`, `founder_protected`, `staff_already_disabled`, `staff_not_disabled`: each only on new endpoints.

**Consumers checked** (Dashboard `5101f84`, Flutter `b2b9b96`): no existing code calls any reset or staff-create endpoint. Flutter has a stub — `lib/features/auth/login_screens.dart:79` shows the toast *Password reset is not available yet* (key `auth.password_reset_is_not_available_yet`, retired by this spec). Dashboard `login.vue` has no forgot link; `router/index.ts` has three public guest routes (login, mfa, mfa-enroll) to extend; `pages/staff/index.vue` has the Staff / Roles tabs.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 12; Vue 3 + TypeScript (Vuetify 4, Pinia, TanStack Vue Query); Dart ^3.11, Flutter Web.
**Primary Dependencies**: Sanctum, Spatie Permission, Pest 3, l5-swagger; reused: `RecordAuditLogAction`, `WithdrawalSafetyStop`, `CustomerSessions`, `ConfirmationTokens`, `LocksStaffAuthorization`, `RoleEscalationGuard`, `ReasonRule`, `Templates`, `AccountNotification`, `PhoneChangeCodeNotification` (pattern), `FounderAlertNotification` (staff mail pattern), the `idempotent` middleware, `db.elevate:bootstrap`.
**Storage**: PostgreSQL 16 (tests on **`dahab_wt020`**, dev on **`dahab_wt020_dev`** — to be created by the owner; the `dahab` user has no CREATEDB; **never the primary `dahab`**); Redis/cache for the code challenge. PostgreSQL is not running in this planning environment.
**Testing**: Pest at the HTTP boundary; Dashboard `type-check`/`lint`/`build`; Flutter `analyze`/`test`/`build web`.
**Constraints**: no endpoint ever returns or sets a known password or the code/token (except the link in the mail itself); uniform answers on both `reset-request` endpoints; no RLS weakened; staff routes keep `db.elevate:bootstrap`; the migration's `down()` refuses while `password_reset` pauses exist (the 017 pattern).
**Scale/Scope**: 7 endpoints, ~25 new text keys in the app, 4 Dashboard screens/dialogs, ~45 Pest cases.

## Constitution Check

| Principle | Check | Result |
|---|---|---|
| I Named actor | Staff creation/disable/enable name the acting staff member and the target; customer reset is attributed to the customer (`actorCustomerId`); a staff self-service recovery is attributed to that staff member; unknown-account requests fall back to the app log (as sign-in does) | PASS |
| II Isolation / permission data | `staff.manage` is a catalogue code held by data (CEO row), editable; customer reads/writes run under the existing bootstrap elevation like sign-in; no new RLS table | PASS |
| III Docs are the source of truth | Technical Spec Part 1 §2.1, Part 2 §1 and §10, authorization §43 get "Changed by spec 020" notes; `docs/platform/api-contract.md`; schema SQL mirror; `CLAUDE.md` current state; `docs/features/account-recovery.md` | PASS (gated by tasks) |
| IV Foundation before modules | Builds on spec 001/002/013/017 pieces; every endpoint has Action, FormRequest/Resource, `#[OA]`, happy + refusal Pest tests | PASS |
| V Test the boundary and the ledger | Every state change through HTTP; the withdrawal cancellation on reset is asserted on the ledger (money back to available, entries balanced) | PASS |

Post-design re-check: **PASS**.

## Project Structure

### Documentation (this feature)

```text
specs/020-account-recovery-and-staff-access/
├── spec.md        # + decisions D20-1…5, clarifications C1–C6
├── plan.md        # this file
├── research.md    # R1–R14
├── data-model.md
├── contracts/account-recovery-api.md
├── quickstart.md
└── tasks.md
```

### Source code (planned)

```text
Backend  app/Actions/Auth/Customer/      RequestPasswordResetAction, ConfirmPasswordResetAction
         app/Actions/Auth/Staff/         RequestStaffPasswordResetAction, ConfirmStaffPasswordResetAction, IssueStaffPasswordLinkAction
         app/Actions/Authorization/      CreateStaffAction, DisableStaffAction, EnableStaffAction (+ ListStaffAction status filter)
         app/Services/                   PasswordResetChallengeStore
         app/Notifications/              PasswordResetCodeNotification (SMS), StaffPasswordLinkNotification (mail); AccountEvent::PASSWORD_RESET
         app/Http/Controllers/Api/V1/    Customer/Auth/CustomerPasswordResetController, Dashboard/Auth/StaffPasswordResetController, Dashboard/StaffController (+3)
         app/Http/Requests/              Auth/Customer/{PasswordResetRequestRequest, PasswordResetConfirmRequest}, Auth/Staff/{StaffResetRequestRequest, StaffResetConfirmRequest}, Dashboard/Authorization/{CreateStaffRequest, StaffStatusChangeRequest}
         app/Enums/                      PauseTrigger::PASSWORD_RESET, StaffPermission::STAFF_MANAGE, AuditEvent (4), AccountEvent::PASSWORD_RESET
         config/dahab-auth.php           reset code/link settings; config/dahab-account.php unchanged
         database/migrations/2026_10_13_000010_account_recovery.php
Dashboard src/pages/{forgot-password,set-password}.vue, src/pages/staff/index.vue, src/components/staff/{AddStaffModal,StaffStatusModal}.vue,
         src/services/{staff-auth,access}.service.ts, src/types/{staff,access,customer}.ts, src/api/endpoints.ts, src/router/index.ts, src/mock/*
Flutter  lib/features/auth/{forgot_password_screen,reset_password_screen}.dart, lib/services/auth/auth_api.dart, auth_messages.dart, lib/routing/routes.dart,
         lib/core/text/keys/auth_reset.dart (+ manifest), test/{account_flows,flows}_test.dart
```

## Phases and order

| Phase | Content | Depends on |
|---|---|---|
| **1 Setup** | owner creates `dahab_wt020` / `dahab_wt020_dev`; feature doc; branches when asked | — |
| **2 Foundational** | migration (CHECK + `staff.manage`), enums, error codes, audit events, limiters, config, schema SQL mirror | 1 |
| **3 US1 Customer reset** (P1) | store → actions → notifications/templates → request/controller/routes/OA → Pest → Postman → Dashboard label → Flutter flow | 2 |
| **4 US2 Staff recovery** (P1) | link issuer → request/confirm actions → mail → controller/routes/OA → Pest → Postman → Dashboard pages | 2 (shares the issuer with 5) |
| **5 US3 Staff lifecycle** (P2) | create / disable / enable actions → requests/resource/controller/OA → Pest → Postman → Dashboard Staff page | 4 (invitation link) |
| **6 Docs and verify** | OpenAPI, api-contract, Technical Spec notes, `CLAUDE.md`, text sync, one full run, Step 5 report | all |

Inside each phase: Backend → contract → Dashboard → Customer App. Phases 3 and 4 are independent after 2.

## Risks and watch-points

- **Account takeover by SIM swap** is the main risk of an SMS-only reset (D20-1). Mitigations in the plan: code expires in 5 min and is voided after 5 wrong tries, 3 requests/hour/phone, every session and device ended, the 24 h withdrawal pause with unreleased withdrawals cancelled (C2), and the customer told on SMS + email + inbox.
- **Enumeration**: both `reset-request` answers are byte-identical for known, unknown, closed and (staff) disabled; the code path does equal work (a dummy hash on the unknown branch). A test compares the response bodies.
- **Lost authenticator**: a staff member who lost their authenticator has no path (spec 021 / OI-1.2 own it). Recovery never re-enrols MFA (R4).
- **Mailbox-only staff** (no MFA required for their role and not enrolled): a recovery link then gives sign-in with no second factor. This is inherent to D20-3; the Dashboard Staff page shows MFA state already (`mfa_enrolled`).
- **Staff email delivery**: if the invitation mail fails to send, the member can use *Forgot password?* with the same address (the link is not lost forever); no extra "resend" endpoint is invented.
- **Pause trigger value** reaches the Dashboard Customer file — the Dashboard change ships with the Backend migration.
- **Notification golden test (spec 019)**: the new messages must be added to `tests/Support/NotificationCases.php` and the catalogue in the same task, or the inventory tests fail.
- **Arabic**: every new Arabic string stays AR-DRAFT until the owner approves.
