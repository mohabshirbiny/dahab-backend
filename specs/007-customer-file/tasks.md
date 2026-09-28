# Tasks: Customer File v1

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/dashboard-customer-file.md](./contracts/dashboard-customer-file.md), [quickstart.md](./quickstart.md)

**Tests**: required (Constitution V; test-first within each story: write the test → see it fail for the expected reason → implement → run it).

**Paths**:
- Backend paths are relative to the repo root.
- Dashboard paths are under `../dahab-dashboard/`.
- Flutter paths are under `../dahab-flutter/`, which has no git.

**Git**: work on branch `feature/customer-file` in backend and dashboard, created only when the user asks.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup

- [x] T001 Write the cross-project feature spec `docs/features/customer-file.md` from `docs/features/_TEMPLATE.md`: impact analysis from plan.md, links to `specs/007-customer-file/`.
- [x] T002 Update the schema docs first (Constitution III). In `docs/Database schema/02_schema_identity.sql` and `00_schema_full.sql`, add to `customer`:
  - `suspended_note TEXT`;
  - `status_before_suspension TEXT CHECK (status_before_suspension IN ('pending_verification','active','rejected'))`;
  - CHECK `customer_suspension_state`: `(status = 'suspended') = (status_before_suspension IS NOT NULL)`;
  - CHECK on `suspended_reason IN ('piece_misrepresented','off_platform_dealing','repeated_disputes','reported_by_users','identity_unconfirmed','customer_request','other')`.

  In `05_schema_security.sql` and `00_schema_full.sql`, add:
  - the `idempotency_key` table exactly as in data-model.md, with the RLS note;
  - `idx_audit_actor_customer ON audit_log (actor_customer_id, created_at DESC) WHERE actor_customer_id IS NOT NULL`.

---

## Phase 2: Foundational (blocks every story)

### Idempotency layer (research R2)

- [x] T003 Write `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`, against a test-only route registered in the test (a staff POST that counts executions). It covers:
  - missing or non-UUID header → `400 idempotency_key_required`;
  - first call runs;
  - a replay with the same key and body → the same status and body, header `Idempotent-Replayed: true`, and the handler ran once;
  - same key, different body → `422 idempotency_key_mismatch`;
  - an `in_flight` row younger than 60 s → `409 idempotency_in_progress`; older → taken over and runs;
  - a 5xx → row `failed`, and a retry runs again;
  - the same key from another staff member or on another route → independent;
  - a customer-scope request cannot see another customer's key row (RLS).

  Run it and confirm it fails.
- [x] T004 Migration `database/migrations/2026_09_30_000020_create_idempotency_key.php`. It creates `idempotency_key` per data-model.md:
  - columns `idem_key UUID NOT NULL`;
  - `actor_kind` CHECK `IN ('customer','staff')`;
  - `actor_customer_id`, `actor_staff_id`, `endpoint TEXT NOT NULL`, `request_hash CHAR(64) NOT NULL`;
  - `state` default `'in_flight'`, CHECK `IN ('in_flight','completed','failed')`;
  - `response_status SMALLINT`, `response_body JSONB`, `created_at`, `completed_at`, `expires_at TIMESTAMPTZ NOT NULL`;
  - CHECK `idempotency_has_actor`;
  - a unique expression index on `(actor_kind, COALESCE(actor_customer_id, actor_staff_id), endpoint, idem_key)`, and an index on `expires_at`;
  - forced RLS with policy `dahab_rls_elevated() OR actor_customer_id = dahab_current_customer_id()`, following `2026_09_26_000040_enable_customer_row_level_security.php`.

  Provide a working `down()`.
- [x] T005 [P] Model `app/Models/IdempotencyKey.php` (casts `response_body` array, datetimes).
- [x] T006 [P] Add factories to `app/Exceptions/DomainApiException.php` (business/protocol refusals live there; `AuthErrorCode` is auth-only): `idempotencyKeyRequired()` → `idempotency_key_required` 400, `idempotencyKeyMismatch()` → `idempotency_key_mismatch` 422, `idempotencyInProgress()` → `idempotency_in_progress` 409.
- [x] T007 Middleware `app/Http/Middleware/EnforceIdempotency.php`, registered as alias `idempotent` in `bootstrap/app.php`. It follows research R2 exactly:
  - scope = actor (staff or customer from the guard) + the route name;
  - hash = SHA-256 of the canonical (key-sorted) JSON body plus the route parameters;
  - insert `in_flight` in its own transaction; take over an `in_flight` row older than 60 s;
  - store a response below 500 as `completed`, with `expires_at = now + 24h`; on a 5xx set `failed`;
  - replay with `Idempotent-Replayed: true`.

  **Ordering** (analysis U2): it touches an RLS table, so it runs after `auth:*` and after `SetDatabaseActor` has bound the scope; on routes it is listed last (after `staff.permission`). T003 includes a customer-scope write under RLS.

  Run T003 until it passes.
- [x] T008 [P] Command `app/Console/Commands/PruneIdempotencyKeys.php` (`idempotency:prune`, deletes rows past `expires_at`, runs in the maintenance DB scope), scheduled hourly in `routes/console.php`. Add a test case to T003's file.

### Suspension data (research R3, R5)

- [x] T009 Write `tests/Feature/CustomerFile/SuspensionSchemaTest.php`. It checks:
  - the DB refuses `status='suspended'` without `status_before_suspension`, and a non-suspended row with it;
  - the DB refuses an unknown `suspended_reason`;
  - `Customer::transitionTo(CustomerStatus::SUSPENDED)` throws.

  Confirm it fails.
- [x] T010 Migration `database/migrations/2026_09_30_000010_customer_suspension_details.php`:
  - add `suspended_note TEXT NULL` and `status_before_suspension TEXT NULL` with its CHECK;
  - backfill `status_before_suspension='active'` for existing suspended rows;
  - remap old reason codes (`fraud_suspected→piece_misrepresented`, `policy_violation→off_platform_dealing`, `kyc_failed→identity_unconfirmed`, `staff_request→other`);
  - add CHECKs `customer_suspension_state` and `customer_suspended_reason_check`.

  `down()` drops them and maps back the 1:1 codes, noting that `other` keeps `other`.
- [x] T011 [P] Replace the cases in `app/Enums/SuspendedReason.php` with the seven codes and add `label()` (the staff labels from research R5).
- [x] T012 In `app/Models/Customer.php`:
  - add `suspended_note` and `status_before_suspension` to fillable and casts (the latter to `CustomerStatus`);
  - add `suspend(SuspendedReason $reason, string $note, Staff $by)`, which stores the previous status and sets `is_verified = (previous === ACTIVE)`, `is_suspended = true`;
  - add `reinstate()`, which restores the previous state and clears the five suspension fields;
  - make `transitionTo()` throw for `SUSPENDED`.

  Update `database/factories/CustomerFactory.php` `suspended()` (default reason `off_platform_dealing`, sets `status_before_suspension`), `database/seeders/LocalCustomerSeeder.php`, and `tests/Feature/Customer/CustomerGateTest.php:80` (`'policy_violation'` → `'off_platform_dealing'`). Run T009 and the existing Customer/Identity suites.
- [x] T013 [P] Migration `database/migrations/2026_09_30_000030_audit_log_actor_customer_index.php` (the partial index from T002; `down()` drops it).
- [x] T014 Checkpoint: run `composer test`. Then `php artisan migrate:fresh --seed`, then `migrate:rollback --step=3` then `migrate`, as the `dahab` DB user.

---

## Phase 3: User Story 1 — Open one customer's file (P1) 🎯 MVP

**Goal**: one audited page with profile, state, suspension details and every document.
**Independent test**: as Verification, open a verified customer with two documents → full file, one audit entry; IGI → 403.

- [x] T015 [US1] Write `tests/Feature/CustomerFile/CustomerFileShowTest.php`, calling `GET /api/v1/dashboard/customers/{id}`. It checks:
  - `preferred_lang`, `joined_at`;
  - `documents[]` newest first with `reviewed_by {id,full_name}` or null;
  - `latest_document == documents[0]`;
  - `suspension: null` for an active customer;
  - for a suspended one, `suspension {reason, note, status_before, suspended_at, suspended_by{id,full_name}}` (as built: the existing `DashboardStaffRef` shape);
  - exactly one `auth.customer.verification_details_viewed` audit row per call;
  - 403 without `customer.view`, 404 for an unknown uuid.

  Also add a `q` test: exact `display_ref` or phone across statuses, with `status` ignored.
- [x] T016 [US1] Resource `app/Http/Resources/Staff/CustomerFileResource.php`. It extends the `CustomerVerificationResource` fields with the contract §1 additions and has an `#[OA\Schema(schema: 'StaffCustomerFile')]`. The note appears here only, never in customer resources.
- [x] T017 [US1] In `app/Actions/Dashboard/ShowCustomerVerificationDetailsAction.php`, eager-load the documents' reviewer (staff id + name) and `suspended_by` staff; no extra queries per document. Switch `CustomerController::show` to `CustomerFileResource` and update its `#[OA\Get]` response.
- [x] T018 [US1] Search:
  - `app/Http/Requests/Dashboard/ListCustomersRequest.php` gains `q` (`sometimes|string|min:1|max:32`);
  - `ListCustomersForVerificationAction` matches `display_ref = q` OR `phone = normalised(q)` (reuse the registration phone normaliser), ignoring status when `q` is set;
  - `#[OA]` param on `index`.

  Run T015.
- [x] T019 [P] [US1] Postman: update "Show customer" (new fields in the saved example) and "List customers" (`q`) in `postman/Dahab-Backend.postman_collection.json`.
- [x] T020 [US1] Dashboard types and service:
  - `src/types/customer.ts`: `CustomerFile`, `CustomerSuspension`, `SuspendedReason` (7 codes) + `SUSPENDED_REASON_LABELS`, `reviewedBy`;
  - mapping in `src/services/customer.service.ts` (snake → camel);
  - `q` support in the list service.
- [x] T021 [US1] Dashboard file page:
  - `src/composables/useCustomerFile.ts` (TanStack query);
  - `src/pages/customers/[id].vue` (route `dashboard/customers/:id`, `permissions: [PERMISSIONS.customerView]`);
  - `src/components/customer-file/CustomerProfileCard.vue` (profile + state + suspension block);
  - `src/components/customer-file/IdentityDocumentsPanel.vue` (reuse `components/identity/DocumentPreview.vue`).

  Follow the design's Customer file layout and omit the unbuilt panels (FR-013), including `customer_type`. Include loading, error, empty and 404 states.

  One opening = one audit entry (FR-003, analysis U1): the file query sets `refetchOnWindowFocus: false`, `refetchOnReconnect: false` and a long `staleTime`, and is refetched only when the page is opened.
- [x] T022 [US1] Dashboard search and navigation:
  - replace the `customer` placeholder route with `src/pages/customer/index.vue` (search by reference or phone → results → file);
  - the Users page reaches the file through an "Open file" button in `src/components/users/CustomerReviewPanel.vue` (as built: a row click still opens the review panel, which the identity review needs);
  - nav item gated on `customer.view`.

---

## Phase 4: User Story 2 — Suspend and reinstate (P1)

**Goal**: founders suspend with a reason and note and reinstate; the change is audited, idempotent and restores the prior state.
**Independent test**: the COO suspends → trade-gated 403 `account_suspended`, sign-in OK; reinstate → the trade gate opens.

- [x] T023 [P] [US2] Add `DomainApiException::customerAlreadySuspended()` → `customer_already_suspended` 409 and `customerNotSuspended()` → `customer_not_suspended` 409 in `app/Exceptions/DomainApiException.php`.
- [x] T024 [US2] Write `tests/Feature/CustomerFile/SuspendCustomerTest.php`:
  - 200 with the file shape, `status: suspended`, `suspension.status_before`;
  - the row has `suspended_by`/`suspended_at`/`suspended_note`;
  - one `auth.customer.suspended` audit row (before/after status, `reason` = note, reason code in the payload);
  - works from `pending_verification`, `active` and `rejected`;
  - 422 for missing reason, an unknown reason, a missing note, or a note over 1000 characters;
  - 409 `customer_already_suspended`;
  - 403 without `customer.suspend` (e.g. Verification);
  - 400 without `Idempotency-Key`; a replay → same body, still one audit row;
  - afterwards the customer signs in (200), reads `/customer/auth/me` (status + reason, **no note**), and a trade-gated route gives 403 `account_suspended`.
- [x] T025 [US2] Write `tests/Feature/CustomerFile/ReinstateCustomerTest.php`:
  - 200, the state restored to exactly `status_before` (for each of the three), with `is_verified` matching;
  - suspension fields cleared;
  - one `auth.customer.unsuspended` audit row;
  - 422 for a missing note, 409 `customer_not_suspended`, 403 without permission, idempotent replay;
  - the trade gate is open again for a customer restored to `active`.
- [x] T026 [US2] Write `tests/Feature/CustomerFile/ReviewWhileSuspendedTest.php`:
  - approving a pending document of a suspended customer → still `suspended`, `status_before_suspension = active`;
  - rejecting it → `rejected`;
  - `needs_resubmission` → unchanged;
  - reinstate → the updated state.
- [x] T027 [US2] `app/Actions/Customers/SuspendCustomerAction.php` and `ReinstateCustomerAction.php`:
  - `DB::transaction` + `lockForUpdate` on the customer;
  - conflict checks throw the `DomainApiException` 409s from T023;
  - call `Customer::suspend()` / `reinstate()`;
  - audit through `RecordAuditLogAction` (`CUSTOMER_SUSPENDED` / `CUSTOMER_UNSUSPENDED`, entity `customer`, before/after `{status, suspended_reason}`, `reason` = note, `actorStaffId`).
- [x] T028 [US2] Requests:
  - `app/Http/Requests/Dashboard/Customers/SuspendCustomerRequest.php`: `reason` required, `Rule::enum(SuspendedReason::class)`; `note` required, string, trimmed, `min:1`, `max:1000`;
  - `ReinstateCustomerRequest.php`: `note` with the same rule.
- [x] T029 [US2] Controller and routes:
  - `CustomerController::suspend` / `reinstate`, returning `CustomerFileResource`, with `#[OA\Post]` including the `Idempotency-Key` header parameter and every error code;
  - routes in `routes/api.php`: `POST /{customer}/suspend` and `/{customer}/reinstate`, with `whereUuid`, middleware `staff.permission:customer.suspend` and `idempotent`, named `suspend` and `reinstate`.

  Run T024 and T025.
- [x] T030 [US2] `app/Actions/Identity/ReviewIdentityDocumentAction.php` `activate()` / `reject()`: when the customer is suspended, set `status_before_suspension` (and `is_verified` per R3) instead of transitioning. Run T026 and the existing `tests/Feature/Identity` suite.
- [x] T031 [P] [US2] Postman: "Suspend customer" and "Reinstate customer" requests, with a pre-request script setting `Idempotency-Key` to a `{{$guid}}` per send, bodies matching T028, and saved examples.
- [x] T032 [US2] Dashboard:
  - `src/api/endpoints.ts` (`customerSuspend`, `customerReinstate`);
  - service methods sending `Idempotency-Key` (`crypto.randomUUID()` generated once per dialog submission and reused on a retry of it);
  - `src/components/customer-file/SuspendForm.vue` (as built: an inline box that expands in the profile card, as in the design, instead of a dialog; 7 reasons with design labels, a required note of at most 1000 characters);
  - `ReinstateForm.vue` (required note; says which state they return to);
  - the key comes from `src/composables/useIdempotencyKey.ts` (the same key for a retry of the same input, a new one when the input changes);
  - both shown only with `PERMISSIONS.customerSuspend`;
  - on success, replace the file query data from the response;
  - map 409 codes to messages in `src/services/errors.ts`.

---

## Phase 5: User Story 3 — History and Sessions (P2)

**Goal**: staff see the audit history and the devices and sessions of one customer.
**Independent test**: a customer who registered, submitted, was reviewed and signed in on two devices → History lists them with labels (no token rotations); Sessions shows 2 devices and 2 sessions.

- [x] T033 [US3] Write `tests/Feature/CustomerFile/CustomerActivityTest.php`, calling `GET /dashboard/customers/{id}/activity`. It checks:
  - includes entries with the customer as actor, as the `customer` entity, or on their `identity_document` entities;
  - excludes `auth.token.rotated`;
  - newest first, with a cursor that has no gaps or duplicates;
  - labels and categories as in spec 006;
  - a `view_all` holder (the CEO) sees all; a `view_own` holder (Verification) sees only their own actions;
  - 403 without an audit permission, and 403 without `customer.view`;
  - volume (SC-005, analysis G1): with 10,000 audit rows and 1,000 token families seeded for one customer, the file, activity and sessions calls each use a bounded query count, and `idx_audit_actor_customer` exists (as built: asserting the chosen plan was dropped, because the planner rightly picks a time scan when every test row is one customer's). There is no wall-clock assertion.
- [x] T034 [US3] Write `tests/Feature/CustomerFile/CustomerSessionsTest.php`, calling `GET /dashboard/customers/{id}/sessions`. It checks:
  - devices with a 12-character `device_ref`, first/last seen, and no full fingerprint;
  - open sessions grouped by token family with `started_at`, `last_active_at`, `expires_at`; after sign-out the session is no longer listed; a family whose tokens have all expired is not listed;
  - no token or ability fields anywhere in the JSON;
  - pagination `meta`;
  - an empty state for a customer who never signed in;
  - 403 without `customer.view`.
- [x] T035 [US3] `app/Actions/Customers/ListCustomerActivityAction.php`:
  - builds the query from `App\Support\Audit\AuditQuery::visibleTo()` plus the customer predicate (research R7);
  - reuses `AuditCursor` and `AuditEntryPresenter`;
  - `limit(per_page + 1)` keyset, as in `ListAuditEntriesAction`.

  Add `app/Http/Requests/Dashboard/Customers/CustomerActivityRequest.php` (`cursor`, `per_page` 1–50, default 20).
- [x] T036 [US3] `app/Actions/Customers/ListCustomerSessionsAction.php`:
  - devices from `customer_trusted_device`, ordered by `last_seen_at desc`;
  - sessions from one grouped query over `personal_access_tokens` where `tokenable` is the customer and `family_id IS NOT NULL`, `GROUP BY family_id`, paginated;
  - only open families: `HAVING` any token with `expires_at IS NULL OR expires_at > now()` (sign-out deletes rows, so there is no `ended_at` — see research R7 as built).

  Add `app/Http/Resources/Staff/CustomerSessionResource.php` (+ `#[OA\Schema]`).
- [x] T037 [US3] Controller `activity` / `sessions` and routes:
  - `GET /{customer}/activity` with `staff.permission:customer.view`; the any-of-audit check happens in the Action or a gate returning 403 `permission_denied`;
  - `GET /{customer}/sessions`;
  - `#[OA\Get]` on both.

  Run T033 and T034.
- [x] T038 [P] [US3] Postman: "Customer activity" and "Customer sessions".
- [x] T039 [US3] Dashboard:
  - endpoints and types (`CustomerActivityEntry` reusing `src/types/audit.ts`, `CustomerDevice`, `CustomerSession`);
  - service and `useCustomerFile` infinite queries;
  - `src/components/customer-file/CustomerHistoryPanel.vue` (shown only with `auditViewAll` or `auditViewOwn`, "Load more" via cursor);
  - `CustomerSessionsPanel.vue` (a devices list plus a paged sessions table, with empty states).

---

## Phase 6: User Story 4 — Customer App suspended notice (P3)

**Goal**: a suspended customer sees that they are suspended and why.
**Independent test**: sign in as a suspended customer in the Flutter fake backend → the notice with the plain reason; after reinstate and a session restore → no notice.

- [x] T040 [US4] Test in `../dahab-flutter/test/flows_test.dart`: the fake backend returns `status: suspended`, `suspended_reason: off_platform_dealing` from `me` → the home shows the suspended notice with the reason's wording; an unknown code → the generic line; an active customer → no notice; a request refused with 403 `account_suspended` → the suspended message (FR-014, analysis G2).
- [x] T041 [US4] `../dahab-flutter/lib/models/customer.dart`: add `suspendedReason` (from `suspended_reason`).
- [x] T042 [US4] Suspended notice widget:
  - add `../dahab-flutter/lib/features/shared/suspended_notice.dart`, reading the session's customer;
  - customer-facing wording for the 7 codes plus a generic fallback in `lib/core/i18n/i18n.dart` / `ar_extra.dart` (en + ar); never the staff note;
  - show it on `lib/features/home/home_screen.dart` (and `lib/widgets/app_shell.dart` if the shell hosts banners).

  Run `flutter analyze` and `flutter test`.

---

## Phase 7: Polish & cross-cutting

- [x] T043 [P] Docs:
  - `docs/Technical Spec/dahab-spec-part1-auth.md` §4.3/§2.2: the seven reasons, the pre-suspension state, identity review while suspended;
  - `dahab-spec-part2-api.md` §543: the as-built `/api/v1/dashboard/customers/{id}/suspend|reinstate`, activity and sessions, the idempotency as-built note at line 19;
  - `docs/platform/api-contract.md`: the idempotency convention (contract §6) and the new error codes;
  - `docs/features/customer-file.md` completed.
- [x] T044 [P] `CLAUDE.md` "Current state": Backend (spec 007 routes + the idempotency layer), Dashboard (Customer file live), Flutter (suspended notice).
- [x] T045 Quality gates (`laravel-quality-gates`):
  - `composer test`, `./vendor/bin/pint --test`, `composer swagger:generate` (no warnings for new schemas);
  - `php artisan route:list --path=dashboard/customers`;
  - migrations fresh plus rollback of 3;
  - N+1 check on the file endpoint (query count test in T015).

  Dashboard: `npm run type-check`, `npm run lint`, `npm run build`. Flutter: `flutter analyze`, `flutter test`, `flutter build web --release`.
- [x] T046 Run the [quickstart.md](./quickstart.md) scenarios 1–7 against a local server. Check that `git status` and `git diff` show only intended files in both repos, and grep the diff for secrets. Then run `/speckit-analyze` again.

---

## Dependencies & execution order

- Setup (T001–T002) → Foundational (T003–T014) → the user stories.
- **US1** (T015–T022) needs Foundation. **US2** (T023–T032) needs Foundation and the `CustomerFileResource` from US1 (T016). **US3** (T033–T039) needs Foundation only; it can run in parallel with US2. **US4** (T040–T042) needs only T011 (the reason codes).
- Within each story: tests → Actions/Requests → controller/routes → Postman → Dashboard.
- Polish comes after all stories.

## Parallel opportunities

- Foundation: T005, T006, T008 after T004; T011 and T013 alongside T010.
- US1: T019 alongside T020.
- US2: T023 at the start; T031 alongside T032.
- US3 alongside US2 (different Actions and tests; both touch `CustomerController` and `routes/api.php`, so sequence those two edits).
- US4 (Flutter) is fully independent of US1–US3 once T011 fixes the codes.

## Implementation strategy (batches, per CLAUDE.md)

1. **Batch A: Foundation** (T001–T014). Run the tests, check the diff, report.
2. **Batch B: US1** (T015–T022). This is the MVP: the file opens in the Dashboard.
3. **Batch C: US2** (T023–T032).
4. **Batch D: US3** (T033–T039).
5. **Batch E: US4** (T040–T042).
6. **Batch F: Polish** (T043–T046).

Stop after each batch to report.
