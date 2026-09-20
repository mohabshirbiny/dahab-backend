# Dahab Backend

Clean Laravel 12 skeleton for the Dahab Gold Marketplace Backend.

> This repository is **foundation only**. No Dahab business modules, models,
> migrations, or endpoints exist yet. Product requirements, database design,
> and workflows live under [`docs/`](./docs) and are the source of truth for
> subsequent phases.

## Stack

- Laravel 12 · PHP 8.3+
- PostgreSQL 16
- Redis 7
- Laravel Sanctum (API tokens)
- Laravel Horizon (queue supervisor over Redis)
- Laravel Notifications
- L5-Swagger (OpenAPI 3 documentation)
- Pest 3 (on top of PHPUnit 11)
- Docker / Docker Compose (app · nginx · postgres · redis · horizon)

## Project layout

```
app/
├── Actions/          # single-purpose invokable classes (future)
├── Enums/            # PHP 8 enums (future)
├── Http/
│   └── Controllers/
│       └── Api/V1/   # API v1 controllers (future)
├── Models/           # Eloquent models
├── Providers/
├── Services/         # domain services (future)
└── Support/          # framework-agnostic helpers (ApiResponse, ...)

bootstrap/app.php     # routing, middleware, JSON exception rendering
routes/api.php        # /api/v1 group + /health
config/               # database, sanctum, horizon, cors, l5-swagger, ...
database/migrations/  # framework + Sanctum tables only
docker/nginx/         # nginx site config used by docker-compose
docs/                 # product / architecture reference (source of truth)
tests/                # Pest tests (Feature/Unit)
```

## Local development (Laragon / native PHP)

```bash
cp .env.example .env
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
php artisan key:generate
# create the postgres database & user described in .env, then:
php artisan migrate
php artisan serve
```

Health check:

```bash
curl http://127.0.0.1:8000/api/v1/health
```

Swagger UI:

```bash
php artisan l5-swagger:generate
# http://127.0.0.1:8000/api/documentation
```

## Docker

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Services:

| Service    | Port | Notes                                  |
|------------|------|----------------------------------------|
| `web`      | 8080 | nginx → `http://localhost:8080`        |
| `postgres` | 5432 | database `dahab` / user `dahab`        |
| `redis`    | 6379 | cache · sessions · queue · horizon     |
| `horizon`  | —    | `php artisan horizon` (queue worker)   |

Health check inside the container network:

```bash
curl http://localhost:8080/api/v1/health
```

Horizon dashboard (requires local auth gate): `http://localhost:8080/horizon`.

## Tests

```bash
./vendor/bin/pest
# or
composer test
```

Tests use an in-memory SQLite database (framework tables only) — see `phpunit.xml`.

## Code style

```bash
./vendor/bin/pint
```

## What is intentionally NOT here

Per the skeleton mandate, none of the following exist in this phase:

- Gold Items, Buy Requests, Inspections, Wallets, Payments, Settlements,
  Commissions, Seller/Buyer modules, Marketplace endpoints
- Business-specific migrations, models, controllers, services, notifications
- Any endpoint under `/api/v1/*` beyond `/health` and the Sanctum `/user` probe

## Next phase

Once the foundation is accepted, subsequent work is driven by the reference
material in [`docs/`](./docs):

1. **Identity & Auth** — `docs/Technical Spec/dahab-spec-part1-auth.md`
2. **API surface** — `docs/Technical Spec/dahab-spec-part2-api.md`
3. **Backend logic & workflows** — `docs/Technical Spec/dahab-spec-part3-logic.md`
4. **Database migrations** derived from `docs/Database schema/*.sql`

Each module should be introduced under `app/Http/Controllers/Api/V1/…`,
paired with `Actions/`, `Services/`, `Requests/`, and `Resources/` as needed —
without inventing empty abstractions ahead of demand.
