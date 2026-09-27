# Tasks: Audit Log Viewer

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)
**Tests**: required (Constitution V); written first within each story.
**Standing rules**:
- Read-only feature: no write to `audit_log` except one `audit.log.exported` entry per export (FR-011), and no change to what features record.
- Visibility is enforced in the Action (never trusted from the client).
- Customers are shown by `display_ref` only.

## Phase 1: Setup — docs first

- [X] T001 Schema docs: in `docs/Database schema/05_schema_security.sql` (and the `00_schema_full.sql` mirror), add `CREATE INDEX idx_audit_created_id ON audit_log (created_at DESC, audit_id DESC)` with a note (spec 006, listing and keyset pagination).

## Phase 2: Foundational

- [X] T002 Migration `2026_09_29_000010_audit_log_listing_index.php`: create the index; `down()` drops it.
- [X] T003 [P] Catalogue:
  - `app/Enums/AuditCategory.php` (8 cases, `label()`, `inEverything()`, where only `sessions` is false);
  - `AuditEvent::label()` and `AuditEvent::category()`, exhaustive `match` with no default;
  - `AuditEvent::codesIn(AuditCategory)`;
  - a new event `AUDIT_LOG_EXPORTED = 'audit.log.exported'` (label "Audit log exported", category `system`);
  - `StaffPermission` + `audit.view_all` (seed: ceo only) and `audit.view_own` (coo, finance, operations, verification), in group "Audit".
- [X] T004 Update the permission-list expectations the 2 new codes change: `StaffDashboardAccessTest`, `StaffAuthorizationMigrationTest`, `StaffListTest`, `StaffRoleAssignmentTest`. This lands with T003.
- [X] T005 [P] `tests/Feature/Audit/AuditCatalogueTest.php`: every `AuditEvent` has a non-empty label and a category; every category has a label; "Everything" = all but `sessions`.

## Phase 3: US1 + US2 — List, filter, visibility (P1)

- [X] T006 [P] [US1] `tests/Feature/Audit/AuditLogListTest.php`:
  - the CEO sees entries newest first, with the actor shape for staff, customer (`display_ref`, no name) and system, plus the label, category, subject, before/after summaries, reason and IP;
  - filters: period (default 7 days), category, `Everything` excluding sessions, actor (UUID and `system`), action, entity;
  - `meta.total`;
  - bad inputs → 422;
  - the cursor returns every row exactly once across pages with an insert between pages;
  - the detail read has the full before/after and fingerprint;
  - unknown legacy action codes are shown with the raw code under `system`.
- [X] T007 [P] [US2] `tests/Feature/Audit/AuditLogVisibilityTest.php`:
  - Finance (`view_own`) sees only their own entries, count included;
  - `actor=<other>` → empty;
  - another person's entry by id → 404;
  - customer-actor rows hidden;
  - IGI → 403 `permission_denied`;
  - a role given `view_all` from the Dashboard sees everything;
  - the any-of route guard: a holder of only one of the two codes passes, and a holder of neither gets 403 with the denial audited.
- [X] T008 [US1] `app/Support/Audit/AuditCursor.php` (opaque base64 of `(created_at, audit_id)`, validated) and `AuditQuery.php` (applies filters, category → `action IN`, `system` → unknown codes, keyset, and the own-actions scope).
- [X] T009 [US1] `app/Support/Audit/AuditEntryPresenter.php`:
  - actor, subject, `before_summary` and `after_summary` per event family (pricing setting/adjustment/manual price, karat, branch/closure, role/permissions, staff roles/branch, identity, sessions), with a compact-JSON fallback of 80 characters;
  - batch lookups per page for staff names, customer `display_ref`, branch and role names.
- [X] T010 [US1] `ListAuditEntriesAction`, `ShowAuditEntryAction`, `AuditFilterRequest`, `AuditEntryResource`, `AuditLogController::index|show|categories` with `#[OA]` (tag "Dashboard Audit"). Routes gated with `staff.permission:audit.view_all|audit.view_own`. `EnforceStaffPermission` is extended to accept `|`-separated codes (any of them); single codes behave as before. Postman folder **Dashboard → Audit Log**.

## Phase 4: Export (FR-009)

- [X] T011 [P] `tests/Feature/Audit/AuditLogExportTest.php`:
  - CSV headers and filename;
  - UTF-8 BOM, and Arabic intact;
  - the same rows as the list for the same filters (all pages, not one);
  - the own-actions rule applies;
  - CSV injection is escaped;
  - the cap sets `X-Export-Truncated` (cap lowered through config in the test);
  - each export writes exactly one `audit.log.exported` entry with the filters, row count and `truncated`, attributed to the caller.
- [X] T012 `ExportAuditEntriesAction` (streamed, lazy keyset, cap from `config('dahab-audit.export_max_rows', 50000)`) and `AuditLogController::export` with `#[OA]`, route and Postman request. After the stream, record `AUDIT_LOG_EXPORTED` (filters, rows, truncated).

## Phase 5: Dashboard (after T010/T012 fix the contract)

- [X] T013 Foundation:
  - `src/types/api.ts` (+ `ApiAuditEntry`, `ApiAuditCategory`) and `src/types/audit.ts`;
  - `src/api/endpoints.ts`;
  - `src/services/audit.service.ts` (list with cursor, detail, categories, export as a Blob download with the filename from the header);
  - `src/composables/useAuditLog.ts` (infinite query);
  - `PERMISSIONS` +2;
  - nav: an `anyPermission` option in `types/nav.ts` + `AppSidebar.vue`, with the Audit log item unhidden for `audit.view_all | audit.view_own`;
  - router: replace the `audit` placeholder, and let the route guard accept any of the codes (`meta.anyPermissions`).
- [X] T014 `src/pages/audit/index.vue` + `components/audit/AuditTable.vue` + `AuditEntryModal.vue`, per the design:
  - the lead;
  - `FilterChips` for Everything + the 8 categories;
  - period select (7 days / 30 days / custom from–to);
  - the "Log" panel with the count and the period;
  - "Export to Excel";
  - table columns When / Who / What (+ subject) / Before / After / Origin;
  - Load more;
  - a detail modal (pretty before/after JSON, reason, IP, fingerprint, outcome);
  - loading, empty and error states;
  - a note that own-actions viewers see only their entries.
- [X] T015 Dashboard gates: type-check, lint (changed files), build; `docs/06` + `CLAUDE.md` status.

## Phase 6: Polish

- [X] T016 Docs:
  - Part 2 (as-built audit routes, near §10 "Accounts & access");
  - `docs/features/audit-log.md` (Customer App not affected; the device gap noted);
  - the CLAUDE.md "Current state" section;
  - `postman/README.md`.
- [X] T017 Quality gates:
  - Pint;
  - the full Pest suite;
  - migrate fresh + rollback as `dahab`;
  - swagger + the `OpenApiGenerationTest` lists + the `PrincipalIsolationTest` count (+4);
  - a browser check as CEO (all), Finance (own) and IGI (hidden) on the main DB, timing how long it takes to find a price change (SC-001: under 1 minute);
  - a benchmark: seed 1M rows into the scratch database (`dahab_wt002`), then time the first page and a category-filtered page and confirm the index is used (SC-004: under 2 s). Record the result in the feature doc.

## Dependencies

```
T001 → T002 → T003 (+T004 same change) → T005
                     │
                     ├── US1/US2: T006, T007 (tests) → T008 → T009 → T010
                     └── Export: T011 (test) → T012 (needs T008/T009)
                                                  ▼
                     Dashboard T013 → T014 → T015 (needs the T010 + T012 contract)
                                                  ▼
                     Polish T016, T017
```

## Parallel opportunities

- T003 and T005 alongside T002.
- Tests T006, T007 and T011 in parallel once T003 lands.
- T009 (presenter) can be developed alongside T008 (query).

## Implementation strategy

1. MVP = Phases 1–3 (the CEO and own-actions viewers can read the log through the API).
2. Then export.
3. Then the Dashboard.
4. Then polish.

**Counts**: 17 tasks.

| Phase | Tasks |
|---|---|
| Setup | 1 |
| Foundational | 4 |
| US1/US2 | 5 |
| Export | 2 |
| Dashboard | 3 |
| Polish | 2 |
