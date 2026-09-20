---
name: "laravel-quality-gates"
description: "Run Dahab's pre-merge checks — Pint, Pest, migrations fresh and rollback, OpenAPI generation, route list, N+1 and security review. Use before marking a spec-kit task or feature complete, or before opening a PR."
argument-hint: "Optional: scope (e.g. a feature folder)"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

Run these in order and report real output. Don't claim success without it.

```bash
./vendor/bin/pint --test            # style (then ./vendor/bin/pint to fix)
composer test                        # Pest suite must be green
php artisan migrate:fresh --seed     # migrations apply cleanly on PostgreSQL
php artisan migrate:rollback --step=<n> && php artisan migrate   # new migrations reverse cleanly
composer swagger:generate            # OpenAPI builds without warnings
php artisan route:list --path=api/v1 # every route has the expected middleware
```

## Review checklist (Constitution Check)

- [ ] **I, Actor**: every state change records exactly one `customer_id` or `staff_id`.
- [ ] **II, Least privilege**: no tenancy that relies on a `WHERE` alone; new tables have RLS/grants per docs.
- [ ] **III, Docs**: behavior matches `docs/`; any doc gaps were fixed in `docs/` in the same change.
- [ ] **IV, Foundation**: migration + FormRequest + Resource + Action + feature test + OpenAPI exist for each new endpoint.
- [ ] **V, Tests**: ledger/state/notification paths are covered through HTTP with a refusal case.

## Common Laravel defects to scan for

- N+1: resources that touch relations not loaded with `with()`/`load()`. `Model::preventLazyLoading()` in non-production catches these in tests.
- Floats in money/gold maths or casts.
- `$guarded = []`, `request()->all()`, or `$request->input()` passed to `create()`/`update()` instead of `validated()`.
- Raw SQL with interpolated input (use bindings).
- Side effects dispatched inside a transaction without `afterCommit`.
- Secrets or PII in logs, exceptions or API responses.
- Missing `down()` in migrations.
