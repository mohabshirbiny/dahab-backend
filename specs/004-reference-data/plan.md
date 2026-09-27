# Implementation Plan: Reference Data — Karats, Piece Types, Branches, Working Hours

**Branch**: `feature/reference-data` (backend + dashboard) | **Date**: 2026-09-27 | **Spec**: [spec.md](./spec.md)

## Summary

Add the reference tables from schema §2 (karat, piece_type, branch, branch_hours, branch_closure) with seeds, and constrain `staff.branch_id` to a real branch. Add one working-hours resolver (Part 3 §1). Add the Dashboard endpoints and two screens (Karats, Branches and hours), plus a branch picker for staff.

The Customer App is not affected (Clarification Q1). Piece types are seed-only (Q2).

## Technical Context

**Language/Version**: PHP 8.3+/Laravel 12 (backend); Vue 3 + TypeScript + Vuetify (dashboard)
**Primary Dependencies**: Spatie permission (catalogue codes), Carbon (timezones), TanStack Query (dashboard)
**Storage**: PostgreSQL 16. The new tables have no customer owner columns, so no RLS (spec 003 check passes).
**Testing**: Pest (HTTP + a database-free resolver scenario suite); dashboard type-check, lint and build
**Target Platform**: Linux containers / Laragon
**Project Type**: Web service + staff SPA
**Performance Goals**: The resolver answers in < 50 ms for ≤ 366-day windows (tiny data).
**Constraints**: Data-not-code; no hard-coded karat, branch or piece-type values in business logic; branch timezone governs.
**Scale/Scope**: Tens of rows; 10 endpoints; 2 Dashboard pages + a dialog field.

## Constitution Check

| Principle | Check | Status |
|---|---|---|
| I. Named actor | All writes audited with the staff actor | ✅ |
| II. Customer isolation / staff authz by permission data | New catalogue codes, seeded; no customer data touched | ✅ |
| III. Docs are the source of truth | Tables mirror §2; the four additions (R1) go into the schema doc first; Part 2 is updated with the implemented routes | ✅ (tracked) |
| IV. Foundation before modules | Reference data is foundation for listings and pricing | ✅ |
| V. Test the boundary | Pest HTTP tests for every endpoint + resolver scenarios (SC-002) | ✅ |
| Reversible migrations | `down()` drops tables, the type and the FK | ✅ |

## Project Structure

```text
backend
  database/migrations/2026_09_27_0000{10,20}_*.php   # reference tables + seeds; staff.branch_id FK
  app/Models/{Karat,PieceType,Branch,BranchHour,BranchClosure}.php (+ factories)
  app/Enums/PieceCategory.php, StaffPermission (+4), AuditEvent (+8)
  app/Support/WorkingHours/{WorkingCalendar,WorkingHoursResolver,WorkingHoursUnavailable}.php
  app/Actions/Reference/*.php, app/Actions/Authorization/SetStaffBranchAction.php
  app/Http/Controllers/Api/V1/Dashboard/{KaratController,BranchController,BranchClosureController}.php
  app/Http/Requests/Dashboard/Reference/*.php, app/Http/Resources/Staff/{Karat,Branch,BranchClosure}Resource.php
  routes/api.php · postman · docs (schema §2, Part 2 §10, feature doc)
  tests/Feature/Reference/*, tests/Unit/WorkingHours/WorkingCalendarTest.php
dashboard
  src/types/{api,reference}.ts · src/services/reference.service.ts · src/composables/useReference.ts
  src/pages/karats/index.vue · src/pages/branches/index.vue · src/components/reference/*
  src/components/staff/StaffRolesModal.vue (branch field) · router · nav · errors · docs/06
```

**Structure Decision**: Existing layering on both sides. A new `Reference` domain folder in the backend.

## Complexity Tracking

None.
