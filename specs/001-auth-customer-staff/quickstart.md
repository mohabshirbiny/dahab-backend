# Quickstart — Authentication (Customer & Dashboard Staff)

Runnable validation guide for what is **implemented today**. Read `spec.md` (see its *Implementation status* table) and `plan.md` for the "why"; this file is the "how do I check it works". Sections marked *Planned* describe endpoints that do not exist yet — do not expect them to respond.

## Prerequisites

- The project can `composer install` and `php artisan migrate` (see repo `README.md`).
- PHP built with Argon2 (`php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'` → `bool(true)`; the official PHP Docker images are).
- Postgres 16 running (Docker or local).
- Redis 7 running (Docker or local; falls back to file/database drivers via `.env`).
- `.env` values for `DAHAB_AUTH_*` and `HASH_DRIVER`/`ARGON_*` are populated from `.env.example`.

## Setup

```bash
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
cp .env.example .env
php artisan key:generate
php artisan migrate --seed        # seeds Spatie roles/permissions + one staff per role (<role>@dahab.test) in local
php artisan l5-swagger:generate   # writes storage/api-docs/api-docs.json
```

Verify:

```bash
curl -s http://127.0.0.1:8000/api/v1/health | jq .
```

## The two API surfaces

| Surface | Prefix | Guard | Access ability | Refresh ability |
|---|---|---|---|---|
| Customer API | `/api/v1/customer/*` | `auth:customer` | `customer:access` | `customer:refresh` |
| Dashboard API | `/api/v1/dashboard/*` | `auth:staff` | `staff:access` | `staff:refresh` |

- A token of the other principal → `401 unauthenticated`.
- The right principal's token with the wrong ability (refresh token on an access endpoint, or the reverse) → `403 forbidden`.
- There is no `/api/v1/auth/*` and no `/api/v1/user`.

## Run the feature test suite

```bash
./vendor/bin/pest --filter=Auth
```

Every test file under `tests/Feature/Auth/**` is a boundary-level Pest spec. `RefreshDatabase` runs each in isolation. Expected: all green. The architecture-level ones:

| File | Proves |
|---|---|
| `Shared/PrincipalIsolationTest.php` | guards, 401 across principals, removed routes, route-table audit |
| `Shared/TokenAbilitiesTest.php` | exact abilities, refresh ≠ access (403), rotation and replay |
| `Shared/RateLimiterSeparationTest.php` | one limiter per concern, 429 code follows the limiter |
| `Shared/PasswordHashingTest.php` | Argon2id storage, bcrypt legacy rehash |
| `Shared/OpenApiGenerationTest.php` | generated OpenAPI matches the implemented routes and schemes |

Note: `phpunit.xml` runs as the `dahab` database user. Run manual `migrate`/`db:seed` commands with `DB_USERNAME=dahab DB_PASSWORD=secret` so table ownership stays consistent with the tests.

## Contracts

- OpenAPI contract (design target, `x-implemented` marks what is live): [`contracts/openapi.yaml`](./contracts/openapi.yaml).
- Error codes: [`contracts/error-codes.md`](./contracts/error-codes.md).
- Live Swagger UI (after `php artisan l5-swagger:generate` + `php artisan serve`): <http://127.0.0.1:8000/api/documentation> — two tags, `Customer Auth` and `Dashboard Auth`, four bearer schemes.

## Manual smoke test — Customer API (implemented)

```bash
BASE=http://127.0.0.1:8000/api/v1
DEV="X-Device-Id: $(uuidgen)"

# Register -> session (device becomes trusted)
curl -sS "$BASE/customer/auth/register" -H "$DEV" -H 'Content-Type: application/json' \
  -d '{"phone":"+201000000001","password":"correct-horse-battery","preferred_lang":"ar"}' | jq .
# data.session.access_token  -> ACCESS
# data.session.refresh_token -> REFRESH

# Read own profile (access token)
curl -sS "$BASE/customer/auth/me" -H "Authorization: Bearer $ACCESS" | jq .

# Refresh token on an access endpoint -> 403 forbidden
curl -sS -i "$BASE/customer/auth/me" -H "Authorization: Bearer $REFRESH" | head -1

# Rotate: refresh token on the refresh endpoint -> new pair in data.*
curl -sS -X POST "$BASE/customer/auth/refresh" -H "Authorization: Bearer $REFRESH" | jq .
# Replaying the same $REFRESH now -> 401 refresh_invalid and the whole family is revoked

# Sign in again from the same device
curl -sS "$BASE/customer/auth/login" -H "$DEV" -H 'Content-Type: application/json' \
  -d '{"phone":"+201000000001","password":"correct-horse-battery"}' | jq .

# Sign out (current family) / sign out everywhere
curl -sS -X POST "$BASE/customer/auth/logout" -H "Authorization: Bearer $ACCESS" -i | head -1     # 204
curl -sS -X POST "$BASE/customer/auth/logout-all" -H "Authorization: Bearer $ACCESS" -i | head -1 # 204
```

Signing in from a device that has never been trusted for that customer is currently refused with `401 invalid_credentials`; the OTP branch is *Planned* (US3).

## Manual smoke test — Dashboard API (partially implemented)

There is no staff login endpoint yet (*Planned*, US2). Mint a session for a seeded account instead:

```bash
php artisan tinker --execute='$s = App\Models\Staff::where("email","ceo@dahab.test")->first(); echo json_encode(app(App\Actions\Auth\Shared\IssueTokenFamilyAction::class)->forStaff($s)->toArray(), JSON_PRETTY_PRINT);'
```

```bash
# Own profile with roles + effective Spatie permissions (staff access token)
curl -sS "$BASE/dashboard/auth/me" -H "Authorization: Bearer $STAFF_ACCESS" | jq .

# A customer token here is a different principal -> 401
curl -sS -i "$BASE/dashboard/auth/me" -H "Authorization: Bearer $ACCESS" | head -1

# Rotate the staff refresh token
curl -sS -X POST "$BASE/dashboard/auth/refresh" -H "Authorization: Bearer $STAFF_REFRESH" | jq .
```

## Planned (not implemented — will not respond yet)

`POST /customer/auth/otp/verify|resend`, `password/reset-request|reset`, `email`, `email/verify`; `POST /dashboard/auth/login`, `mfa/verify|enroll`, `logout`, `logout-all`, `password/*`; `POST /dashboard/customers/{id}/suspend|unsuspend`. Tracked in `tasks.md`.

## What "done" looks like for the implemented surface

- All Pest feature tests under `tests/Feature/` pass (`composer test`).
- `php artisan l5-swagger:generate` succeeds and the document contains exactly the operations marked `x-implemented: true` in `contracts/openapi.yaml`.
- `php artisan route:list --path=api/v1` shows every `customer/*` route on `auth:customer` + `abilities:customer:*` and every `dashboard/*` route on `auth:staff` + `abilities:staff:*` (register and login are the only public customer routes).
- `php artisan migrate:fresh` succeeds and `php artisan migrate:rollback` returns the schema to empty (Constitution / reversibility).
- Grep of the built OpenAPI JSON for `password_hash|token_hash|otp_hash|mfa_secret` returns 0 hits (also asserted in `OpenApiGenerationTest`).
- `./vendor/bin/pint --test` is clean for the files you touched (several pre-existing files still fail it — see `tasks.md` T139).

## Failure signatures to check when a test fails

| Symptom | Likely cause |
|---|---|
| Sign-in fails for a correct password | `password_hash` was stored with a different algorithm and `argon.verify` was switched on — keep `HASH_VERIFY` unset/false (`config/hashing.php`) |
| New passwords hash as `$2y$` | `HASH_DRIVER` overridden in the environment; it must be `argon2id` |
| `403 forbidden` on `/customer/auth/me` right after sign-in | a refresh token was sent instead of the access token |
| `401 unauthenticated` on a route you expected a token to reach | the token belongs to the other principal, or the route's guard is wrong (`auth:customer` vs `auth:staff`) |
| Second request in a test sees the previous request's user | Sanctum guards cache the user per app instance — use `$this->bearer($token)` (resets guards) |
| `Sanctum::actingAs($customer)` gets 401 | pass the guard: `Sanctum::actingAs($customer, ['customer:access'], 'customer')` |
| 429 `too_many_requests` where `account_locked` was expected (or the reverse) | the identity bucket vs. IP bucket of a login limiter, or a different limiter than you think is on the route — check `php artisan route:list -v` |
| OTP always returns `otp_expired` in tests | *Planned* — missing `Carbon::setTestNow` or Redis TTL not honoring `array` cache driver |
| `permission_denied` on a role that should have the permission | Spatie roles/permissions not seeded — `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder` |
| Audit log rows missing | `RecordAuditLogAction` not invoked inside the same transaction — check the Action wraps its DB writes in `DB::transaction()` and calls the audit action inside it |
