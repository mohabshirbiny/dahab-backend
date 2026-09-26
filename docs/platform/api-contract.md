# Dahab API Contract

The conventions of the Dahab REST API, as implemented in `dahab-backend` (verified 2026-09-26).
This file **summarises** the contract; it does not replace it.

- **The contract is the Backend code**, documented by `#[OA\…]` attributes on controllers, Requests,
  Resources and `app/Support/*` DTOs, and generated into OpenAPI 3 by l5-swagger:
  `dahab-backend/storage/api-docs/api-docs.json` (gitignored — `composer swagger:generate`;
  Swagger UI at `/api/documentation`). **Treat the generated OpenAPI as the API contract.**
- Per-endpoint request/response examples: `dahab-backend/postman/Dahab-Backend.postman_collection.json`.
- Do not hand-write an `openapi.yaml`. `dahab-backend/specs/*/contracts/` are planning artefacts only.
- If this file disagrees with the code, the code wins — fix this file.

## Versioning

- All routes are under **`/api/v1`** (`routes/api.php`, `Route::prefix('v1')`; OpenAPI server `/api/v1`).
- Additive, non-breaking changes stay in `v1`.
- Per the Backend Constitution, a **breaking change ships under a new prefix** (`/api/v2`), and the old
  prefix is deprecated on a documented schedule.
- Precedent for retiring an endpoint inside v1: `POST /customer/auth/register/complete` is kept as a
  **410** stub returning `registration_endpoint_deprecated` (replaced by `/register/submit`).

## Surfaces and consumers

| Surface | Prefix | Guard / token | Consumers |
|---|---|---|---|
| Public | `/api/v1/health` | none | any |
| Customer | `/api/v1/customer/*` | `auth:customer` (Sanctum, `customers` provider) | `dahab-flutter` |
| Dashboard | `/api/v1/dashboard/*` | `auth:staff` (Sanctum, `staff` provider) | `dahab-dashboard` |

A customer token is never valid on `/dashboard/*` and vice-versa (**401**). **Any change to a surface
must be checked against every consumer listed for it**; changes to shared conventions (envelope, error
codes, auth headers) affect all consumers.

## Authentication

Bearer tokens only (Sanctum personal access tokens). No cookie/SPA session auth — `statefulApi()` is
deliberately not enabled.

- **Token pair.** Sign-in issues a *session* object (`app/Support/SessionDto.php`):
  `token_type` (`Bearer`), `access_token`, `access_token_expires_at`, `refresh_token`,
  `refresh_token_expires_at`, `family_id`. Defaults: access 15 min, refresh 30 days
  (`config/dahab-auth.php`, env-overridable).
- **Abilities.** Access tokens carry `customer:access` / `staff:access`; refresh tokens carry
  `customer:refresh` / `staff:refresh`. The wrong kind on an endpoint → **403 `forbidden`**.
- **Refresh.** `POST /{customer|dashboard}/auth/refresh` with the **refresh token** as Bearer; rotates on
  every use (returns a new session). Rejected → **401 `refresh_invalid`**; clients must then sign out.
- **Customer sign-in.** `POST /customer/auth/login` requires the `X-Device-Id` header (also
  `X-Device-Platform`, default `web`). From a trusted device → `{ data: { customer, session } }`.
  From a new device → `{ data: { otp_required: true, otp_channel, challenge_id, expires_at, resend_available_at } }`;
  finish with `POST /customer/auth/otp/verify` (resend: `/otp/resend`) from the same device.
  Accounts in `pending_verification` / `rejected` / `suspended` are refused with their codes.
- **Customer registration** is six steps under `/customer/auth/register/*`
  (`start → verify-phone-otp → email → verify-email-otp → documents → submit`), state held server-side
  under an opaque `registration_ref`; only `submit` writes to the database.
- **Staff sign-in.** `POST /dashboard/auth/login` (email + password) → `{ data: { staff, session } }`, or,
  for MFA roles, `{ data: { … mfa_required | mfa_enrollment_required, session_ref, expires_at } }`;
  finish with `/dashboard/auth/mfa/verify` or `/mfa/enroll` (TOTP).
- `GET …/auth/me`, `POST …/auth/logout`, `POST …/auth/logout-all` exist on both surfaces.

## Authorization

- **Customer**: a customer can act only on its own resources (`/customer/me/*`).
- **Staff**: permission-based via Spatie Permission, enforced per route by the
  `staff.permission:<code>` middleware (`EnforceStaffPermission`), which returns **403 `permission_denied`**
  and audit-logs the denial. Current permissions (`app/Enums/StaffPermission.php`):
  `customer.view`, `customer.suspend`, `identity.view`, `identity.review`.
  Roles (`app/Enums/StaffRole.php`): `ceo`, `coo`, `finance`, `operations`, `verification`, `igi_branch`.
- Frontends gate UI on the **permission strings** from `GET /dashboard/auth/me`, never on role names.
  A new permission is a contract change: update `StaffPermission`, seeders, the route, and the Dashboard's
  `src/types/staff.ts` / `usePermissions` / route guards.

## HTTP methods and resource naming

- Methods in use: `GET` (read), `POST` (create and actions). No `PUT`/`PATCH`/`DELETE` routes exist yet;
  follow REST conventions if introduced.
- Paths are lower-case, kebab-case, plural nouns: `/dashboard/customers`, `/dashboard/identity-documents/{document}`.
- State transitions are sub-resource actions via `POST`: `/identity-documents/{document}/review`,
  `/auth/logout`, `/auth/otp/verify`.
- Route parameters are **UUIDs** (`whereUuid`). Customer "own" resources live under `/customer/me/*`.
- Route names mirror paths: `api.v1.<surface>.<resource>.<action>`.
- Field names are **`snake_case`** in requests and responses. Frontends map to their own casing in
  their service/model layer, never in UI components.

## Request format

- JSON bodies (`Content-Type: application/json`), `Accept: application/json`.
- File uploads are `multipart/form-data` (`/customer/auth/register/documents`, `/customer/me/uploads`).
- Validation lives in `app/Http/Requests/*` FormRequests; enums are validated with `Rule::enum(...)`.
- Customer requests send `X-Device-Id` and `X-Device-Platform` (both frontends do this on every request).

## Response format

| Case | Status | Body |
|---|---|---|
| Single resource / action result | 200 (201 on create) | `{ "data": { … } }` |
| Collection, paginated | 200 | `{ "data": [ … ], "links": { … }, "meta": { … } }` |
| No content (logout, logout-all) | 204 | empty |
| Error | 4xx/5xx | `{ "message": "…", "code": "…", "errors"?: { … }, …extra }` |

`App\Support\ApiResponse::ok()` builds `{ data, meta? }`; Laravel API Resources build the same envelope.

## Pagination

- Laravel length-aware pagination through `Resource::collection($paginator)`:
  `meta` has `current_page`, `from`, `last_page`, `per_page`, `to`, `total` (+ `path`, `links`);
  `links` has `first`, `last`, `prev`, `next`.
- Query params: `page`, `per_page` (integer 1–50, default 25). Current paginated endpoints:
  `GET /dashboard/customers`, `GET /dashboard/identity-documents`.
- Filters are optional query params validated by the list FormRequest (e.g. `status` as an enum).
- The Dashboard types this as `ApiPageMeta` in `src/types/api.ts`.

## Validation errors

**422**, `code: "validation_failed"`:

```json
{ "message": "The phone field is required.", "code": "validation_failed",
  "errors": { "phone": ["The phone field is required."] } }
```

Keys in `errors` are request field names (`snake_case`). Clients show them per field
(Flutter: `ApiException.fieldErrors`; Dashboard: `services/errors.ts`).

## Error responses

Rendered centrally in `dahab-backend/bootstrap/app.php` for every `api/*` request. **`code` is the stable,
machine-readable value clients must switch on**; `message` is human text and may change.

- Auth codes: `app/Enums/AuthErrorCode.php` (`unauthenticated`, `invalid_credentials`, `account_locked`,
  `account_suspended` (+ `suspended_reason`), `account_frozen`, `account_pending_verification`,
  `account_rejected`, `permission_denied`, `otp_invalid`, `otp_expired`, `mfa_invalid`, `refresh_invalid`,
  `registration_*`, …).
- Domain codes: `app/Exceptions/DomainApiException.php` (`document_already_pending` 409,
  `illegal_document_transition` 409, `unsupported_doc_kind` 422, `upload_token_invalid` 422, …).
- Generic: `forbidden` (403, wrong token ability or HTTP 403), `not_found` (404), `method_not_allowed`
  (405), `too_many_requests` (429, with `Retry-After`), `server_error` (500; message hidden unless debug).
- Extra top-level keys may accompany an error (e.g. `resend_available_at`, `suspended_reason`); clients
  should tolerate unknown keys.

## Status codes in use

`200` OK · `201` Created · `204` No Content · `401` unauthenticated / bad credentials / bad refresh ·
`403` permission or state refusal · `404` · `405` · `409` conflicting state · `410` deprecated endpoint ·
`422` validation / invalid input · `429` rate limited · `500`.

## Rate limiting

Named limiters on sensitive routes (`throttle:auth.customer.login`, `auth.customer.register`,
`auth.customer.register.otp`, `auth.customer.register.documents`, `auth.otp.verify`, `auth.staff.login`,
`auth.staff.mfa`, `auth.refresh`, `customer.uploads`) plus the default API throttle. Identity lockouts
return **429 `account_locked`**.

## CORS

`config/cors.php`: `api/*`, origins from `CORS_ALLOWED_ORIGINS` (comma-separated; `*` if unset).
Each frontend's dev origin must be listed (Dashboard Vite :3000, Flutter web :8765).

## Backward-compatibility rules

1. Adding an optional response field, a new endpoint, or an optional query param is **non-breaking**.
2. Adding an enum value, removing a field, changing type/nullability, adding a required input or a new
   validation rule, or tightening auth/permissions is **potentially breaking** — check every consumer.
3. Renaming a field, changing a URL/method, the envelope, or an error `code` is **breaking** → new
   version prefix, per the Constitution, and only after the user approves.
4. Never change the meaning of an existing `code`. Add new codes instead.
5. Clients must ignore unknown response fields and handle unknown enum values gracefully.
6. Retire endpoints with a stub (e.g. 410 + explicit code) before removal, and note it here.

## Every API change — checklist

1. Backend: FormRequest / Resource / Action / controller `#[OA]` / Pest tests / Postman request.
2. `composer swagger:generate`.
3. Search **both** frontends (Dashboard for `/dashboard/*`, Flutter for `/customer/*`) for the path, fields (both casings),
   enum values and permission strings; update or report every consumer.
4. Update this file if a convention (not just an endpoint) changed.
