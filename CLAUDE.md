# Dahab Platform — Parent Guide

This file is the **parent guide for the whole Dahab platform** and lives in the Backend repo so
that its changes are tracked. `D:\laragon\www\CLAUDE.md` only points here.
Platform docs: [`docs/platform/architecture.md`](docs/platform/architecture.md) ·
[`docs/platform/api-contract.md`](docs/platform/api-contract.md) ·
[`docs/platform/development-workflow.md`](docs/platform/development-workflow.md) ·
[`docs/features/`](docs/features/) (feature specs, template `_TEMPLATE.md`).

Part 1 covers the platform (all projects). Part 2 covers this Backend repo.

---

# Part 1 — Platform

## One platform, separate projects

The Dahab projects are sibling folders in `D:\laragon\www`. They are parts of **one** platform but
stay separate. Never merge them, never move/rename them, never move files between them, never
create a new parent/shared project.

```
Backend API (dahab-backend, Laravel 12, /api/v1)
     │
     ├──────────────► Dashboard            (../dahab-dashboard, Vue 3)  →  /api/v1/dashboard/*
     │
     └──────────────► Customer Flutter Web (../dahab-flutter, Flutter)  →  /api/v1/customer/*
```

| Folder | Role | Stack | Git |
|---|---|---|---|
| `dahab-backend/` (this repo) | REST API, **source of truth** | Laravel 12, PHP 8.3+, PostgreSQL 16, Redis, Sanctum, Spatie Permission, Horizon, l5-swagger, Pest 3 | own repo, `main` |
| `../dahab-dashboard/` | Staff/admin UI | Vue 3 + TS, Vuetify 4, Pinia, Vue Router 5, TanStack Vue Query, Axios, vue-i18n, Tailwind 4, Vite 8 | own repo, `main` |
| `../dahab-flutter/` | **Customer app** (Flutter Web) | Flutter (Dart ^3.11), go_router, provider, http, shared_preferences | no repo yet (the user will add one later) |

`../dahab-pwa/` (an earlier Vue customer PWA) is **out of scope for now** — ignore it unless the user
brings it back. Unrelated projects in `D:\laragon\www` (VastPay, VastMenu, mysaff, `old dahab code/`, …)
are never part of Dahab work.

Project guides: this file (Backend), `../dahab-dashboard/CLAUDE.md` (+ `AGENTS.md`, `docs/`),
`../dahab-flutter/README.md`.

## Responsibilities

**Backend** — business logic · database · authentication · authorization · API · validation ·
transactions · permissions · audit. **The Backend is the source of truth for business rules.**

**Dashboard** — staff/admin UI · dashboard state · API consumption (`/dashboard/*` only) ·
navigation · UI validation · loading/error/empty states. Gates UI on the Backend's permission strings.
Staff/admin only — no customer functionality unless a spec says so. Respect staff auth, MFA, the Spatie
`staff` guard, permissions/roles, the API contract and the existing Dashboard architecture. A new
permission means: `app/Enums/StaffPermission.php` (the catalogue) · seed data · authorization tests ·
the affected UI guards · docs.

**Customer App** — customer UI · customer state · API consumption (`/customer/*` only) ·
navigation · UI validation · loading/error/empty states. Separate from the Dashboard — don't copy the
Dashboard's architecture into Flutter. Keep it mobile-friendly (no desktop-only assumptions) and
compatible with future mobile builds.

Frontends may validate for UX, but must not re-implement Backend business rules (pricing
authority, status transitions, permissions) beyond what is needed for display. Where a frontend
currently holds such logic (e.g. `../dahab-flutter/lib/services/pricing.dart` — prototype maths),
it is a placeholder until the Backend provides it.

## Sources of truth

| Question | Authority | Where |
|---|---|---|
| Endpoint, method, params, validation, auth, permissions, response shape, pagination, errors, field names | **Backend code** | `routes/api.php`, controllers, `app/Http/Requests`, `app/Http/Resources`, `app/Actions`, `app/Enums/AuthErrorCode.php`, `bootstrap/app.php` |
| Machine-readable form of the above | Generated OpenAPI (from `#[OA\…]` attributes) | `storage/api-docs/api-docs.json` — gitignored; `composer swagger:generate` |
| Manual API exercising | Postman collection | `postman/` |
| Product rules / business intent | Backend docs + Constitution | `docs/`, `.specify/memory/constitution.md` |
| Dashboard visuals | Design reference | `../dahab-dashboard/docs/dahab-admin-dashboard.html` |
| Customer app visuals | Prototype | `../dahab-flutter/doc/dahab-app-prototype.html` (copy in `docs/`) |

Do **not** hand-write an `openapi.yaml`. `specs/*/contracts/*` are Spec Kit planning artefacts and
may lag the code. On disagreement: Backend code → generated OpenAPI → Postman → Spec Kit contracts →
prose docs. Report the disagreement.

## Feature sessions — one feature = one session

The repository is the long-term memory; a Claude conversation is temporary working context.
Every feature / spec / feature branch (`feature/pricing`, `feature/wallets`, …) gets its **own**
Claude session. Don't carry one conversation across unrelated features, and never rely on an old
conversation ("we discussed this earlier") for a decision: anything that matters goes into code,
tests, this file, `specs/<NNN-feature>/` (spec · research · plan · data-model · contracts · tasks),
`docs/`, or the feature's `handoff.md`.

Priorities, in order: correctness · security · traceability · small context · feature isolation ·
reproducibility · clean Git history · clear API contracts · test coverage · maintainability.
Don't optimise for keeping a conversation alive.

**Lifecycle.** Research → Clarify → Specify → Plan → Tasks → Analyze → Implement → Tests → Review →
QA → Handoff → session complete. The Spec Kit commands for it are in Part 2.

**Starting a session.**
1. `git branch --show-current`, `git status` (uncommitted changes?), recent relevant commits if needed.
2. Identify the feature/spec. Read this file, then only that feature's docs — for a Spec Kit feature
   `specs/<NNN-feature>/` (`spec.md`, `research.md`, `plan.md`, `tasks.md`, `data-model.md`,
   `quickstart.md`, `contracts/`) — and the code it touches. Map dependencies before editing.
3. Say `New feature session: <feature-name>`. When resuming a feature, read
   `specs/<NNN-feature>/handoff.md` (if it exists) and `tasks.md` first, determine the exact next task,
   and say `Continuing feature session: <feature-name>`. Never assume earlier chat context exists or is right.

**Context and usage.** Load only what the feature needs: files it changes, files its spec/plan/tasks
reference, their dependencies, and what tests and integration checks need. Don't scan the repo or
read unrelated projects without a reason, don't re-read unchanged large files, don't repeat large
command outputs or explanations that already live in files. Prefer focused searches, small batches,
targeted tests and short reports.
- Context under ~50%: normal. 50–70%: cut exploration and repeated output. 70–75%: write the handoff
  and recommend a new session if the work can resume from files. 75%+: prefer ending the session.
  Never deliberately run a session up to the limit.
- Auto-compaction is only a fallback for continuing the *same* feature; a new feature always starts fresh.
- If a model/usage limit is hit: stop retrying expensive operations, save the handoff if possible,
  stop safely, and resume in a new session after the reset.

**Handoff.** When a feature needs continuing, create/update `specs/<NNN-feature>/handoff.md` — concise,
never a transcript — so a brand-new session can pick it up. Headings: `# Feature Handoff`, then
`## Feature` · `## Branch` · `## Status` · `## Completed Tasks` (IDs) · `## Remaining Tasks` (IDs) ·
`## Files Changed` · `## Tests` (run + results) · `## Migrations` (created/run, current state) ·
`## Important Decisions` (only those needed to continue) · `## Known Issues` (failures, blockers,
open questions) · `## Next Step` (the exact next task or command).

**Finishing a session.** Verify (Part 2, "Before declaring a feature complete"), tick `tasks.md`,
update docs, write the handoff if useful, and report completed work · tests · remaining work ·
known issues · next feature/session. Then say:
`Feature session complete. Start a new Claude session for the next feature.`

**Stop and report — never decide silently — when:** the spec contradicts the code in a way that
affects the design · plan and tasks disagree · spec/plan/data-model/contracts/tasks disagree and the
repo can't settle it · an API contract is ambiguous · DB constraints conflict · a migration could lose
data · a security boundary is unclear · a money rule is ambiguous · credentials are found · unrelated
uncommitted work could be overwritten · another feature's files are unexpectedly modified.
For ambiguity touching money, security, authorization, DB integrity, API contracts, external
integrations or business rules: name it, lay out the competing readings, check the docs, then ask.
Minor implementation details: follow existing conventions.

## Multi-project feature rule (MANDATORY)

When asked to implement a feature, **do not** modify only the project where the feature appears.
First analyse it across **all three** projects, then implement every required change in every
affected project.

### Required workflow for every feature

**Step 1 — Understand.** Read this file, `docs/platform/architecture.md`,
`docs/platform/api-contract.md`, the relevant project guides, and any spec in `docs/features/`.
Inspect the relevant code in Backend, Dashboard **and** Customer App.

**Step 2 — Impact analysis.** Write a short analysis before editing:

```
Backend:          YES/NO
Database:         YES/NO
API:              YES/NO
Dashboard:        YES/NO
Customer App:     YES/NO
Auth:             YES/NO
Permissions:      YES/NO
API models/types: YES/NO
```

If a project is not affected, say so explicitly and why. For non-trivial features, write a spec
in `docs/features/<feature-name>.md` using `docs/features/_TEMPLATE.md`. The analysis covers all
three projects (with targeted searches — see "Finding consumers"), but only the projects marked YES
are modified; before touching a second project, state why the feature requires it.

**Step 3 — Implement.** When the API changes, go in this order and keep all consumers in sync:

```
Backend (migration → model → Action → FormRequest/Resource/controller + #[OA] → Pest tests → Postman)
    ↓
API contract (composer swagger:generate; update docs/platform/api-contract.md if a convention changed)
    ↓
Dashboard (types → services → composables/stores → components/pages)
    ↓
Customer App (models → services/api → controllers/state → screens)
```

Verify the real API responses before starting frontend work, and finish by checking that every
client uses the same contract — never leave a frontend on an obsolete one.

Backend work follows Part 2 (Spec Kit lifecycle, Laravel skills, Postman sync).

**Step 4 — Verify.** Run only the commands that exist (see `docs/platform/development-workflow.md`):

- Backend: `composer test` · `./vendor/bin/pint --test` · `composer swagger:generate`
- Dashboard: `npm run type-check` · `npm run lint` · `npm run build`
- Customer App: `flutter analyze` · `flutter test` · `flutter build web --release`

**Step 5 — Report** in this shape:

```
Feature:        <name>
Backend:        <changes>
Dashboard:      <changes>
Customer App:   <changes>
API:            <changes + classification: non-breaking / potentially breaking / breaking>
Database:       <changes>
Tests:          <results>
Builds:         <results>
Documentation:  <changes>
Projects intentionally not changed: <projects + reason>
```

## Golden rules

1. **Never invent API behaviour** — no endpoints, fields, filters or codes that don't exist, in any
   project. Missing → report what the Backend needs; don't fake it.
2. **Never modify one project without checking the others.**
3. **Never change an API without checking all its consumers** (Dashboard for `/dashboard/*`;
   Flutter for `/customer/*`; both for shared conventions like the error envelope).
4. **Frontends never guess the Backend** — read the Resource/FormRequest/`#[OA]`/Postman first.
5. **Don't duplicate Backend business rules** in frontends unnecessarily.
6. **No unrelated refactoring; never delete working functionality.**
7. **Never move/rename/merge the projects or their repositories.**
8. **Never commit or push unless explicitly instructed.** Each repo gets its own commits.
9. Prefer small, focused, reversible changes.

## Git coordination

Each project keeps its own repository. For a feature touching several projects, use the **same**
branch name in each affected repo: `feature/<feature-name>` (e.g. `feature/customer-identity-approval`).
Create/switch branches only when asked. `../dahab-flutter` has no repository yet — skip Git there and
say so; don't `git init` unless asked. Details: `docs/platform/development-workflow.md`.

- **One feature = one feature branch.** Don't mix unrelated features in a branch; if the current
  branch holds unrelated changes, stop and report before doing anything destructive.
- **Before modifying anything:** `git status`, the current branch, recent relevant commits when needed.
- **Never** switch branches without permission, reset or discard anyone else's work or unrelated
  changes, overwrite uncommitted changes, touch another feature's files without a stated reason,
  commit unrelated changes, or force-push unless explicitly authorised. Unrelated uncommitted
  changes: inspect and report them first.
- **Commits** (only when asked): logically scoped, e.g. `feat(pricing): add price calculator`,
  `feat(pricing): add gold price feed` — no giant mixed commits. Before committing, inspect
  `git status` + `git diff`, confirm tests pass and only intended files changed, and that no secret is included.

## Change classification

| Change | Class |
|---|---|
| Add an optional response field / new endpoint / new optional query param | Non-breaking |
| Add a value to a response enum | Potentially breaking (exhaustive UI maps, status chips) |
| Remove a response field | Potentially breaking |
| Change a field's type/format/nullability | Potentially breaking → breaking, by usage |
| Add a required request field or new validation rule | Potentially breaking |
| Tighten auth, or require a new permission | Potentially breaking |
| Rename a field, change URL/method, change the response envelope or error `code`s | **Breaking** |

Breaking changes: list consumers, tell the user first; per the Constitution they ship under a new
`/api/v2` prefix with the old one deprecated on a documented schedule.

## Finding consumers

Search, don't assume file names. Grep for the **path**, the **field** (Backend `snake_case`; Dashboard
maps to `camelCase` in `src/services`/`src/types`; Flutter maps in `lib/models`/`lib/services`), the
**enum values** and the **permission strings**:

- Dashboard: `../dahab-dashboard/src` — `api/endpoints.ts` → `types/` → `services/` (+ `mock/`) → `composables/` → `stores/` → `components/`, `pages/`
- Customer App: `../dahab-flutter/lib` — `services/api/`, `services/auth/`, `models/` → controllers (`services/*_controller.dart`) → `features/`; tests in `test/` (incl. the fake backend in `test/flows_test.dart`)

## Impact report (whenever a change crosses the API boundary)

```
Change:          <what, one line>
Classification:  non-breaking | potentially breaking | breaking — <why>
Backend:         files changed / needed · OpenAPI · Postman
Dashboard:       affected files (types · services · mock · composables · stores · components/pages)
Customer App:    affected files (models · services · controllers · screens · tests)
Missing:         anything not found, stated exactly (endpoint / field / permission / doc)
Action needed:   Backend → … · Dashboard → … · Customer App → …
```

## Current state (verified 2026-09-27 — re-verify before relying on it)

- Backend implements: health, customer registration (6 steps) / login / new-device OTP / refresh / me /
  logout, customer uploads + identity-document submission; staff login + MFA / refresh / me / logout;
  dashboard customers list/show and identity-document list/show/image/review; Dashboard-managed staff
  roles/permissions and staff role assignment (`/dashboard/permissions`, `/dashboard/roles*`,
  `/dashboard/staff*`) and the customer verified gate (spec 002); customer data isolation by forced
  PostgreSQL row-level security (spec 003); reference data — karats, branches with weekly hours, closures,
  staff branch assignment, and the working-hours deadline resolver (spec 004, `/dashboard/karats`,
  `/dashboard/branches`, `/dashboard/branch-closures`, `/dashboard/staff/{staff}/branch`); pricing — settings with
  history, the gold price record fed every minute by the provider (`pricing:pull-feed`, credentials in `.env` only)
  or entered by hand while the feed is down, per-karat buy/sell adjustments, and the Part 3 §2 price calculator
  (spec 005, `/dashboard/settings*`, `/dashboard/gold-prices*`, `/dashboard/karats/{code}/adjustments`). The app and
  the PostgreSQL session run on Cairo time (`APP_TIMEZONE=Africa/Cairo`). Nothing else yet.
- Dashboard: staff auth, customers/identity, staff and roles, Karats, Branches and hours, Gold pricing and
  Commission rates are live; Overview and other sections are mock.
- Flutter: registration + sign-in (with device OTP), session restore, refresh and sign-out are live;
  catalog, orders, wallet, notifications, etc. run on mock repositories (`lib/services/mock_repositories.dart`).
- Flutter's `API_BASE_URL` defaults to `http://127.0.0.1:8000/api/v1`; the production host is passed
  with `--dart-define` only when building a deploy version.

---

# Part 2 — Backend repo (dahab-backend)

Laravel 12 API (PHP 8.3+, PostgreSQL 16, Redis, Sanctum, Horizon, Pest 3, l5-swagger).
The rules are in `.specify/memory/constitution.md`, and the specs live in `docs/`
(Spec Kit feature folders: `specs/<NNN-feature>/`).

## Spec Kit + Laravel skills

Non-trivial work goes through the Spec Kit lifecycle (`/speckit-specify → /speckit-clarify → /speckit-plan → /speckit-tasks → /speckit-analyze → /speckit-implement → /speckit-analyze`).
Don't skip a stage unless told to. After the first `/speckit-analyze` (post-tasks), **stop and report
the findings** — `/speckit-implement` starts only when explicitly instructed, never automatically after
task generation. Run `/speckit-analyze` again before marking the feature done.
Use the Laravel skills in `.claude/skills/laravel-*` inside those phases:

| Spec Kit phase | Laravel skills to apply |
|---|---|
| `/speckit-plan` (data-model, contracts) | `laravel-migrations`, `laravel-eloquent-models`, `laravel-api-endpoints`, `laravel-auth-authorization` |
| `/speckit-tasks` | Split each endpoint into tasks along the skill boundaries: migration → model/factory → Action → FormRequest/Resource/controller/OpenAPI → Pest feature test |
| `/speckit-implement` | The skill for each task's layer: `laravel-migrations`, `laravel-eloquent-models`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-queues-notifications`, `laravel-pest-testing` |
| Before marking tasks done, `/speckit-analyze`, or a PR | `laravel-quality-gates` |

**Keep the artefacts in sync.** `spec.md`, `research.md`, `plan.md`, `data-model.md`, `contracts/`,
`quickstart.md` and `tasks.md` must agree. When a requirement changes: find the affected documents,
update them, check dependencies, update tasks, and re-run `/speckit-analyze` before implementing.
Never silently implement something that contradicts the current spec; if the artefacts disagree and
the repo can't settle it, stop and ask.

**Test-first (Constitution V).** The `/speckit-tasks` row above lists layers; within each user story
the test task still runs first (as in `specs/005-pricing/tasks.md`):
1. write/update the Pest tests; 2. confirm they fail for the expected reason; 3. implement;
4. run the focused test; 5. run the feature's suite; 6. run the full suite when appropriate.
Never change a test just to make it pass unless its expectation is demonstrably wrong. Preserve
existing behaviour unless the feature explicitly changes it.

**Implement in batches.** Don't implement a whole feature of dozens of tasks in one run. Group by
dependency — Foundation, then User Story 1, User Story 2, … — each as tests → implementation →
verification. After each milestone: run the tests, inspect `git diff`, update task status, report
what changed, and don't roll on into unrelated tasks.

## Postman collection

`postman/Dahab-Backend.postman_collection.json` mirrors every route in `routes/api.php`.
Whenever a task adds, changes, or removes an API endpoint, update the matching request in
that collection (and the environment file if new variables are needed) as part of the same
task — see `postman/README.md` for the exact steps. Do this before marking the task done.

## Commands

```bash
composer test               # Pest
./vendor/bin/pint           # format
composer swagger:generate   # OpenAPI
php artisan migrate:fresh --seed
```

## This repo is the source of truth for

API endpoints and HTTP methods · request parameters and validation · authentication ·
authorization (permissions) · response structures · pagination · filtering · sorting ·
error responses and `code`s · API Resources · API field names.

The contract lives in code and is generated from it — do **not** hand-write a parallel
`openapi.yaml`:

| Artefact | Role |
|---|---|
| `routes/api.php`, controllers, `app/Http/Requests`, `app/Http/Resources`, `app/Actions` | The behaviour |
| `#[OA\…]` attributes on controllers/Resources/Requests | The documented contract |
| `storage/api-docs/api-docs.json` (gitignored) | Generated OpenAPI — refresh with `composer swagger:generate` |
| `postman/Dahab-Backend.postman_collection.json` | Manual-testing mirror of every route — see "Postman collection" above |
| `specs/*/contracts/` | Spec Kit planning artefacts; may lag the code. Code wins on disagreement |

## When you add, change, or remove an endpoint or an API field

1. **Inspect existing consumers first** — `../dahab-dashboard/src` for `/dashboard/*`,
   `../dahab-flutter/lib` (+ `test/`) for `/customer/*`: the path, the field (search both
   `snake_case` and the mapped casing), the enum values and the permission strings.
2. **Classify the change** using the table in Part 1. Breaking changes ship under a new version
   prefix per the Constitution.
3. **Update the contract in the same task**: `#[OA\…]` attributes, then
   `composer swagger:generate`; the Postman request (body kept in sync with the FormRequest
   rules, auth, saved-token test scripts); Pest feature tests.
4. **Update or report consumer impact** — for a feature, implement the frontend changes per the
   multi-project workflow in Part 1; for a Backend-only task, report impact with the impact-report
   shape. Never silently break a consumer.
5. **Verify** authorization, validation and error responses for every new or changed endpoint, and
   update the API contract docs (`docs/platform/api-contract.md` when a convention changes).

Do not invent frontend behaviour. Don't add fields, filters or endpoints "because the UI
might want them" — build what the spec/docs and the user's request require, and when a frontend
needs something the API lacks, state it as a Backend requirement for the user to approve (ideally
through the Spec Kit lifecycle above).

## Database and migrations

Before creating or altering a migration, inspect the existing schema (`docs/Database schema/`),
related migrations, models, factories/seeders and tests. PostgreSQL semantics — never assume MySQL
behaviour. Enforce important business rules in the database where practical (FKs, CHECK, unique).
Append-only / audit / history tables never gain update or delete paths silently.

## Money and pricing

Never use floats for money or gold: decimal strings with BCMath (`bcadd`/`bcsub`/`bcmul`/`bcdiv`),
through `app/Support/Pricing/Money.php`, with deterministic scale and rounding. Pricing formulas live
in the Backend only — never duplicated in the Dashboard or Flutter.

## Secrets and credentials

Credentials live only in `.env` (or the approved secret store) — never in code, docs, tests,
fixtures, Postman, Git, commit messages, logs or chat output. `.env.example` holds variable names
with empty values. Tests use dummy credentials with `Http::fake` (or equivalent). If a credential is
found in the source or Git history: stop, report where (without repeating the value), and recommend
rotation and cleanup.

## External providers

Don't infer a provider's identity from a hostname, URL, old code or assumptions. If it is
unconfirmed, document it as pending confirmation, implement against its confirmed observable
behaviour behind an adapter, and don't rename it on a guess.

## Before declaring a feature complete

1. `git status` and `git diff` — only intended files changed, nothing unrelated.
2. Every task ID in `tasks.md` verified, not just ticked.
3. Relevant tests, then the full suite when appropriate.
4. Migrations (fresh + rollback), the API contract (`#[OA]` + `composer swagger:generate`),
   authorization, Postman, documentation.
5. Search the diff for accidentally included secrets.

Quality gates, where applicable: tests · lint · formatting · static analysis · build · migrations ·
API contract · authorization tests · security checks · frontend builds · cross-project verification
(`laravel-quality-gates` covers the Backend ones). A gate that could not be run is reported as not
run — never as passed. Never claim completion without verification.
