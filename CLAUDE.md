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

**Customer App** — customer UI · customer state · API consumption (`/customer/*` only) ·
navigation · UI validation · loading/error/empty states.

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
in `docs/features/<feature-name>.md` using `docs/features/_TEMPLATE.md`.

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

## Current state (verified 2026-09-26 — re-verify before relying on it)

- Backend implements: health, customer registration (6 steps) / login / new-device OTP / refresh / me /
  logout, customer uploads + identity-document submission; staff login + MFA / refresh / me / logout;
  dashboard customers list/show and identity-document list/show/image/review; Dashboard-managed staff
  roles/permissions and staff role assignment (`/dashboard/permissions`, `/dashboard/roles*`,
  `/dashboard/staff*`) and the customer verified gate (spec 002); customer data isolation by forced
  PostgreSQL row-level security (spec 003). Nothing else yet.
- Dashboard: staff auth + customers/identity screens are live; Overview and other sections are mock.
- Flutter: registration + sign-in (with device OTP), session restore, refresh and sign-out are live;
  catalog, orders, wallet, notifications, etc. run on mock repositories (`lib/services/mock_repositories.dart`).
- Flutter's `API_BASE_URL` defaults to `http://127.0.0.1:8000/api/v1`; the production host is passed
  with `--dart-define` only when building a deploy version.

---

# Part 2 — Backend repo (dahab-backend)

Laravel 12 API (PHP 8.3+, PostgreSQL 16, Redis, Sanctum, Horizon, Pest 3, l5-swagger).
The rules are in `.specify/memory/constitution.md`, and the specs live in `docs/`.

## Spec Kit + Laravel skills

Non-trivial work goes through the Spec Kit lifecycle (`/speckit-specify → /speckit-clarify → /speckit-plan → /speckit-tasks → /speckit-implement → /speckit-analyze`).
Use the Laravel skills in `.claude/skills/laravel-*` inside those phases:

| Spec Kit phase | Laravel skills to apply |
|---|---|
| `/speckit-plan` (data-model, contracts) | `laravel-migrations`, `laravel-eloquent-models`, `laravel-api-endpoints`, `laravel-auth-authorization` |
| `/speckit-tasks` | Split each endpoint into tasks along the skill boundaries: migration → model/factory → Action → FormRequest/Resource/controller/OpenAPI → Pest feature test |
| `/speckit-implement` | The skill for each task's layer: `laravel-migrations`, `laravel-eloquent-models`, `laravel-actions-services`, `laravel-api-endpoints`, `laravel-auth-authorization`, `laravel-queues-notifications`, `laravel-pest-testing` |
| Before marking tasks done, `/speckit-analyze`, or a PR | `laravel-quality-gates` |

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

Do not invent frontend behaviour. Don't add fields, filters or endpoints "because the UI
might want them" — build what the spec/docs and the user's request require, and when a frontend
needs something the API lacks, state it as a Backend requirement for the user to approve (ideally
through the Spec Kit lifecycle above).
