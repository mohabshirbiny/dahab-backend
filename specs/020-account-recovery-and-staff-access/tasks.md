# Tasks: Account recovery and staff access (spec 020)

**Input**: `specs/020-account-recovery-and-staff-access/` — spec.md (D20-1…5, C1–C6), plan.md, research.md (R1–R14), data-model.md, contracts/account-recovery-api.md, quickstart.md
**Repos**: `dahab-backend/` (B), `dahab-dashboard/` (D), `dahab-flutter/` (F) — branch `feature/account-recovery` in each, created only when the owner asks.
**Tests**: required (Constitution V). Pest at the HTTP boundary on **`dahab_wt020` only**, `DB_DATABASE/DB_USERNAME/DB_PASSWORD` passed explicitly, sequential. **One full run** (T052); targeted files before that; then only failing files, reported to the owner.
**Every endpoint task**: `#[OA]`, the Postman request (and environment variable) in the same task, audit through `RecordAuditLogAction`; error codes, audit events and the permission are added **in the phase that uses them** (catalogue, inventory tests, `docs/platform/api-contract.md`). No commit or push unless the owner asks; no `Co-Authored-By`, no `Claude-Session`, no "Generated with", no AI/model name in any git message; never change `user.name`/`user.email`.
**Texts**: every customer-visible string is a key (`tool/gen_text_catalogue.py` → manifest → `php artisan app-texts:sync`); every Arabic string is marked **AR-DRAFT** for the owner's review.

Format: `- [ ] T### [P?] [US?] description — repo` · `[P]` = parallel (different files, no dependency).

## Phase 1: Setup

- [ ] T001 Ask the owner to create `dahab_wt020` and `dahab_wt020_dev` (the `dahab` user has no CREATEDB); verify with `psql -l`; if missing STOP — never fall back to `dahab` — B
- [ ] T002 [P] `docs/features/account-recovery.md` from `docs/features/_TEMPLATE.md` (impact analysis, classification, consumers, the C1–C6 answers) — B
- [ ] T003 Add `DASHBOARD_URL=http://localhost:3000` to `.env.example` and the local `.env` note in `postman/README.md` — B

## Phase 2: Foundational (blocks all stories)

- [ ] T004 Migration `database/migrations/2026_10_13_000010_account_recovery.php` per data-model.md §1: widen `withdrawal_pause.trigger_kind` CHECK with `password_reset`; insert permission `staff.manage` (guard `staff`) and give it to role `ceo` only; `down()` refuses while a `password_reset` pause exists, otherwise restores the CHECK and removes the permission — B
- [ ] T005 [P] Mirror T004 in `docs/Database schema/05_schema_security.sql` ("Changed by spec 020") — B
- [ ] T006 [P] `PauseTrigger::PASSWORD_RESET`; `StaffPermission::STAFF_MANAGE` (label, group *Access control*, no seed role); `AccountEvent::PASSWORD_RESET`; update `tests/Feature/Authorization/PermissionCatalogueTest.php` (count, CEO holds it, no other seed role) — B
- [ ] T007 [P] `AuditEvent`: `auth.staff.password_reset_requested`, `authz.staff.created`, `authz.staff.disabled`, `authz.staff.enabled` with labels and categories; update the audit-event inventory test — B
- [ ] T008 [P] `DomainApiException` factories `resetLinkInvalid` (422), `founderProtected` (403), `staffAlreadyDisabled` / `staffNotDisabled` (409); add the four codes to the error-code inventory test and `docs/platform/api-contract.md` — B
- [ ] T009 [P] `config/dahab-auth.php` keys of data-model.md §4 (`password_reset.staff_link`, `per_device_*`, `password_reset_confirm`, `staff_password_reset_confirm`) — B
- [ ] T010 Limiters in `AppServiceProvider`: add the per-device bucket to `auth.password_reset.request`; new `auth.password_reset.confirm` and `auth.staff.password_reset.confirm` — B

**Checkpoint**: `php artisan migrate` on `dahab_wt020`; catalogue/audit/error inventory tests pass (targeted).

## Phase 3: US1 — A customer who forgot their password gets back in (P1) 🎯

### Backend
- [ ] T011 [US1] `app/Services/PasswordResetChallengeStore.php` (R1: HMAC-phone key, encrypted payload, `begin/find/save/forget/lock`), modelled on `CustomerLoginChallengeStore` — B
- [ ] T012 [P] [US1] Notifications: `PasswordResetCodeNotification` (SMS only, on-demand route, `auth.password_reset_code.sms`); `AccountEvent::PASSWORD_RESET` handled by `AccountNotification` (keys `account.password_reset.*`, with pause and cancelled-withdrawal params) — B
- [ ] T013 [P] [US1] Catalogue entries in `CatalogueData` (EN + AR-DRAFT; code SMS `read_only`), cases in `tests/Support/NotificationCases.php` so the spec 019 golden/inventory tests cover them — B
- [ ] T014 [US1] `RequestPasswordResetAction` (uniform result; real account → code, SMS, audit; unknown/closed → dummy hash, app-log only; honours `otp.send_cooldown_seconds`/`max_sends_per_hour` without changing the answer) — B
- [ ] T015 [US1] `ConfirmPasswordResetAction` (R2/R5/R6/R7): spend the code under the challenge lock (`otp_invalid`/`otp_expired`, attempt counting, void at the cap); then one transaction — `lockPayoutAccounts`, customer row lock, status re-check (closed → `otp_invalid`), password update, `WithdrawalSafetyStop::apply(PASSWORD_RESET)`, `CustomerSessions::endOthers(null)` + `forgetOthers(null)`, clear the login limiter bucket, audit `auth.customer.password_reset_completed`; notice after commit — B
- [ ] T016 [US1] `PasswordResetRequestRequest`, `PasswordResetConfirmRequest` (phone E.164, `code` `digits:6`, `Password::defaults()` + `confirmed`) with `#[OA\Schema]` — B
- [ ] T017 [US1] `CustomerPasswordResetController` + routes `POST /customer/auth/password/reset-request|reset-confirm` (`db.elevate:bootstrap`, throttles), `#[OA]` for both, regenerate OpenAPI — B
- [ ] T018 [US1] `CustomerFileResource` enum doc/value: `password_reset` in `trigger_kind` — B
- [ ] T019 [US1] Pest `tests/Feature/Auth/CustomerPasswordResetTest.php`: uniform request bodies (registered / unknown / closed / suspended); SMS only for a real account; 3-per-hour per phone, per-device and per-IP throttles; confirm happy path (hash changed, sessions + devices cleared, SMS + mail + inbox notice, audit counts, pause row `password_reset`, unreleased withdrawal cancelled with balanced ledger entries, lockout cleared); wrong code counted and voided at 5; expired; reused; unknown/closed phone → `otp_invalid`; suspended and pending accounts may reset; weak password and mismatched confirmation → 422; no body contains the code or hash; concurrent double confirm → exactly one success — B
- [ ] T020 [US1] Postman: *Customer → Auth → Password Reset Request / Confirm* (+ `reset_code` env variable) — B

### Dashboard (consumer of the new enum value)
- [ ] T021 [P] [US1] `src/types/customer.ts` (`WithdrawalPauseTrigger` + label *a password reset*) and `src/types/api.ts:202`; type-check — D

### Customer App
- [ ] T022 [US1] `lib/services/auth/auth_api.dart`: `passwordResetRequest(phone)` → `{expiresInSeconds, resendAvailableAt}`, `passwordResetConfirm(phone, code, password)` → `{signedOutSessions, withdrawalsPausedUntil, cancelledWithdrawals}`; error mapping in `auth_messages.dart` (`otp_invalid`, `otp_expired`, `too_many_requests`, `validation_failed`) — F
- [ ] T023 [US1] Screens `forgot_password_screen.dart` (phone, *Send code*) and `reset_password_screen.dart` (code, new password, confirm, resend with cooldown from `resend_available_at`); routes in `lib/routing/routes.dart`; replace the toast at `login_screens.dart:79`; on success back to sign-in with the phone prefilled and the pause notice; English + Arabic — F
- [ ] T024 [P] [US1] Text keys `lib/core/text/keys/auth_reset.dart` (`auth.reset.*`); retire `auth.password_reset_is_not_available_yet` in the manifest test; run `tool/gen_text_catalogue.py`, copy the manifest to B `database/data/app_text_keys.json`, `php artisan app-texts:sync` — F, B
- [ ] T025 [US1] Tests: extend the fake backend in `test/flows_test.dart` with the two endpoints; `test/account_flows_test.dart` cases (happy path, wrong code, expired, resend cooldown, validation); `flutter analyze` — F

## Phase 4: US2 — Staff recover a password (P1)

- [ ] T026 [US2] `IssueStaffPasswordLinkAction` (origin `recovery|invitation|enabled`; voids the member's open links; HMAC token via `ConfirmationTokens`; lifetime by origin; returns the link; mail after commit) — B
- [ ] T027 [P] [US2] `StaffPasswordLinkNotification` (mail, English, three subjects/lines by origin, link only) — B
- [ ] T028 [US2] `RequestStaffPasswordResetAction` (uniform; active, non-system member → link + audit `auth.staff.password_reset_requested`; others → nothing; equal work) — B
- [ ] T029 [US2] `ConfirmStaffPasswordResetAction` (R3/R4): atomic consume, `reset_link_invalid` for unknown/used/expired/disabled; upsert `staff_password` without touching `force_reenroll_mfa_at`/`staff_mfa`; consume other links; delete every staff token; clear staff login limiter bucket; audit `auth.staff.password_reset_completed` with origin — B
- [ ] T030 [US2] `StaffResetRequestRequest`, `StaffResetConfirmRequest` + `StaffPasswordResetController`, routes `POST /dashboard/auth/password/reset-request|reset-confirm` (`db.elevate:bootstrap`), `#[OA]`, regenerate OpenAPI — B
- [ ] T031 [US2] Pest `tests/Feature/Auth/StaffPasswordResetTest.php`: uniform answers (unknown / disabled / system / active); mail only to active; link single use; expiry; newer link voids older; password set and old tokens revoked; next sign-in still demands MFA for an enrolled member and enrolment for a founder (no `force_reenroll_mfa_at`); disabled member's link → `reset_link_invalid`; lockout cleared; audit rows; two simultaneous confirms → one wins — B
- [ ] T032 [US2] Postman: *Dashboard → Auth → Staff Password Reset Request / Confirm* (+ `staff_reset_token`) — B
- [ ] T033 [US2] Dashboard `src/api/endpoints.ts`, `src/services/staff-auth.service.ts` (`requestPasswordReset`, `confirmPasswordReset`), mock counterpart in `src/mock/` — D
- [ ] T034 [US2] Pages `src/pages/forgot-password.vue` and `src/pages/set-password.vue` (token from the query; password + confirm; `reset_link_invalid` banner with a link back to *Forgot password?*; success → login with a banner), public guest routes in `src/router/index.ts`, *Forgot password?* link in `login.vue`; type-check, lint on touched files, build — D

## Phase 5: US3 — Create, disable and enable staff (P2)

- [ ] T035 [US3] `CreateStaffAction` (R8, in `withAuthzLock`: insert, `created_by`, branch check as `SetStaffBranchAction`, roles through `RoleEscalationGuard::assertCanAssign` + `ReasonRule`, audit `authz.staff.created`, invitation link after commit) — B
- [ ] T036 [US3] `DisableStaffAction` / `EnableStaffAction` (R9: not self, founder protected, state errors, token deletion, open links consumed, `last_role_manager` through the lock, enable deletes the password row and issues the `enabled` link, audits) — B
- [ ] T037 [P] [US3] `ListStaffAction` `status` filter and `StaffMemberResource` `password_set` (+ `#[OA]` update of `DashboardStaffMember` and `ListStaffRequest`) — B
- [ ] T038 [US3] `CreateStaffRequest`, `StaffStatusChangeRequest`; `StaffController::store|disable|enable` with `#[OA]`; routes `POST /dashboard/staff`, `POST /dashboard/staff/{staff}/disable|enable` (`staff.permission:staff.manage`, `idempotent`); regenerate OpenAPI — B
- [ ] T039 [US3] Pest `tests/Feature/Authorization/StaffLifecycleTest.php`: create (permission, reason, unique email, escalation denied, branch, founder flag stays false, no password row, invitation mailed, audit, `created_by`); invited member cannot sign in; link → password → sign in; disable (self, founder, last role manager, tokens revoked, `invalid_credentials`, history name kept, `status=disabled` list); enable (old password dead, link issued); idempotency replay; a non-CEO holder's roles bounded by escalation; route inventory shows `staff.manage` on the three routes; two simultaneous disables — B
- [ ] T040 [US3] Postman: *Dashboard → Staff → Create Staff / Disable Staff / Enable Staff* (+ `new_staff_id`); list request gains `status` — B
- [ ] T041 [US3] Dashboard `PERMISSIONS.staffManage`, `src/types/staff.ts` (`passwordSet`, status), `src/types/access.ts`, `src/services/access.service.ts` (`createStaff`, `disableStaff`, `enableStaff`, `status` filter), endpoints, mock — D
- [ ] T042 [US3] `src/components/staff/AddStaffModal.vue` (name, email, phone, roles from the roles list, branch, reason) and `StaffStatusModal.vue` (reason); `pages/staff/index.vue`: *Add staff member* (gated by `staff.manage`), *Disable / Enable* row actions (hidden for self and founders), *Invitation pending* chip, *Disabled* filter tab; type-check, lint on touched files, build — D

## Phase 6: Documentation and verification

- [ ] T043 [P] Technical Spec "Changed by spec 020" notes: Part 1 §2.1 (reset channel), Part 2 §1 (the two customer endpoints) and §10 (`POST /dashboard/staff`, disable/enable), `dahab-dashboard-authorization.md` §43 (recovery built, `staff.manage`, create/disable) — B
- [ ] T044 [P] `docs/platform/api-contract.md`: the 7 endpoints, 4 codes, `staff.manage`, the `password_reset` trigger, public-route throttles — B
- [ ] T045 [P] `CLAUDE.md` current-state: Backend, Dashboard and Flutter paragraphs for spec 020 — B
- [ ] T046 `composer swagger:generate`; confirm the 7 operations and the changed schemas appear; fix attributes — B
- [ ] T047 `./vendor/bin/pint --test` on touched files only (the two pre-existing unformatted files are not ours) — B
- [ ] T048 Targeted Pest files of this spec + `PermissionCatalogueTest`, the audit/error/notification/route inventory tests — B
- [ ] T049 [P] Dashboard: `npm run type-check`, `npm run build`, lint on touched files only (older files have known errors) — D
- [ ] T050 [P] Flutter: `flutter analyze`, `flutter test`, `flutter build web --release` — F
- [ ] T051 Walk `quickstart.md` on `dahab_wt020_dev` + the three servers — B, D, F
- [ ] T052 **One full Pest run** (`dahab_wt020`, sequential); rerun only failing files; report the result to the owner — B
- [ ] T053 Step 5 report (Feature / Backend / Dashboard / Customer App / API classification / Database / Tests / Builds / Documentation / Projects not changed) — B

## Dependencies

```
Phase 1 ─► Phase 2 ─┬─► Phase 3 (US1) ──┐
                    └─► Phase 4 (US2) ─► Phase 5 (US3) ─► Phase 6
Phase 3 ─────────────────────────────────┘
```
- T011–T015 before T016–T017; T018 and T021 ship with T015 (the new enum value reaches the Dashboard).
- T026 (the link issuer) before T028, T029 and T035/T036.
- Within a story: Backend → contract (T017/T030/T038 regenerate OpenAPI) → Dashboard → Customer App.
- US1 and US2 can proceed in parallel after Phase 2; US3 needs the link issuer from US2.

## Parallel examples

- After T010: T011 (store) ‖ T012/T013 (notifications + catalogue) ‖ T026/T027 (link issuer + mail).
- Dashboard T033 ‖ Flutter T022 once their Backend contract task is done.
- Phase 6: T043 ‖ T044 ‖ T045 ‖ T049 ‖ T050.

## Implementation strategy

1. MVP = Phases 1–3 (customers can recover — the launch blocker). Shippable alone.
2. Phase 4 next (staff recovery), then Phase 5 (accounts no longer come from a seeder).
3. Each phase is shippable on its own; the Dashboard enum change travels with T015.
4. Stop after Phase 2's checkpoint and after each phase for the owner's review if asked.

## Blocked / waiting on the owner

- T001 (databases).
- C1–C6: confirmed 2026-10-11.
- AR-DRAFT approval of the Arabic strings (does not block build).
