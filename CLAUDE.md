# Dahab Backend — Agent Guide

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

## Dashboard sync — this API has a consumer

`../dahab-dashboard` (Vue 3 + TypeScript + Vuetify, a **separate repo**) consumes the
`/api/v1/dashboard/*` surface. The workspace rules are in `../CLAUDE.md` (applies to the
Dahab projects only); the essentials are repeated here so this repo stands on its own.
Never move or copy files between the two repos.

### This repo is the source of truth for

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

### When you add, change, or remove an endpoint or an API field

1. **Inspect existing consumers first** — search `../dahab-dashboard/src` for the path,
   the field (Backend is `snake_case`; the Dashboard is mostly `camelCase`, search both),
   the enum values and the permission strings. Typical hits: `src/api/endpoints.ts`,
   `src/services/*.service.ts`, `src/mock/`, `src/types/`, `src/composables/`, `src/stores/`,
   then components/pages.
2. **Classify the change**: non-breaking (new optional field/endpoint) · potentially breaking
   (removed field, changed type/nullability, new required input, new enum value, tighter
   auth/permission) · **breaking** (renamed field, changed URL/method, changed envelope or
   error `code`). Breaking changes ship under a new version prefix per the Constitution.
3. **Update the contract in the same task**: `#[OA\…]` attributes, then
   `composer swagger:generate`; the Postman request (body kept in sync with the FormRequest
   rules, auth, saved-token test scripts); Pest feature tests.
4. **Report Dashboard impact** using the impact-report shape in `../CLAUDE.md`: affected
   Dashboard files, breaking-change class, and anything the Dashboard needs that this API
   does not yet provide. Never silently break the Dashboard.
5. **Only edit the Dashboard if asked.** Backend tasks report Dashboard impact; they do not
   rewrite Dashboard code unprompted.

Do not invent frontend behaviour. Don't add fields, filters or endpoints "because the UI
might want them" — build what the spec/docs and the user's request require, and when the
Dashboard needs something the API lacks, state it as a Backend requirement for the user to
approve (ideally through the Spec Kit lifecycle above).
