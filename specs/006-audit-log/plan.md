# Implementation Plan: Audit Log Viewer

**Branch**: `feature/audit-log` (backend + dashboard) | **Date**: 2026-09-27 | **Spec**: [spec.md](./spec.md)

## Summary

This feature adds the read side of the existing append-only `audit_log`:
- a filterable, keyset-paginated list, one entry's details, and a CSV export, all under two dynamic permissions (everything, or own actions);
- a Backend catalogue giving every `AuditEvent` a plain label and a category, plus a presenter for subjects and before/after summaries;
- the Dashboard "Audit log" page per the design.

There are no new writes and no change to what is audited. The Customer App is not affected.

## Technical Context

- **Language/Version**: PHP 8.3+ / Laravel 12 (backend); Vue 3 + TypeScript + Vuetify (dashboard).
- **Primary Dependencies**: Spatie permission (2 catalogue codes); Laravel streamed responses (CSV); TanStack Query (dashboard).
- **Storage**: PostgreSQL 16, the existing `audit_log` (forced RLS; the staff scope is elevated). Adds one index, `(created_at DESC, audit_id DESC)`.
- **Testing**: Pest feature tests covering list, filters, visibility, cursor stability, detail, export, and a catalogue completeness test. Dashboard: type-check, lint, build.
- **Target Platform**: Linux containers / Laragon.
- **Project Type**: Web service + staff SPA.
- **Performance Goals**:
  - a page (50 rows) in under 2 s with 1M rows (SC-004), via the index + keyset;
  - names are loaded in one batch per page;
  - the export streams at flat memory.
- **Constraints**:
  - read-only;
  - the own-actions filter is applied in the Action, never trusted from the client;
  - customers are shown by `display_ref` only;
  - CSV-injection safe.
- **Scale/Scope**: about 50 event kinds; 4 endpoints; 1 Dashboard page + a detail modal.

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | Read-only; displays the actor already enforced by `audit_has_actor` | ✅ |
| II. Isolation / staff authz by permission data | Two catalogue codes, seeded, editable; customer rows only to `view_all`; RLS untouched | ✅ |
| III. Docs are the source of truth | Index added to the schema doc first; Part 2 gets the as-built audit routes; the design's "Device" gap is documented, not faked | ✅ |
| IV. Foundation before modules | Not a business module; reads an existing foundation table | ✅ |
| V. Test the boundary | HTTP tests for every endpoint and for visibility | ✅ |
| Reversible migrations | The index migration drops it in `down()` | ✅ |

## Project Structure

### Documentation (this feature)
```text
specs/006-audit-log/{spec,plan,research,data-model,quickstart}.md, contracts/dashboard-audit.md, checklists/requirements.md, tasks.md (next)
```

### Source Code
```text
backend
  docs/Database schema/{05_schema_security,00_schema_full}.sql (index)
  database/migrations/2026_09_29_000010_audit_log_listing_index.php
  app/Enums/{AuditCategory}.php, AuditEvent (+label, +category), StaffPermission (+2)
  app/Support/Audit/{AuditQuery,AuditCursor,AuditEntryPresenter}.php
  app/Actions/Audit/{ListAuditEntriesAction,ShowAuditEntryAction,ExportAuditEntriesAction}.php
  app/Http/Controllers/Api/V1/Dashboard/AuditLogController.php · app/Http/Requests/Dashboard/Audit/AuditFilterRequest.php
  app/Http/Resources/Audit/AuditEntryResource.php · routes/api.php · postman · docs (Part 2, features/audit-log.md, CLAUDE.md)
  tests/Feature/Audit/{AuditCatalogueTest,AuditLogListTest,AuditLogVisibilityTest,AuditLogExportTest}.php
dashboard
  src/types/{api,audit}.ts · src/api/endpoints.ts · src/services/audit.service.ts · src/composables/useAuditLog.ts
  src/pages/audit/index.vue · src/components/audit/{AuditTable,AuditEntryModal}.vue
  router · nav (+ any-of permission) · PERMISSIONS (+2) · docs/06, CLAUDE.md
```

**Structure Decision**: The existing layering on both sides; a new `Audit` domain folder.

## Phase 0 / Phase 1 outputs

[research.md](./research.md) (R1–R8), [data-model.md](./data-model.md), [contracts/dashboard-audit.md](./contracts/dashboard-audit.md), [quickstart.md](./quickstart.md). Post-design re-check: no violations.

## Complexity Tracking

None.
