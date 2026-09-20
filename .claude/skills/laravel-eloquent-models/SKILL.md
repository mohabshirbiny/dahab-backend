---
name: "laravel-eloquent-models"
description: "Create Eloquent models, relationships, casts, enums, scopes and factories for Dahab tables. Use when a task adds or changes a model, a relationship, or test data factories."
argument-hint: "Model name(s) and table"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Generate

```bash
php artisan make:model <Name> --factory
php artisan make:enum <Name>Status   # backed enum in app/Enums
```

## Rules

- `protected $table` whenever the table name is singular or differs from Laravel's plural guess (the docs' schema uses singular names like `wallet`).
- Mass assignment: explicit `$fillable`. Never `$guarded = []` on money, gold, status or actor columns — those change only inside Actions.
- Casts via the `casts()` method:
  - money/grams → `'decimal:<scale>'` (string maths, never float)
  - status columns → backed enums in `app/Enums`
  - json → `'array'` / `AsArrayObject::class`
  - timestamps → `'immutable_datetime'`
- Relationships: typed return values (`BelongsTo`, `HasMany`, …) with explicit foreign keys when not conventional.
- Ledger and audit models are append-only: no `update()` / `delete()` paths; throw from `updating`/`deleting` model events if needed.
- No business logic in models beyond small accessors and query scopes. State changes belong in Actions (`laravel-actions-services`).
- Tenancy is enforced by PostgreSQL RLS, not global scopes (Constitution II). Don't add a `where customer_id` global scope as the security boundary.
- Enable `Model::shouldBeStrict(! app()->isProduction())` in `AppServiceProvider::boot()` if not already on, to catch lazy loading and missing attributes.

## Factories

- Every model gets a factory with realistic defaults and named states for each status (`->pending()`, `->approved()`).
- Factories for actor-attributed rows must set exactly one of `customer_id` / `staff_id`.
- Use `Factory::for()` / `has()` for relations instead of hard-coded IDs.
