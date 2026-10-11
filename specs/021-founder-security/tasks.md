# Tasks: Founder-account security (spec 021)

**Input**: `specs/021-founder-security/`: spec.md (D21-1…4, C1–C2), plan.md (Q1–Q3 answered: Q1 yes, Q2 no, Q3 yes), research.md (R1–R18), data-model.md, contracts/founder-security-api.md, quickstart.md
**Repos**: `dahab-backend/` (B), `dahab-dashboard/` (D). Branch `feature/founder-security` in each, created only when the owner asks. The Customer app is **not** touched.
**Tests**: required (Constitution V). Pest at the HTTP boundary on **`dahab_wt021` only**, with `DB_DATABASE/DB_USERNAME/DB_PASSWORD` passed explicitly, sequential. **One full run** (T066, in the background, about 70 minutes); targeted files before that; afterwards only failing files, reported to the owner.
**Every endpoint task**: `#[OA]`, the Postman request (and environment variable) in the same task, and audit through `RecordAuditLogAction`. Error codes, audit events and the permission are added in Phase 2 together with their catalogue/inventory tests and `docs/platform/api-contract.md`.
**Git**: no commit or push unless the owner asks. No `Co-Authored-By`, no `Claude-Session`, no "Generated with", no AI/model name in any git message. Never change `user.name`/`user.email`.
**Texts**: staff-facing English only, like `FounderAlertNotification`; no customer-visible text, so no app-text keys and no AR-DRAFT.
**Line endings**: Dashboard files mix CRLF and LF per file. Detect them in Python and edit with an EOL-aware helper written with the Write tool.

Format: `- [ ] T### [P?] [US?] description — repo` · `[P]` = parallel (different files, no dependency) · **[D]** in the description marks a Dashboard task.

## Phase 1: Setup

- [x] T001 The owner created `dahab_wt021` and `dahab_wt021_dev` and answered Q1–Q3 (defaults: Q1 yes, Q2 no, Q3 yes). Verify the databases with `psql -l`; if they're missing, STOP and never fall back to `dahab` — B
- [x] T002 When asked: create `feature/founder-security` from `main` in B and D (or a B worktree: `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`, copy the main `.env`) — B, D
- [x] T003 [P] `docs/features/founder-security.md` from `_TEMPLATE.md`: impact analysis, classification, consumers, D21/C1–C2/Q answers — B

## Phase 2: Foundational (blocks all stories)

### Migration and schema (skill `laravel-migrations`)
- [x] T004 `database/migrations/2026_10_14_000010_founder_security.php` per data-model §1:
  - `founder_device_approval` columns, CHECKs, indexes and the DH021 trigger;
  - `account_freeze` columns (`reason` backfilled, then `NOT NULL`), CHECKs, the unique open-freeze index and the DH022 trigger;
  - the `staff_notice` table and indexes;
  - permission `founder.unfreeze_second` (+ `ceo` role if Q1 = yes);
  - `down()` refusing once spec 021 data exists
  — B
- [x] T005 [P] Mirror T004 in `docs/Database schema/05_schema_security.sql` (new "spec 021" section), add a pointer comment beside the two tables in `02_schema_identity.sql`, and update `00_schema_full.sql` — B
- [x] T006 [P] `tests/Feature/Migrations/FounderSecurityMigrationTest.php`:
  - columns and CHECKs exist;
  - DH021: delete, re-decide, decide after expiry, extend `expires_at`, consume an unapproved row;
  - DH022: delete, change reason, slot 2 before slot 1, same person twice, non-founder slot 2, non-founder `frozen_by`, touch an unfrozen row;
  - the second open freeze fails;
  - `down()` refuses with data and runs clean without
  — B

### Models and factories (skill `laravel-eloquent-models`)
- [x] T007 [P] `FounderDeviceApproval`: fillable, casts, `status()`, relations, scopes `pending()` / `forApprover()`. `AccountFreeze`: new columns, relations, `stage()` — B
- [x] T008 [P] New `StaffNotice` model. `Staff`: `notices()`, `deviceFingerprints()`, `isKnownDevice()` — B
- [x] T009 [P] Factories:
  - `FounderDeviceApprovalFactory` (pending / approved / refused / expired / consumed);
  - `AccountFreezeFactory` (firstConfirmed / unfrozen);
  - `StaffNoticeFactory`;
  - `StaffFactory::withKnownDevice()`;
  - `StaffFactory::frozen()` gains a `reason`
  — B

### Catalogue, codes, config
- [x] T010 [P] `StaffPermission::FOUNDER_UNFREEZE_SECOND` (label, group, seed role `ceo` — Q1); update `tests/Feature/Authorization/PermissionCatalogueTest.php` — B
- [x] T011 [P] `AuditEvent`: the 9 `founder.*` events (data-model §3), category Accounts, `founderAlert()` set (R11); update `tests/Unit/Audit/AuditCatalogueTest.php` — B
- [x] T012 [P] Error codes:
  - `AuthErrorCode` gains `device_id_required`, `device_approval_refused` and `device_approval_invalid`;
  - `DomainApiException` gets the 9 factories (R16), plus the DH021/DH022 SQLSTATE mapping;
  - the error-code inventory test and `docs/platform/api-contract.md` error table
  — B
- [x] T013 [P] `config/dahab-auth.php`: `founder_device_approval_enforced` (forced on in production, like `mfa_enforced`), `founder_device_approval.ttl_minutes` = 15, `poll_interval_seconds` = 5. `.env.example`: `DAHAB_AUTH_FOUNDER_DEVICE_APPROVAL=true` — B
- [x] T014 Limiter `auth.staff.device_approval` (30 / min per `approval_ref` hash, 60 / min per IP) in `AppServiceProvider` — B
- [x] T015 Middleware `EnsureFounder`, registered as `staff.founder` (→ `403 founder_only`) and `staff.founder_or:<permission>`; alias in `bootstrap/app.php` — B
- [x] T016 Extract `App\Support\Auth\StaffTotpVerifier` (TOTP with the time-step replay guard + recovery-code redemption) from `VerifyStaffMfaAction` and keep `VerifyStaffMfaAction` using it. Existing MFA tests must stay green (targeted run) — B

### Notices channel and the audit hook (skill `laravel-queues-notifications`)
- [x] T017 `StaffNoticeKind` enum; `StaffNoticeChannel` (writes `staff_notice`, modelled on `InboxChannel`); `FounderSecurityNotification` (`via`: mail + staff notice, + `sms` when asked; English; params never carry secrets; `ShouldQueue`, `afterCommit`, 3 tries) — B
- [x] T018 `App\Support\FounderSecurity\FounderAlerts::dispatch(AuditLog $row)`:
  - recipients are every active founder, frozen included;
  - the condition is per R11 (sensitive set → only when the actor is a founder; `founder.*` → always);
  - SMS only for `founder.frozen`, with `founder_alert.sms_skipped` in the app log when there's no phone;
  - content per R11: label, actor or "System", Cairo time, IP, device ref, link

  Hook it into `RecordAuditLogAction` via `DB::afterCommit` (immediately when no transaction is open) — B

**Checkpoint**: `php artisan migrate` on `dahab_wt021`; the T006/T010/T011/T012 tests and the existing `tests/Feature/Auth/Staff` pass (targeted).

## Phase 3: US1 — A new founder device is held until the other founder approves (P1) 🎯

### Backend
- [x] T019 [US1] Find the existing tests that sign founders in through `/dashboard/auth/*`:
  - `tests/Feature/Auth/Staff/StaffProfileShapeTest.php`
  - `tests/Feature/Auth/StaffPasswordResetTest.php`
  - `tests/Feature/Authorization/SystemActorTest.php`
  - `tests/Feature/Authorization/StaffMfaRequirementTest.php`
  - `tests/Feature/Authorization/StaffLifecycleTest.php`
  - `tests/Feature/Auth/Staff/*` (grep again)

  Give their founders `withKnownDevice()` and send `X-Device-Id`, so they keep testing what they tested — B
- [x] T020 [US1] `CompleteStaffSignInAction`: keep `first_seen_at` on an existing row (R2); accept `via: device_approval` — B
- [x] T021 [US1] `FounderDeviceGate` (skill `laravel-actions-services`). For a founder when enforced:
  - no fingerprint → `device_id_required`;
  - known device → pass;
  - otherwise, in one transaction: end earlier pending rows for the device (R5), create the row (`expires_at` +15 min, `ref_hash`, platform, user agent, IP), audit `founder.device_approval_requested`.

  Return the held payload (R18). Call it from `LoginStaffAction` (no-MFA path), `VerifyStaffMfaAction` and `EnrollStaffMfaAction` before `CompleteStaffSignInAction` — B
- [x] T022 [US1] `CollectDeviceApprovalAction`. Under a row lock: find by `ref_hash`, then match the fingerprint; then by state:
  - pending → pending;
  - refused → `device_approval_refused`;
  - expired, consumed or another device → `device_approval_invalid`;
  - approved → re-check `is_active` / freeze, set `consumed_at`, run `CompleteStaffSignInAction(via: device_approval)`, audit `founder.device_approval_collected`
  — B
- [x] T023 [US1] `DecideDeviceApprovalAction` (approve | refuse, optional note):
  - the actor is a founder, not frozen, not the requester (`founder_self_action`);
  - the row must be pending (`device_approval_not_pending`);
  - audit `founder.device_approved` / `founder.device_refused` with the note as reason
  — B
- [x] T024 [P] [US1] `ListDeviceApprovalsAction` (status filter, `can_decide`, `pending_for_me`) and `ListFounderDevicesAction` — B
- [x] T025 [US1] `TrustFounderDeviceAction` + command `founder:trust-device {email} {device-id} {--platform=web} {--force}`:
  - refuses a non-founder, a disabled member or a frozen founder;
  - upserts the fingerprint, ends pending approvals for that device;
  - audits `founder.device_trusted_by_console` as the system actor
  — B
- [x] T026 [US1] Requests `DeviceApprovalStatusRequest`, `DecideDeviceApprovalRequest` (`note` ≤ 500), `ListDeviceApprovalsRequest`; Resources `DeviceApprovalResource` (device ref = 8 hex characters, never the ref or full hash), `FounderDeviceResource`; `#[OA\Schema]` incl. `StaffDeviceApprovalPending` — B
- [x] T027 [US1] Controllers + routes:
  - `POST /dashboard/auth/device-approval/status` (public, `db.elevate:bootstrap`, `throttle:auth.staff.device_approval`);
  - `GET /dashboard/founder/device-approvals`, `POST …/{id}/approve|refuse` (`idempotent`), `GET /dashboard/founder/devices` (`staff.founder`);
  - `#[OA]` on all, plus the new `oneOf` member on `login`, `mfa/verify` and `mfa/enroll`
  — B
- [x] T028 [US1] Pest `tests/Feature/FounderSecurity/DeviceApprovalTest.php` (skill `laravel-pest-testing`):
  - non-founder and known-device founder unchanged;
  - unknown device after MFA → held, no token rows, row + audit, the other founder notified;
  - no `X-Device-Id` → 422 and no row;
  - poll pending / approved (session works, device now known, second poll 401) / refused 403 / expired 401 / other device 401;
  - approve own → 403 (and the DB CHECK on a forced write);
  - decide twice → 409;
  - decide after 15 min → 409;
  - a second held sign-in ends the first;
  - frozen or disabled while waiting → no token;
  - enroll path held too;
  - `enforced=false` → no hold;
  - poll throttle;
  - list filters and `can_decide`;
  - the response bodies never contain `approval_ref` except the held answer
  — B
- [x] T029 [P] [US1] Pest `tests/Feature/FounderSecurity/TrustDeviceCommandTest.php`: trusts, ends a pending request, audits as system, refuses non-founder/disabled/frozen — B
- [x] T030 [US1] Postman: *Dashboard → Auth → Device Approval Status*. The *MFA Verify* test script saves `approval_ref` when held. *Dashboard → Founder → Device Approvals / Approve / Refuse / Devices*. Environment: `device_id`, `approval_ref`, `approval_id`. All dashboard requests send `X-Device-Id: {{device_id}}` (collection-level header) — B

### Dashboard
- [x] T031 [P] [US1] **[D]** `src/api/device.ts`: create `crypto.randomUUID()` once, keep it in `localStorage` (`dahab.device_id`, in try/catch), expose `deviceId()`. `src/api/axios.ts` sends `X-Device-Id` and `X-Device-Platform: web` on every request — D
- [x] T032 [US1] **[D]** Types:
  - `src/types/api.ts`: `ApiDeviceApprovalPending`, `ApiDeviceApprovalStatus`;
  - `src/types/staff.ts`: `StaffLoginResult` and `verifyMfa`/`enrollMfa` results gain `device_approval_required`;
  - `src/types/security.ts` (new): DeviceApproval, FounderDevice

  `src/api/endpoints.ts` gets the new paths — D
- [x] T033 [US1] **[D]** `src/services/staff-auth.service.ts`: login/verify/enroll map the held variant; `pollDeviceApproval(ref)`. `src/services/security.service.ts` (new): list/approve/refuse/devices — D
- [x] T034 [US1] **[D]** `src/stores/auth.ts`:
  - `LoginOutcome` gains `device_approval_required`;
  - a `pendingApproval` state;
  - polling at `poll_interval_seconds`, stopping on approved / refused / invalid / frozen, the countdown, or cancel;
  - start the session on approved
  — D
- [x] T035 [US1] **[D]** `src/pages/device-approval.vue` (guest route `/device-approval`): "Waiting for the other founder to approve this device", the countdown, this device's id with *Copy* (for the server command), *Cancel*, and the refused/ended messages. `login.vue`, `mfa.vue` and `mfa-enroll.vue` route there on the held outcome — D
- [x] T036 [US1] **[D]** `src/pages/security/index.vue` (`/dashboard/security`, founders only; route meta + guard on `isFounder`) with `components/security/PendingApprovals.vue` (Approve / Refuse with optional note, empty/loading/error states, refetch every 15 s) and `FounderDevices.vue`; sidebar item *Security* in `AppSidebar.vue` for founders — D

**Checkpoint**: targeted Pest for T028/T029 + the T019 files; D `npm run type-check`, `lint` on touched files, `build`.

## Phase 4: US2 — Freeze and dual unfreeze (P1)

### Backend
- [x] T037 [US2] `FreezeFounderAction`. Lock key `dahab.founder_security` + both staff rows in id order. Then:
  - actor not frozen (`account_frozen`);
  - target is another active founder (`freeze_target_invalid` / `founder_self_action`);
  - no open freeze (`already_frozen`);
  - `ReasonRule`;
  - insert, delete every target token, end pending approvals;
  - audit `founder.frozen` with the reason;
  - return the counts
  — B
- [x] T038 [US2] `ConfirmUnfreezeAction` (staff session). Slot by the server:
  - founder not frozen → slot 2 (`unfreeze_order` if slot 1 is empty) → set `unfrozen_at`, audit `founder.unfreeze_confirmed` + `founder.unfrozen`;
  - else holder of `founder.unfreeze_second` → slot 1 `second_approver` (`unfreeze_already_confirmed` if filled);
  - else `permission_denied`;
  - `not_frozen` for a closed freeze;
  - row lock;
  - optional note as reason
  — B
- [x] T039 [US2] `ConfirmFrozenFounderUnfreezeAction` (public, tokenless; order of checks in R16): password (uniform `invalid_credentials`, audited like a failed sign-in) → open freeze (`not_frozen`) → known device (`device_id_required` / `device_not_known`) → `StaffTotpVerifier` (`mfa_invalid`) → slot 1 free (`unfreeze_already_confirmed`) → slot 1 `frozen_founder`. Audit success / `founder.unfreeze_confirm_failed`. Never mint a token — B
- [x] T040 [P] [US2] `ListFreezesAction` (`state=open|all`, confirmations, `can_confirm`, `my_slot` for the viewer) — B
- [x] T041 [US2] Requests `FreezeFounderRequest` (`staff_id` uuid, `reason` required ≤ 1000), `ConfirmUnfreezeRequest` (`freeze_id`, `note` ≤ 500), `FrozenConfirmUnfreezeRequest` (email, password, `code` digits:6 xor `recovery_code`, `note`), `ListFreezesRequest`; `AccountFreezeResource`; `#[OA\Schema]` — B
- [x] T042 [US2] Routes and `#[OA]`:
  - `POST /dashboard/founder/freeze` (`staff.founder`, `idempotent`);
  - `GET /dashboard/founder/freezes`, `POST /dashboard/founder/unfreeze` (`staff.founder_or:founder.unfreeze_second`, `idempotent` on POST);
  - `POST /dashboard/auth/frozen/confirm-unfreeze` (public, `db.elevate:bootstrap`, `throttle:auth.staff.login`, `throttle:auth.staff.mfa`)
  — B
- [x] T043 [US2] Pest `tests/Feature/FounderSecurity/FreezeTest.php`:
  - freeze happy path: tokens gone, every route 403 `account_frozen` and audited, login 403, pending approvals ended, audit with reason, email + SMS + notice to both founders;
  - no reason 422;
  - self 403;
  - non-founder / unknown / system 422;
  - already 409;
  - a frozen founder can't freeze (403);
  - crossing freezes leave exactly one frozen (two sequential calls; the second actor frozen → 403);
  - logout still works for the frozen account
  — B
- [x] T044 [US2] Pest `tests/Feature/FounderSecurity/UnfreezeTest.php`:
  - frozen founder narrow path:
    - happy path, slot 1, no token rows;
    - wrong password uniform 401;
    - not frozen 409;
    - no header 422;
    - unknown device 403;
    - wrong TOTP 401;
    - replayed TOTP 401;
    - recovery code works once;
    - limiter;
  - second approver slot 1, then founder slot 2 → unfrozen, old tokens still gone, sign-in works again;
  - founder before slot 1 → 409;
  - slot 1 twice → 409;
  - the freezer can't be both;
  - a non-holder non-founder → 403;
  - a closed freeze → 409;
  - a founder holding `founder.unfreeze_second` still lands in slot 2;
  - each confirmation audited + notified
  — B
- [x] T045 [US2] Postman: *Dashboard → Founder → Freeze / Freezes / Unfreeze*, *Dashboard → Auth → Frozen: Confirm Unfreeze*; environment `freeze_id`, `founder_staff_id` — B

### Dashboard
- [x] T046 [P] [US2] **[D]** Types and service: `src/types/security.ts` gets `AccountFreeze` and `FreezeConfirmation`; `security.service.ts` gets `freeze`, `listFreezes`, `confirmUnfreeze`; `staff-auth.service.ts` gets `confirmUnfreezeAsFrozen`; endpoints; `PERMISSIONS.founderUnfreezeSecond` in `src/types/staff.ts` — D
- [x] T047 [US2] **[D]** Security page: `FreezePanel.vue` (the other founder with *Freeze*), `FreezeModal.vue` (reason required, a plain warning of the effect), `UnfreezeModal.vue` (shows slot 1/slot 2 state, *Confirm*, optional note), `SecurityHistory.vue` (freezes + decided approvals). Q3: a refused request shows *Freeze* (opens `FreezeModal`, never automatic). The page and sidebar item are also visible to holders of `founder.unfreeze_second`, who see only the freezes section — D
- [x] T048 [US2] **[D]** `src/pages/frozen.vue` (guest route `/frozen`): on `account_frozen` at sign-in, `login.vue` routes here with the plain message "This account is frozen by the other founder. It works again only after both confirmations." (no reason shown, R9), and the form *Confirm my unfreeze* (password + authenticator code, or a recovery code) → shows "Your confirmation is recorded; the other founder confirms last." `staff.standing`'s `account_frozen` on a live session signs out and routes here — D

**Checkpoint**: targeted Pest T043/T044; D type-check / lint on touched files / build.

## Phase 5: US3 — Founders are told in real time (P2)

### Backend
- [x] T049 [US3] Verify that the `founderAlert()` set (T011) covers the Story 3 list: `wallet.adjusted`, `pricing.setting.changed`, `pricing.adjustment.changed`, `promo.created`, `promo.updated`, `pricing.manual_price.entered`, `pricing.manual_price.confirmed`, `authz.staff.created`, `authz.staff.disabled`, and the `founder.*` events. Q2: nothing more — B
- [x] T050 [US3] `FounderSecurityNotification` content per event (subject + lines; device request: platform, IP, time, link `/dashboard/security`; others: link `/dashboard/audit?entry={audit_id}`) — B
- [x] T051 [P] [US3] `ListStaffNoticesAction` (own, `unread` filter, `unread_count`), `MarkStaffNoticeReadAction` (one / all, only your own → 404 otherwise); `ListStaffNoticesRequest`, `StaffNoticeResource` — B
- [x] T052 [US3] Routes `GET /dashboard/notices`, `POST /dashboard/notices/{id}/read`, `POST /dashboard/notices/read-all` (`auth:staff`, `staff.standing`; no permission) + `#[OA]` — B
- [x] T053 [US3] Pest `tests/Feature/FounderSecurity/FounderAlertsTest.php`:
  - each Story 3 event by a founder → mail + notice to every active founder (frozen included), none when the actor isn't a founder;
  - `founder.*` always;
  - SMS only on a freeze, skipped without a phone;
  - nothing sent when the action rolls back;
  - no notice or mail body contains a code, password, `approval_ref`, device id, amount or setting value (each event exercised through its real endpoint with `Notification::fake()` / `Mail::fake()`)
  — B
- [x] T054 [P] [US3] Pest `tests/Feature/FounderSecurity/StaffNoticesTest.php`: own only, unread filter and count, read one / all, someone else's → 404, non-founder staff have an (empty) inbox — B
- [x] T055 [US3] Postman: *Dashboard → Notices → List / Read / Read all*; environment `notice_id` — B

### Dashboard
- [x] T056 [P] [US3] **[D]** `src/types/notices.ts`, `src/services/notices.service.ts`, endpoints — D
- [x] T057 [US3] **[D]** `src/components/app/NoticesMenu.vue` in `AppTopbar.vue`:
  - a bell with the unread badge (TanStack query, refetch 30 s);
  - a list with severity, title, body, time and link;
  - *Mark all read*, and a click marks one read and follows the link;
  - empty/loading/error states
  — D

## Phase 6: Inventories, docs and verify (skill `laravel-quality-gates`)

- [x] T058 Inventory tests:
  - `tests/Feature/Auth/Shared/OpenApiGenerationTest.php`: the 12 new endpoints;
  - `PrincipalIsolationTest` route count (236 + the new staff routes) and its comment;
  - `tests/Feature/Isolation/ElevationTest.php`: the 2 new public `db.elevate:bootstrap` routes in the allow-list;
  - the permission, audit and error-code catalogues (done in Phase 2, re-checked)
  — B
- [x] T059 `composer swagger:generate`; `./vendor/bin/pint --test` on touched files (the two known untouched files excluded) — B
- [x] T060 [P] `docs/platform/api-contract.md`: the held-sign-in variant, `X-Device-Id` required for founders, the new codes, the notices endpoints — B
- [x] T061 [P] Technical Spec "Changed by spec 021" notes:
  - Part 1 §8 (built: how), §9 (codes), §10 (OI-1.2 still open, OI-1.3 answered for founders: D21-3);
  - Part 2 §10 (founder routes);
  - `dahab-dashboard-authorization.md` (founders, frozen accounts, `staff.founder`, `founder.unfreeze_second` in §4)
  — B
- [x] T062 [P] `CLAUDE.md` current state (Backend, Dashboard lines for spec 021); `postman/README.md` (the `X-Device-Id` header, the console command) — B
- [x] T063 [P] `docs/features/founder-security.md` final (T003) — B
- [x] T064 **[D]** `npm run type-check`, `npm run lint` on touched files only, `npm run build` — D
- [x] T065 Migrations: `migrate:fresh` and `migrate:rollback --step=1` on `dahab_wt021` (rollback works on an empty table set, refuses with data) — B
- [x] T066 **One** full sequential Pest run on `dahab_wt021`, in the background (about 70 minutes). Afterwards only failing files, each reported to the owner. `InvoicesPerformanceTest` is known to be timing-sensitive — B
- [x] T067 Step 5 report (platform shape), including the projects intentionally not changed (Customer app: no `/customer/*`, `/market/*` or `/reference/*` change) — B, D

## Dependencies

- Phase 2 blocks everything.
- US1 (Phase 3) before US2 (Phase 4): the narrow unfreeze path uses `isKnownDevice()` and `StaffTotpVerifier`.
- US3 backend (T049–T053) can start after Phase 2, but its tests exercise events from US1 and US2.
- Dashboard tasks follow their Backend tasks in each phase (contract first). T031 (`X-Device-Id`) must ship with the Backend.

## Parallel examples

- Phase 2: T005, T006 (after T004), T007–T013 together.
- Phase 3: T024 alongside T023; T029 alongside T028; D T031 as soon as the phase starts.
- Phase 5: T051 and T054 alongside T050.
