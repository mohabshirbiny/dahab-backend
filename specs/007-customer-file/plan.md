# Implementation Plan: Customer File v1

**Branch**: `feature/customer-file` (backend + dashboard; the Customer App has no repository yet) | **Date**: 2026-09-28 | **Spec**: [spec.md](./spec.md)

## Summary

This feature builds the one-page Customer file for staff. It is made of:
- the existing audited details endpoint, extended with every document, the suspension details, the language and the joined date;
- suspend and reinstate, behind `customer.suspend`, audited, returning the customer to their pre-suspension state;
- two activity lists: History, drawn from the audit log under the viewer's audit visibility, and Sessions and devices.

It also:
- builds the platform's shared `Idempotency-Key` layer (a table, middleware and a prune command), used first by suspend and reinstate;
- replaces the five suspension reason codes with the design's seven;
- fixes identity review silently lifting a suspension;
- adds a suspended notice to the Customer App.

## Impact analysis

```
Backend:          YES — Actions, middleware, 3 new endpoints + 2 extended, tests, Postman
Database:         YES — customer +2 columns +2 CHECKs; new idempotency_key table; 1 audit_log index
API:              YES — non-breaking additions; reason enum values change (potentially breaking, see below)
Dashboard:        YES — Customer file page + search, Users rows link to it
Customer App:     YES — suspended notice; reason wording for the 7 codes
Auth:             NO  — sign-in and the trade gate are unchanged (verified by tests)
Permissions:      NO new codes — uses customer.view, customer.suspend, audit.view_all / view_own
API models/types: YES — Dashboard customer types; Flutter Customer model (suspendedReason)
```

**Classification**:
- The added fields, `q` and the new endpoints are non-breaking.
- The `suspended_reason` values change. That is potentially breaking, but no consumer maps the old values:
  - the Dashboard only shows the raw value today;
  - Flutter ignores it;
  - no endpoint could set it before.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12; Vue 3 + TS + Vuetify 4; Flutter (Dart ^3.11).
- **Primary Dependencies**: Spatie permission (existing codes), Sanctum tokens (sessions), the spec 006 audit presenter and query, TanStack Query.
- **Storage**: PostgreSQL 16, forced RLS. Migrations mirror the schema docs, which are updated first.
- **Testing**: Pest feature tests through HTTP:
  - the file, suspend and reinstate, conflicts, validation, permissions;
  - idempotency: replay, mismatch, in-flight, 5xx retry, actor and endpoint scoping, RLS;
  - the review-while-suspended case;
  - History visibility and the sessions shape;
  - the trade gate after suspension.

  Dashboard: type-check, lint, build. Flutter: analyze, test (the fake backend in `test/flows_test.dart` gains a suspended customer).
- **Performance Goals**: the file opens in under 2 s for a customer with 10k audit rows and 1k sessions (SC-005). History uses a keyset cursor and a partial index; sessions are one grouped query.
- **Constraints**:
  - named staff actor on every change (Constitution I);
  - row lock on the transitions;
  - the staff note is never exposed to the customer;
  - no token or fingerprint values in responses.
- **Scale/Scope**: 2 new POSTs, 2 new GETs, 2 extended GETs; 1 Dashboard page plus a search page; 1 Flutter widget.

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Suspend and reinstate are staff-only, with `suspended_by` + audit `actor_staff_id`; `suspended_needs_actor` holds | ✅ |
| II. Isolation / staff authz by data | Existing catalogue codes only; `idempotency_key` gets forced RLS; customer reads happen in the staff scope, as today | ✅ |
| III. Docs first | Schema SQL (02, 05, 00_full) is updated before the migrations. Part 1 §4.3 (reason list, pre-suspension state), Part 2 §543 (as-built routes), `api-contract.md` (idempotency convention), `docs/features/customer-file.md` | ✅ |
| IV. Foundation before modules | Not a business module. Idempotency is foundation work the ledger needs | ✅ |
| V. Test the boundary | Every state change is covered by HTTP feature tests asserting rows, audit entries and envelopes | ✅ |
| Reversible migrations | All three migrations have a `down()`. The reason remap is reversible where 1:1; `other` keeps `other`, noted in the migration | ✅ |

## Project Structure

### Documentation

```text
specs/007-customer-file/{spec,plan,research,data-model,quickstart}.md, contracts/dashboard-customer-file.md, checklists/requirements.md, tasks.md (next)
docs/features/customer-file.md
```

### Source Code

```text
backend
  docs/Database schema/{02_schema_identity,05_schema_security,00_schema_full}.sql
  database/migrations/2026_09_30_000010_customer_suspension_details.php      (columns, CHECKs, reason remap)
  database/migrations/2026_09_30_000020_create_idempotency_key.php            (table, RLS)
  database/migrations/2026_09_30_000030_audit_log_actor_customer_index.php
  app/Enums/SuspendedReason.php (7 + label) · app/Exceptions/DomainApiException.php (+5 codes)
  app/Models/{Customer (suspend/reinstate, fillable, casts), IdempotencyKey}.php
  app/Http/Middleware/EnforceIdempotency.php (alias `idempotent`) · bootstrap/app.php
  app/Console/Commands/PruneIdempotencyKeys.php · routes/console.php (hourly)
  app/Actions/Customers/{SuspendCustomerAction,ReinstateCustomerAction,ListCustomerActivityAction,ListCustomerSessionsAction}.php
  app/Actions/Dashboard/{ShowCustomerVerificationDetailsAction (eager-load reviewers, suspender), ListCustomersForVerificationAction (+q)}.php
  app/Actions/Identity/ReviewIdentityDocumentAction.php (suspended → update status_before_suspension)
  app/Http/Requests/Dashboard/Customers/{SuspendCustomerRequest,ReinstateCustomerRequest,CustomerActivityRequest}.php · ListCustomersRequest (+q)
  app/Http/Resources/Staff/{CustomerFileResource,CustomerSessionResource}.php (CustomerVerificationResource kept for the list)
  app/Http/Controllers/Api/V1/Dashboard/CustomerController.php (+suspend, reinstate, activity, sessions)
  routes/api.php · database/factories/CustomerFactory · database/seeders/LocalCustomerSeeder
  postman/Dahab-Backend.postman_collection.json (+4 requests, Idempotency-Key pre-request script)
  tests/Feature/Idempotency/IdempotencyMiddlewareTest.php
  tests/Feature/CustomerFile/{SuspensionSchemaTest,CustomerFileShowTest,SuspendCustomerTest,ReinstateCustomerTest,CustomerActivityTest,CustomerSessionsTest,ReviewWhileSuspendedTest}.php
  docs: Part 1 §4.3, Part 2 §543 + §10, platform/api-contract.md, features/customer-file.md, CLAUDE.md "Current state"
dashboard
  src/api/endpoints.ts · src/types/customer.ts · src/services/customer.service.ts (+ Idempotency-Key)
  src/composables/useCustomerFile.ts
  src/pages/customers/[id].vue · src/pages/customer/index.vue (search) · router (replace placeholder)
  src/components/customer-file/{CustomerProfileCard,IdentityDocumentsPanel,SuspendForm,ReinstateForm,CustomerHistoryPanel,CustomerSessionsPanel}.vue · src/composables/useIdempotencyKey.ts
  src/components/users/CustomersTable.vue (row → file) · docs, CLAUDE.md
flutter (no git)
  lib/models/customer.dart (+suspendedReason) · lib/features/…/suspended_notice.dart · lib/core/i18n (7 reasons en/ar)
  home shell shows the notice · test/flows_test.dart (suspended case)
```

**Structure Decision**: the existing layering on all three sides. A new `app/Actions/Customers` folder holds the file's Actions; the idempotency layer is platform infrastructure (middleware + model).

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) (R1–R10)
- [data-model.md](./data-model.md)
- [contracts/dashboard-customer-file.md](./contracts/dashboard-customer-file.md)
- [quickstart.md](./quickstart.md)

Post-design re-check: no violations.

## Follow-ups (not in this feature)

- Apply `idempotent` to the existing state-creating POSTs (identity-document submission, registration steps, role/staff changes, price entry).
- Automatic suspensions with the system actor (order features).

## Complexity Tracking

| Addition | Why needed | Simpler alternative rejected because |
|---|---|---|
| Shared idempotency layer | Part 2 requires it on every state-creating POST; the ledger is next | State-based-only idempotency was offered; the user chose the shared layer (2026-09-28) |
