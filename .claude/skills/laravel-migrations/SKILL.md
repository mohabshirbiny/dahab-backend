---
name: "laravel-migrations"
description: "Write reversible Laravel 12 migrations for PostgreSQL that mirror docs/Database schema/*.sql — columns, FKs, CHECK constraints, indexes, enums, RLS policies and role grants. Use whenever a task creates or alters a table."
argument-hint: "Table(s) or schema file section to migrate"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

## Source of truth

1. Find the table in `docs/Database schema/0*_schema_*.sql` (PostgreSQL files; ignore `00_schema_mysql.sql`). Also read the matching section of `docs/Dahab_Backend_Database_Design.md`.
2. The migration MUST reproduce that DDL exactly: names, types, nullability, defaults, FKs, `CHECK`s, unique/partial indexes, RLS policies, grants. If the docs look wrong, fix `docs/` first (Constitution III).

## Generate

```bash
php artisan make:migration create_<table>_table
```

One table (plus its indexes/policies) per migration. Order files so FK targets exist first.

## Rules

- Schema builder for plain columns; `DB::statement()` for anything the builder can't express faithfully:
  `CHECK` constraints, partial indexes, PostgreSQL enums/domains, `GENERATED` columns, RLS, `GRANT`s, triggers.
- Money/gold: `decimal(precision, scale)` exactly as in the SQL — never `float`/`double`.
- IDs: match docs (`bigIdentity`/`uuid`). Timestamps: `timestampsTz()` / `timestampTz()` when docs use `timestamptz`.
- FKs: explicit `->constrained('<table>')->restrictOnDelete()` unless docs say `CASCADE`. Ledger/audit rows are never cascaded.
- Actor attribution (Constitution I): keep the `CHECK (customer_id IS NOT NULL) <> (staff_id IS NOT NULL)`-style constraints from the docs verbatim.
- RLS (Constitution II):
  ```php
  DB::statement('ALTER TABLE wallet ENABLE ROW LEVEL SECURITY');
  DB::statement('ALTER TABLE wallet FORCE ROW LEVEL SECURITY');
  DB::statement("CREATE POLICY wallet_owner ON wallet USING (customer_id = current_setting('app.customer_id', true)::bigint)");
  ```
- Guard PostgreSQL-only statements so SQLite framework tests still boot:
  ```php
  if (DB::getDriverName() === 'pgsql') { /* CHECK / RLS / GRANT */ }
  ```
  Tests that exercise these guarantees must run against PostgreSQL (see `laravel-pest-testing`).
- `down()` MUST fully reverse `up()` (drop policies, enums, indexes, table). If reversal is impossible, throw with a clear message and call it out in the PR.
- Never edit a migration that has run in a shared environment — add a new one.

## Verify

```bash
php artisan migrate:fresh
php artisan migrate:rollback --step=1 && php artisan migrate
```

Then diff the resulting table with the docs (`\d+ <table>` in psql) and fix any drift.
