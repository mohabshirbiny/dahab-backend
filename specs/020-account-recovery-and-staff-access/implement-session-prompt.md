# Prompt for the local session — implement spec 020 (Account recovery and staff access)

Implement spec 020 on the owner's machine. The plan is finished and approved; this session builds it. Work through `tasks.md` in order.

## 0. State you start from

- Planning is committed and pushed on the Backend branch **`claude/compassionate-ride-00xoyx`** (Backend `main` 72424da + two docs commits). `main` does not have the spec 020 files yet.
- Files: `specs/020-account-recovery-and-staff-access/{spec,plan,research,data-model,quickstart,tasks}.md` and `contracts/account-recovery-api.md`. Decisions D20-1…5 and clarifications C1–C6 are **all confirmed** by the owner (2026-10-11). Do not reopen them.
- Create the branch `feature/account-recovery` (same name in all three repos):
  - Backend: `git fetch origin && git switch -c feature/account-recovery origin/claude/compassionate-ride-00xoyx`
  - Dashboard and Customer app: from each repo's `main` (Dashboard 5101f84, Flutter b2b9b96).
- Use PHP 8.4 (the lock file needs it). **Never run tests, `migrate:fresh` or seeders on the primary `dahab` database.** Use `dahab_wt020` (suite) and `dahab_wt020_dev` (dev data), created by the owner (the `dahab` user has no CREATEDB), passing `DB_DATABASE/DB_USERNAME/DB_PASSWORD` on the command line. If either is missing, STOP and ask (task T001).

## 1. Read first

1. `CLAUDE.md` (Part 1 platform rules, Part 2 Backend rules) and `docs/platform/*.md`.
2. The spec 020 files above — `tasks.md` is the work list (53 tasks, Phases 1–6), `research.md` R1–R14 holds the design decisions, `contracts/account-recovery-api.md` the exact API.
3. Skills: `laravel-migrations`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-queues-notifications`, `laravel-pest-testing`, `laravel-quality-gates`.

## 2. What to build (summary — the files win)

- **US1** customer reset: `POST /customer/auth/password/reset-request|reset-confirm` — SMS code in an encrypted cache challenge (not `one_time_token`), uniform answers, all sessions and devices ended, the spec 013 safety stop with new `PauseTrigger::PASSWORD_RESET`, login lockout cleared, notice on SMS + email + inbox.
- **US2** staff recovery: `POST /dashboard/auth/password/reset-request|reset-confirm` — single-use HMAC link in `one_time_token` (30 min), MFA untouched.
- **US3** `POST /dashboard/staff`, `…/{staff}/disable|enable`, permission `staff.manage` (CEO only), `status` filter and `password_set` on the staff list/resource.
- One migration `2026_10_13_000010_account_recovery.php` (widen the `trigger_kind` CHECK, seed `staff.manage` to the CEO role). Dashboard: forgot-password and set-password pages, Staff page actions. Flutter: forgot-password flow, text keys.

## 3. Phases and order

Phase 1 setup → 2 foundational → 3 (US1) and 4 (US2) → 5 (US3) → 6 docs and verify. Inside each: Backend → contract (`composer swagger:generate`) → Dashboard → Customer app. Phase 3 alone is a shippable MVP. Show the owner the result after Phase 2's checkpoint and after each phase.

## 4. Rules that still apply

- **Do not run the full test suite more than once** (T052): targeted files while building, one full sequential run at the end, then only the failing files — and tell the owner.
- Backend is the source of truth; never invent API behaviour. If the spec and the code disagree, **stop and report**; never choose silently.
- Same task as each endpoint: `#[OA]`, `composer swagger:generate`, the Postman request and environment variable, Pest tests; at the end `docs/platform/api-contract.md`, the Technical Spec "Changed by spec 020" notes and the `CLAUDE.md` current-state section.
- Every customer-visible text is a key (Flutter `tool/gen_text_catalogue.py` → manifest → copy to `database/data/app_text_keys.json` → `php artisan app-texts:sync`); every Arabic string you write is marked **AR-DRAFT** for the owner's review.
- Git: no `Co-Authored-By`, no `Claude-Session`, no "Generated with" text, no AI or model name in any commit, merge or PR; plain messages in the project's style (`feat(account-recovery): …`); never change `user.name` or `user.email`; each repo gets its own commits; commit only when the owner asks.

## 5. Known open items — do not touch unless asked

- Spec 019 leftovers: Finance sign-off of the market-maker spread (D3), AR-DRAFT review, Terms §5.5 with the lawyer.
- Specs 021–025 stay drafts; spec 020 must not depend on them.
- Pint wants to reformat `StartCustomerEmailVerificationAction` and `StartCustomerRegistrationAction` (not ours); Dashboard `npm run lint` has errors in older files (lint only the files you touch).
