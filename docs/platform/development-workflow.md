# Dahab Development Workflow

How to deliver a change across the Dahab projects. The rules themselves are in [`../../CLAUDE.md`](../../CLAUDE.md) (Part 1 — Platform);
this file holds the practical detail.

## 1. Feature lifecycle

1. **Understand** — read `dahab-backend/CLAUDE.md`, `architecture.md`, `api-contract.md`, the project guides
   (`dahab-dashboard/CLAUDE.md`, `dahab-flutter/README.md`), and the relevant
   product spec in `dahab-backend/docs/`. Inspect the relevant code in **all** projects.
2. **Impact analysis** — fill the YES/NO block from `dahab-backend/CLAUDE.md`. Name the affected files per project.
   State explicitly which projects are *not* affected and why.
3. **Spec** (non-trivial features) — `docs/features/<feature-name>.md` from [`../features/_TEMPLATE.md`](../features/_TEMPLATE.md).
   Backend work additionally follows the Spec Kit lifecycle in Part 2 of `dahab-backend/CLAUDE.md`.
4. **Implement in contract order** — Backend → API contract (OpenAPI/Postman) → Dashboard → Customer App
5. **Verify** — run the checks below for every project you touched.
6. **Report** — the Step 5 report shape in `dahab-backend/CLAUDE.md`.

## 2. Commands per project (only these exist)

### dahab-backend (`D:\laragon\www\dahab-backend`)

```bash
composer test                     # config:clear + Pest (in-memory SQLite)
./vendor/bin/pest --filter=Name   # a single test
./vendor/bin/pint                 # format  (pint --test to check only)
composer swagger:generate         # regenerate storage/api-docs/api-docs.json
php artisan migrate:fresh --seed  # local DB reset + seeded staff
php artisan serve                 # http://127.0.0.1:8000
```

Also update `postman/Dahab-Backend.postman_collection.json` whenever an endpoint changes (see `postman/README.md`).

### dahab-dashboard (`D:\laragon\www\dahab-dashboard`)

```bash
npm run dev          # Vite, port 3000
npm run type-check   # vue-tsc --build --force
npm run lint         # eslint   (lint:fix to fix)
npm run build        # type-check + vite build
```

No unit-test script exists.

### dahab-flutter (`D:\laragon\www\dahab-flutter`)

```bash
flutter pub get
flutter analyze
flutter test                                  # smoke (all routes, 4 widths, EN/AR), flows, pricing
flutter build web --release
flutter run -d chrome --web-port 8765 --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Live API test (creates a customer in the local DB; needs a running backend):
`LIVE_API=http://127.0.0.1:8000/api/v1 flutter test test/live/live_api_test.dart`.

## 3. API change checklist

- [ ] Backend FormRequest / Resource / Action / controller `#[OA]` updated
- [ ] Pest feature tests cover success, validation, auth (401/403) and permission cases
- [ ] `composer swagger:generate` run; Postman request + example updated
- [ ] Change classified (non-breaking / potentially breaking / breaking — see `dahab-backend/CLAUDE.md`)
- [ ] Consumers searched: Dashboard `src/` (dashboard surface), Flutter `lib/` + `test/` (customer surface)
- [ ] Frontend types/models, services/API clients, state, screens updated (or impact reported if not requested)
- [ ] Frontend error handling covers any new `code`s; UI covers any new enum values
- [ ] Flutter fake backend in `test/` returns the new real shapes
- [ ] `docs/platform/api-contract.md` updated if a convention changed

## 4. Git coordination

Each project is its own repository. **Never merge them.**

| Project | Repo | Default branch | Remote |
|---|---|---|---|
| dahab-backend | yes | `main` | `github.com/mohabshirbiny/dahab-backend` |
| dahab-dashboard | yes | `main` | `github.com/mohabshirbiny/dahab-dashboard` |
| dahab-flutter | **no** (to be added later) | — | — |

- Multi-project feature → the **same branch name** in every affected repo: `feature/<feature-name>`
  (kebab-case), e.g. `feature/customer-identity-approval` in backend, dashboard and (once it has a repo) flutter.
- Create/switch branches **only when asked**. Check `git status` first: uncommitted work exists in some
  repos (e.g. dahab-dashboard) and must not be disturbed.
- **Never commit or push unless explicitly instructed.** When asked, commit per repo, one focused commit set
  per project; land the Backend first when a frontend depends on a new API.
- `dahab-flutter` is not under version control — mention this before any Git operation there; `git init`
  only if the user asks.
- The platform guide and docs live in the Backend repo (`CLAUDE.md`, `docs/platform/`, `docs/features/`),
  so changes to them are tracked there. `D:\laragon\www\CLAUDE.md` is only an untracked pointer.

## 5. Local integration setup

1. Backend: `.env` with PostgreSQL + Redis, `php artisan migrate --seed`, `php artisan serve`.
2. `CORS_ALLOWED_ORIGINS` in backend `.env` must include every frontend origin in use
   (`http://localhost:3000`, `http://localhost:8765`).
3. Dashboard: `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1` (`.env.development.local`).
4. Flutter: defaults to `http://127.0.0.1:8000/api/v1`; for a deploy build pass the production host:
   `flutter build web --release --dart-define=API_BASE_URL=<production origin>/api/v1`.
5. OTP/SMS codes are `123456` in the backend `local` environment. Staff: `<role>@dahab.test` /
   `seeded-password-1`; `ceo`/`coo`/`finance` need TOTP MFA.

## 6. Mocks

Screens with no Backend route yet use mocks (Dashboard `src/mock/`, Flutter `lib/mock/` +
`services/mock_repositories.dart`). Replacing a mock with the real API is a feature: it needs the Backend
route to exist first. Never present mock data as Backend data, and never add Backend fields just to match a mock.
