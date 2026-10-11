# Prompt for the next session — spec 020 (Account recovery and staff access)

Continue the Dahab platform. Spec 019 is built and committed; specs 020–025 are drafted and waiting for my decisions. Work on **spec 020 only**: clarify, plan and tasks first; stop and show me the result before implementing.

## 0. State you start from

- Three repositories, each on branch `feature/app-content`, spec 019 committed, **nothing pushed**:
  - Backend worktree `D:\laragon\www\dahab-backend\.claude\worktrees\new-feature-d10eb8` (last commit `docs(specs): draft specs 020-025 and their roadmap`)
  - Dashboard `D:\laragon\www\dahab-dashboard`
  - Customer app `D:\laragon\www\dahab-flutter`
- Create the spec 020 branch as `feature/account-recovery` in each repo from the current branch (spec 019 is not merged to `main` yet), or tell me if you would rather wait for the merge.
- Databases: tests on `dahab_wt019`, dev data on `dahab_wt019_dev` (owner `dahab`). **Never run tests, `migrate:fresh` or seeders on the main `dahab` database.** Create `dahab_wt020` and `dahab_wt020_dev` if you need new ones (the `dahab` user has no CREATEDB; ask me).
- Servers I use: API `127.0.0.1:8000`, Dashboard `localhost:3000`, customer app `127.0.0.1:8765` (static `build/web`).

## 1. Read first, in this order

1. `CLAUDE.md` of the Backend (Part 1 platform rules, Part 2 Backend rules) and the three `docs/platform/*.md`.
2. `specs/ROADMAP-020-025.md` and `specs/020-account-recovery-and-staff-access/spec.md`.
3. Technical Spec Part 1 §2.1 and §8, Part 2 §1 (`password/reset-request`, `reset-confirm`) and §10 (`POST /admin/staff`), and `docs/Technical Spec/dahab-dashboard-authorization.md` §43.
4. The built code: customer auth actions in `app/Actions/Auth/Customer`, staff auth in `app/Actions/Auth/Staff`, the one-time-token store and its purposes (`password_reset_customer`, `password_reset_staff`), `AccountController::changePassword`, the contact-change pause of spec 017 (`withdrawal_pause.trigger_kind`), staff and role controllers, the notification templates (`app/Notifications/Templates`).
5. Dashboard: the login pages, Users and Staff pages, `src/services` and `src/types/staff.ts`. App: `lib/features/auth/login_screens.dart`, `lib/services/auth`.

## 2. My decisions on spec 020 (replace with mine before you start)

| ID | Question | My answer |
|---|---|---|
| D20-1 | Customer reset channel | *(SMS code to the phone on file; email only as a notice)* |
| D20-2 | A reset opens the withdrawal pause like a contact change? | *(yes, same setting)* |
| D20-3 | Staff recovery | *(emailed single-use link)* |
| D20-4 | Who holds `staff.manage` | *(CEO only, no seeded role)* |
| D20-5 | Password rule on reset | *(same as sign-up)* |

## 3. What I want from this session

1. A short **impact analysis** (Backend / Database / API / Dashboard / Customer App / Auth / Permissions) as in the platform workflow.
2. `/speckit-clarify` only if something in the spec is still ambiguous after my answers; otherwise skip.
3. `/speckit-plan` → `plan.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`; `/speckit-tasks` → `tasks.md` split along the skill boundaries (migration → model → Action → FormRequest/Resource/controller/OpenAPI → Pest), with the Dashboard and Flutter tasks marked.
4. **Stop and show me the result.** No implementation before I say so.

## 4. Rules that still apply

- **Do not run the test suite more than once**: write tests carefully, verify with small scripts, one full run at the end, then rerun only failing files and tell me.
- Backend is the source of truth; never invent API behaviour; when a frontend needs something the API lacks, state it as a Backend requirement for me to approve.
- Update in the same task: `#[OA]` attributes, `composer swagger:generate`, the Postman collection (and its environment), Pest tests, `docs/platform/api-contract.md`, the Technical Spec "Changed by spec" notes, and the `CLAUDE.md` current-state section.
- Every customer-visible text is a key in the app's text catalogue (Flutter `tool/gen_text_catalogue.py`, then the manifest and `php artisan app-texts:sync`); every Arabic string you write is marked AR-DRAFT for my review.
- Git: no `Co-Authored-By`, no `Claude-Session`, no "Generated with" text, no AI or model name in any commit, merge or PR; plain messages in the project's style; never change `user.name` or `user.email`; commit only when I ask.
- If the spec and the code disagree, **stop and report**; never choose silently.

## 5. Known open items you must not touch unless I ask

- Spec 019 leftovers: Finance sign-off on the market-maker spread (D3), AR-DRAFT review, Terms §5.5 with the lawyer.
- Specs 021–025 stay drafts; spec 020 must not depend on them.
- Three pre-existing issues are fixed; pint still wants to reformat `StartCustomerEmailVerificationAction` and `StartCustomerRegistrationAction` (not ours); Dashboard `npm run lint` has errors in older files.
