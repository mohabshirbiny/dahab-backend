# Dahab Platform Architecture

As found in the code on 2026-09-26. Describes what exists, not plans; when the code changes, update this.

```
                    ┌──────────────────────────────┐
                    │        Laravel Backend       │
                    │  dahab-backend · REST /api/v1│
                    │  PostgreSQL · Redis · Horizon│
                    └──────────────┬───────────────┘
                                   │  HTTPS JSON, Bearer (Sanctum)
                 ┌─────────────────┴──────────────────┐
                 │ /api/v1/dashboard/*                │ /api/v1/customer/*
                 ▼                                    ▼
       ┌──────────────────┐               ┌──────────────────────┐
       │    Dashboard     │               │   Customer Flutter   │
       │  Vue 3 + Vuetify │               │  Web (dahab-flutter) │
       │ (dahab-dashboard)│               │                      │
       └──────────────────┘               └──────────────────────┘
```

There is no shared code, package, or monorepo tooling between the projects. They are coupled **only**
through the HTTP API, documented in [`api-contract.md`](api-contract.md).

## dahab-backend — Laravel API (source of truth)

- **Stack**: Laravel 12, PHP 8.3+ (^8.2 in composer), PostgreSQL 16, Redis 7 (cache/session/queue),
  Sanctum (tokens), Spatie Laravel Permission (staff), Horizon, Notifications, l5-swagger, google2fa (TOTP),
  Pest 3. Docker Compose (app · nginx · postgres · redis · horizon) or Laragon natively. Tests run on PostgreSQL
  (row-level security and CHECK constraints cannot be tested on SQLite), as the non-superuser `dahab` role.
- **Layout**
  - `routes/api.php` — every route, `/api/v1` group, split into Customer and Dashboard surfaces.
  - `app/Http/Controllers/Api/V1/{Customer,Dashboard}/…` — thin controllers carrying `#[OA\…]` attributes.
  - `app/Http/Requests/…` — validation. `app/Http/Resources/{Customer,Staff}/…` — response shapes
    (separate resources per surface, e.g. `Customer\IdentityDocumentResource` vs `Staff\IdentityDocumentResource`).
  - `app/Actions/{Auth,Dashboard,Identity}/…` — single-purpose business operations (the business logic).
  - `app/Enums/…` — statuses, error codes, permissions, roles, token abilities.
  - `app/Exceptions/{AuthApiException,DomainApiException}` → rendered in `bootstrap/app.php`.
  - `app/Http/Middleware/` — `EnforceStaffPermission` (`staff.permission`), `EnsureStaffStanding`
    (`staff.standing`), `EnsureCustomerStanding` (`customer.gate`), `SetRequestContext` (device/IP context),
    `SetDatabaseActor` (binds the database actor per request — Constitution I/II), `ElevateDatabaseScope`
    (`db.elevate:bootstrap` on unauthenticated auth routes).
  - `config/dahab-auth.php`, `config/dahab-identity.php`, `config/sms.php` — domain configuration.
- **Domain models today**: `Customer`, `CustomerPassword`, `CustomerTrustedDevice`, `IdentityDocument`,
  `DocumentViewLog`, `OneTimeToken`, `AccountFreeze`, `AuditLog`, `Staff`, `StaffPassword`, `StaffMfa`,
  `StaffDeviceFingerprint`, `FounderDeviceApproval`.
- **Implemented modules**: customer auth (registration, login, device OTP, token rotation), identity
  documents (upload, submit, staff review with audited image views), dashboard customer listing/detail,
  staff auth (login, MFA/TOTP), Dashboard-managed staff roles and permissions and the customer verified
  gate (spec 002), and customer data isolation by PostgreSQL row-level security (spec 003). Marketplace modules (items, orders, wallet, payments, …) are **not built yet**;
  their design is in `docs/`.
- **Customer data isolation (spec 003)**: every customer-owned table has **forced** row-level security.
  `App\Support\DatabaseActor` binds the scope (`customer` · `staff` · `bootstrap` · `system` · `maintenance`)
  per request, queued job or CLI migrate/seed, and restores it afterwards; no scope sees no customer rows.
  **A new table with a customer owner column must enable + force RLS and add its policy in the same
  migration** (pattern in `docs/Database schema/05_schema_security.sql` §19); `CustomerTableIsolationTest`
  fails the build otherwise. Details: `docs/Technical Spec/dahab-spec-part1-auth.md` §5.1.
- **Governance**: `.specify/memory/constitution.md` (named actor, least privilege in the engine, docs as
  source of truth, versioning), Spec Kit specs in `specs/`, product/tech specs in `docs/`
  (`Technical Spec/dahab-spec-part{1,2,3}-*.md`, `dahab-dashboard-authorization.md`, DB design docs).

## dahab-dashboard — Staff dashboard (Vue 3)

- **Stack**: Vue 3 + TypeScript, Vuetify 4, Pinia (+ persisted state), Vue Router 5, TanStack Vue Query,
  Axios, vue-i18n, Tailwind 4, Vite 8, ESLint (vuetify config), vue-tsc.
- **Layers**: `pages/`, `components/` → `composables/` (Query hooks) + `stores/` (auth, ui, toast, app) →
  `services/*.service.ts` (the only API callers; map `snake_case` → app types; map Backend `code` →
  `ServiceErrorCode` in `services/errors.ts`) → `api/axios.ts` (Bearer, refresh-and-retry), `api/endpoints.ts`,
  `api/session.ts` → Backend.
- **Base URL**: `VITE_API_BASE_URL` (default `/api/v1`). Dev server port 3000.
- **Authorization**: UI gated on permission strings (`src/types/staff.ts`, `usePermissions`, router guards).
- **Live vs mock**: staff auth + MFA, customers list/detail, identity documents list/detail/image/review
  are live. Overview and other sections use `src/mock/`. See `dahab-dashboard/docs/06-BACKEND-API-INTEGRATION.md`.
- **Design reference**: `docs/dahab-admin-dashboard.html`.

## dahab-flutter — Customer app (Flutter Web)

- **Stack**: Flutter (Dart SDK ^3.11), go_router (hash URLs), provider, http + http_parser, shared_preferences
  (localStorage on web), intl, file_picker, flutter_svg; bundled fonts; EN/AR with RTL. flutter_lints.
- **Layers**: `features/<area>/*_screen(s).dart` → controllers/state (`services/auth/auth_controller.dart`,
  `account_controller.dart`, `app_session.dart`, `sell_draft.dart`; Provider) → repositories / API
  (`services/repositories.dart` interfaces, `services/auth/auth_api.dart`) → `services/api/api_client.dart`
  → Backend. Models in `lib/models/`, mocks in `lib/mock/` + `services/mock_repositories.dart`.
- **API client**: sends `Accept`, `X-Device-Id` (generated once, stored), `X-Device-Platform: web`; Bearer
  on authenticated calls; proactive + on-401 refresh with a single in-flight refresh; maps the error
  envelope to `ApiException(status, code, message, fieldErrors, extra)`; network failure → `network_error`.
- **Base URL**: `--dart-define=API_BASE_URL=…` (`lib/core/config/app_config.dart`); defaults to the local
  backend `http://127.0.0.1:8000/api/v1`. The production host is passed only when building a deploy version.
- **Live vs mock**: registration (6 steps incl. ID photo), sign-in with device OTP, session restore, refresh,
  sign-out are live. Catalog, sell, orders, wallet, account data, notifications, admin screens are mock.
  `services/pricing.dart` holds prototype pricing maths that should become server quotes.
- **Design reference**: `doc/dahab-app-prototype.html`.
- **Git**: no repository yet (to be added later).

## dahab-pwa — out of scope

An earlier Vue 3 customer PWA (registration + login only) exists in `../dahab-pwa`. It is ignored for now.

## Cross-cutting flows

**Customer onboarding / identity approval** (spans all three):

```
Customer App ──register (6 steps, ID photo)──► Backend: Customer = pending_verification,
                                                 IdentityDocument = pending
Dashboard ──GET identity-documents / image──► Backend (identity.view; every image view logged)
Dashboard ──POST …/{id}/review──────────────► Backend (identity.review): verified | needs_resubmission | rejected
                                                 → Customer status updated (active / rejected …)
Customer App ──login──► Backend: refuses pending/rejected/suspended with specific codes;
                                 new device → OTP challenge
```

**Statuses**: `CustomerStatus` = `pending_verification | active | rejected | suspended`;
`IdentityDocumentStatus` = `pending | verified | needs_resubmission | rejected`. Every consumer that renders
these must handle all values (adding one is potentially breaking).

## Environments (local)

| Service | Default |
|---|---|
| Backend (`php artisan serve`) | `http://127.0.0.1:8000` (Docker nginx: `:8080`) |
| Dashboard (Vite) | `http://localhost:3000` |
| Flutter web | `flutter run -d chrome --web-port 8765` or `python -m http.server 8765 --directory build/web` |
| Local OTP/SMS code | `123456` (backend `local` env) |
| Seeded staff | `<role>@dahab.test` / `seeded-password-1` after `php artisan db:seed` |

Each frontend origin must be in the Backend `CORS_ALLOWED_ORIGINS`.
