# Implementation Plan: Customer Data Isolation Enforced by the Database

**Branch**: `claude/customer-rls` (spec dir `003-customer-rls-isolation`) | **Date**: 2026-09-26 | **Spec**: [spec.md](./spec.md)

## Summary

Switch on PostgreSQL row-level security, forced even for the owning application account, on every customer-owned table, plus customer-context rules for `audit_log` and `document_view_log`.

The actor is bound per unit of work (request, job, console command) through a stack that always restores. Cross-customer work runs under named elevations:
- `staff`: automatic for staff requests.
- `bootstrap`: auth routes and token-owner loading.
- `system`: queued jobs; audited.
- `maintenance`: migrations and seeders; audited.

A build test guarantees every future customer table is covered. There is no API change.

## Technical Context

**Language/Version**: PHP 8.3+ (8.4 local), Laravel 12
**Primary Dependencies**: Laravel Sanctum (custom token model), queue events, console events
**Storage**: PostgreSQL 16: RLS policies, `FORCE ROW LEVEL SECURITY`, SQL helper functions, session settings
**Testing**: Pest 3 against PostgreSQL. Isolation tests use raw SQL under a bound customer; plus the architecture test.
**Target Platform**: Linux containers; PHP-FPM + Horizon workers
**Project Type**: Web service (backend only; impact analysis: Dashboard NO, Customer App NO, API NO)
**Performance Goals**: No customer flow more than 10% slower (SC-006). Policies are simple equality checks on indexed owner columns.
**Constraints**: No per-staff-role DB roles (Constitution v2 II). No transaction-mode pooler (research R2). Audit rows written before a thrown refusal must survive.
**Scale/Scope**: 7 tables now; pattern for ~8 more later.

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Every elevation that is not already an audited event (system, maintenance) writes an audit row with the system actor. Customer audit writes keep working under RLS (R8). | ✅ |
| II. Customer isolation by the engine | This feature *is* Principle II: forced RLS, fail closed, no reliance on `WHERE` clauses. Staff authorization untouched. | ✅ |
| III. Docs are the source of truth | Part 1 §5.1 (binding model, bootstrap, pooler assumption), `05_schema_security.sql` §19, architecture doc updated in the same PR. | ✅ (tracked tasks) |
| IV. Foundation before modules | Foundation work; no business module. | ✅ |
| V. Test the boundary | Isolation tests hit the real engine; the full existing HTTP suite must stay green with RLS on (SC-004). | ✅ |
| Reversible migrations | `down()` drops policies, disables RLS, drops helper functions. | ✅ |

## Project Structure

```text
specs/003-customer-rls-isolation/  plan.md · research.md · data-model.md · quickstart.md · tasks.md · checklists/
app/
├── Support/DatabaseActor.php                    # stack-based binding + elevate()
├── Models/PersonalAccessToken.php               # findToken() loads tokenable under bootstrap elevation
├── Http/Middleware/SetDatabaseActor.php         # rewritten: push frame for the request, pop in finally
├── Http/Middleware/ElevateDatabaseScope.php     # alias db.elevate:{scope} (bootstrap routes)
├── Actions/Auth/Shared/RecordAuditLogAction.php # no RETURNING outside elevation
└── Providers/AppServiceProvider.php             # Sanctum model, queue + console listeners
database/migrations/2026_09_26_000040_enable_customer_row_level_security.php
routes/api.php                                   # db.elevate:bootstrap on unauthenticated customer auth routes
tests/TestCase.php                               # maintenance frame for fixtures
tests/Feature/Isolation/                         # CustomerRowIsolationTest, ActorBindingTest, ElevationTest, CustomerTableIsolationTest
docs/Technical Spec/dahab-spec-part1-auth.md §5.1, docs/Database schema/05_schema_security.sql §19, docs/platform/architecture.md
```

**Structure Decision**: Single Laravel project. One new support service, one new middleware, one migration.

## Complexity Tracking

None.
