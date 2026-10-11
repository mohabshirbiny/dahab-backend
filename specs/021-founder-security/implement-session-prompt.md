# Prompt for the local session: implement spec 021 (Founder-account security)

Implement spec 021 on the owner's machine. The plan is finished and approved; this session builds it. Work through `tasks.md` in order, all six phases, to the end.

## 0. State you start from

- Planning is committed and pushed on the Backend branch **`claude/wonderful-hamilton-ukkqkx`** (Backend `main` 86ffb8e + one docs commit). `main` does not have the spec 021 files yet.
- Files: `specs/021-founder-security/{spec,plan,research,data-model,quickstart,tasks}.md` and `contracts/founder-security-api.md`. These are **all confirmed** by the owner (2026-10-11); do not reopen them:
  - decisions D21-1…4;
  - clarifications C1 (unfreeze slots) and C2 (`founder:trust-device` bootstrap);
  - plan questions Q1 = yes (`founder.unfreeze_second` to the `ceo` role), Q2 = no (spec list only), Q3 = yes (*Freeze* beside a refused device).
- Create the branch `feature/founder-security` in **Backend and Dashboard only**. The Customer app is not touched.
  - Backend: `git fetch origin && git switch -c feature/founder-security origin/claude/wonderful-hamilton-ukkqkx`
  - Dashboard: from `main` (e364eb6).
- Use PHP 8.4. **Never run tests, `migrate:fresh` or seeders on the primary `dahab` database.** The owner has created `dahab_wt021` (suite) and `dahab_wt021_dev` (dev data). Check with `psql -l`, and pass `DB_DATABASE/DB_USERNAME/DB_PASSWORD` on the command line every time. If either is missing, STOP and ask (T001).
- Servers: the owner starts the API (`127.0.0.1:8000`) and the Dashboard (`localhost:3000`). Do not leave any running.

## 1. Read first

1. `CLAUDE.md` (Part 1 platform rules, Part 2 Backend rules) and `docs/platform/*.md`.
2. The spec 021 files:
   - `tasks.md` is the work list (Phases 1–6; Dashboard tasks are marked **[D]**);
   - `research.md` R1–R18 holds every design decision;
   - `contracts/founder-security-api.md` has the exact API;
   - `data-model.md` has the migration, CHECKs and the DH021/DH022 guards.
3. The built code the plan builds on:
   - staff sign-in: `LoginStaffAction`, `VerifyStaffMfaAction`, `EnrollStaffMfaAction`, `CompleteStaffSignInAction`, `StaffMfaSessionStore`;
   - `SetRequestContext` (the fingerprint), `EnsureStaffStanding`;
   - `AccountFreeze`, `FounderDeviceApproval`, `StaffFactory::frozen()`;
   - `RecordAuditLogAction`, `FounderAlertNotification`, `InboxChannel`, `SmsChannel`;
   - `LocksStaffAuthorization`, `RoleEscalationGuard`, `ReasonRule`, `DisableStaffAction`.
4. Skills: `laravel-migrations`, `laravel-eloquent-models`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-queues-notifications`, `laravel-pest-testing`, `laravel-quality-gates`.

## 2. What to build (summary; the files win)

- **US1, held new-device sign-in.** After password + MFA, a founder on an unknown device gets `200 {device_approval_required, approval_id, approval_ref, expires_at, poll_interval_seconds}` and **no token**.
  - The device polls `POST /dashboard/auth/device-approval/status` (same `X-Device-Id`).
  - The other founder approves or refuses via `/dashboard/founder/device-approvals*`.
  - The approval is single use and lasts 15 minutes.
  - "Known device" is the existing `staff_device_fingerprint`.
  - Server command: `founder:trust-device`.
  - A founder without `X-Device-Id` gets `422 device_id_required`.
- **US2, freeze and dual unfreeze.**
  - `POST /dashboard/founder/freeze` (the other founder, reason required): every token is deleted.
  - `POST /dashboard/founder/unfreeze` takes one confirmation per call:
    - slot 1 is the frozen founder, through the tokenless `POST /dashboard/auth/frozen/confirm-unfreeze`, or a holder of `founder.unfreeze_second`;
    - slot 2, always last, is the founder who is not frozen.
- **US3, real-time notice.** A hook in `RecordAuditLogAction` (after commit) sends every active founder an email and a Dashboard notice (new table `staff_notice`; `GET /dashboard/notices`, `…/{id}/read`, `…/read-all`), plus SMS for a freeze. A notice never contains a secret.
- One migration, `2026_10_14_000010_founder_security.php`, mirrored in `05_schema_security.sql`.
- **Dashboard:**
  - send `X-Device-Id` on every request (`src/api/device.ts` + `axios.ts`; this must ship with the Backend);
  - the waiting-for-approval screen with polling;
  - the frozen screen with *Confirm my unfreeze*;
  - the *Security* page (approvals, devices, freeze/unfreeze, history);
  - the notices bell in `AppTopbar.vue`.

## 3. Phases and order

Phase 1 setup → 2 foundational → 3 (US1) → 4 (US2) → 5 (US3) → 6 docs and verify.

- Inside each phase: Backend → contract (`composer swagger:generate`) → Dashboard.
- Existing tests that sign founders in through `/dashboard/auth/*` must get `withKnownDevice()` (T019).
- **Work through all six phases without stopping for approval between them** (owner's instruction, 2026-10-11). Run each phase's checkpoint yourself (targeted tests, type-check/lint/build) and carry on. Pause only for a real blocker: a missing database, a spec/code disagreement, or a failure you cannot fix within the plan. Report once at the end in the Step 5 shape.

## 4. Rules that still apply

- **Do not run the full test suite more than once** (T066). Run targeted files while building, then one full sequential run at the end in the background (about 70 minutes), then only the failing files, and tell the owner.
- The Backend is the source of truth; never invent API behaviour. If the spec and the code disagree, **stop and report**; never choose silently. When the Dashboard needs something the API lacks, state it as a Backend requirement for the owner to approve.
- In the same task as each endpoint, update:
  - `#[OA]` and `composer swagger:generate`;
  - the Postman request (collection-level `X-Device-Id: {{device_id}}` header) and its environment variables;
  - Pest tests;
  - the inventory tests: `OpenApiGenerationTest`, the `PrincipalIsolationTest` route count, `ElevationTest` (the two new public `db.elevate:bootstrap` routes), and the permission / audit / error-code catalogue tests.

  At the end: `docs/platform/api-contract.md`, the Technical Spec "Changed by spec 021" notes (Part 1 §8/§9/§10, Part 2 §10, `dahab-dashboard-authorization.md`), the `CLAUDE.md` current-state section and `docs/features/founder-security.md`.
- Texts are staff-facing English, like `FounderAlertNotification`. Nothing reaches the customer app, so there are no app-text keys and no AR-DRAFT.
- Dashboard files mix CRLF and LF per file. Detect line endings in Python and edit with an EOL-aware helper; `grep -c $'\r'` is unreliable. Write Python/PHP helper scripts with the Write tool, not shell heredocs with apostrophes.
- Git:
  - no `Co-Authored-By`, no `Claude-Session`, no "Generated with" text, and no AI or model name in any commit, merge or PR;
  - plain messages in the project's style (`feat(founder-security): …`);
  - never change `user.name` or `user.email`;
  - each repo gets its own commits;
  - commit only when the owner asks, and never push without being asked.

## 5. Known open items: do not touch unless asked

- Spec 019 and 020 leftovers: Finance sign-off of the market-maker spread (D3), the AR-DRAFT review of app and notification texts, Terms §5.5 with the lawyer.
- Specs 022–025 stay drafts, and spec 021 must not depend on them. The notice table and channel are generic so that spec 024 can reuse them.
- `InvoicesPerformanceTest` is timing-sensitive on a loaded machine (it passes alone). Pint wants to reformat `StartCustomerEmailVerificationAction` and `StartCustomerRegistrationAction` (not ours). Dashboard `npm run lint` has errors in older files, so lint only the files you touch.
