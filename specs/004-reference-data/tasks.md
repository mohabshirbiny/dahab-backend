# Tasks: Reference Data

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)
**Tests**: required (Constitution V).

## Phase 1: Setup
- [X] T001 Docs first (Constitution III): in `docs/Database schema/01_schema_core.sql` §2 (and the `00_schema_full.sql` mirror), add the four additions from research R1 and the seed rows for karats and piece types.

## Phase 2: Foundational
- [X] T002 Migration `2026_09_27_000010_create_reference_data.php`: the `piece_category` enum, the five tables with their constraints, and the karat and piece-type seed rows (every environment). Reversible.
- [X] T003 Migration `2026_09_27_000020_staff_branch_foreign_key.php`: null out dangling `staff.branch_id`, add the FK. Reversible.
- [X] T004 [P] Models + factories: `Karat`, `PieceType`, `Branch` (hours relation), `BranchHour`, `BranchClosure`; enum `PieceCategory`. `StaffFactory::withRole('igi_branch')` creates or uses a real branch.
- [X] T005 [P] Catalogue: `StaffPermission` + `reference.view`, `karats.toggle`, `karats.create`, `branches.manage` (label, group "Reference data", seed roles per data-model). `AuditEvent` + the 8 events. `DomainApiException` + `closureExists`, `closureInPast`.
- [X] T006 `LocalReferenceSeeder` (local/testing only): 2 branches, their hours, 3 all-branch holidays. `DatabaseSeeder` calls it before `LocalStaffSeeder`.

## Phase 3: US2 — Working-hours resolver (P1)
- [X] T007 [P] [US2] `tests/Unit/WorkingHours/WorkingCalendarTest.php`: ≥ 12 hand-computed scenarios (SC-002):
  - the design example → Monday 12:00
  - start on a closed day
  - start before opening
  - start exactly at closing
  - end exactly at closing
  - split day
  - national holiday
  - branch closure
  - holiday on a closed day
  - fractional hours (90 min)
  - another timezone (Asia/Dubai)
  - a multi-day amount
  - no open hours → exception
- [X] T008 [US2] `app/Support/WorkingHours/WorkingCalendar.php` (pure), `WorkingHoursResolver.php` (loads the branch week + closures), `WorkingHoursUnavailable.php`. Plus `tests/Feature/Reference/WorkingHoursResolverTest.php` (DB-backed: branch closure vs all-branch closure).

## Phase 4: US1 — Branches, hours, closures (P1)
- [X] T009 [P] [US1] `tests/Feature/Reference/BranchManagementTest.php`:
  - list, create (+ week), patch fields, replace week (split day OK, overlap/order → 422), disable
  - permissions (`operations` OK, `finance` 403)
  - audit
- [X] T010 [P] [US1] `tests/Feature/Reference/BranchClosureTest.php`:
  - add a branch or all-branch closure
  - duplicate → 409 `closure_exists`
  - past date → 422
  - delete a future one → 204; delete a past one → 409 `closure_in_past`
  - audit
- [X] T011 [US1] Actions: `CreateBranchAction`, `UpdateBranchAction` (fields + optional `ReplaceBranchHours`), `AddBranchClosureAction`, `RemoveBranchClosureAction`. FormRequests, `BranchResource`, `BranchClosureResource`, controllers with `#[OA]`, routes.

## Phase 5: US3 — Karats (P1)
- [X] T012 [P] [US3] `tests/Feature/Reference/KaratTest.php`:
  - list order and seed
  - toggle (finance OK, operations 403, unknown 404)
  - create (coo OK, finance 403, duplicate/invalid 422, starts off)
  - code/purity immutable (no update route)
  - audit
- [X] T013 [US3] `ToggleKaratAction`, `CreateKaratAction`, requests, `KaratResource`, `KaratController` with `#[OA]`, routes.

## Phase 6: US5 — Staff branch (P2)
- [X] T014 [P] [US5] `tests/Feature/Authorization/StaffBranchAssignmentTest.php`:
  - set/clear with a reason
  - self → 403
  - disabled/unknown branch → 422
  - system actor → 404
  - audit
  - the profile shows it
- [X] T015 [US5] `SetStaffBranchAction` (authz lock, escalation guard on self, `ReasonRule`), `SetStaffBranchRequest`, `StaffController::updateBranch` with `#[OA]`, route.

## Phase 7: Dashboard
- [X] T016 Types (`api.ts`, new `reference.ts`), `endpoints.ts`, `reference.service.ts`, `useReference.ts`, `PERMISSIONS` (+4), error mapping (`closure_exists`, `closure_in_past` → conflict).
- [X] T017 `pages/karats/index.vue` + components (table, add-karat dialog). Omitted columns noted; route `reference.view`; nav unhidden.
- [X] T018 `pages/branches/index.vue` + components (branches table, branch editor with the week editor, holidays table, add-holiday dialog, confirm delete). The design note is reworded to the correct example.
- [X] T019 Staff Permissions dialog: branch select (all enabled branches + "No branch"), saved through `PUT /staff/{id}/branch` only when it changed.
- [X] T020 Dashboard type-check, lint (changed files), build; `docs/06` + `CLAUDE.md` status.

## Phase 8: Polish
- [X] T021 Docs: Part 2 §10 (implemented reference routes), the cross-project feature doc `docs/features/reference-data.md`, and the Postman folder **Dashboard → Reference data**.
- [X] T022 Quality gates:
  - Pint
  - the full Pest suite
  - migrate fresh/rollback as `dahab`
  - swagger + the OpenAPI test lists
  - `PrincipalIsolationTest` count
  - browser check of both pages against the running Backend

## Dependencies
T001 → T002–T006 → US2 (T007–T008) → US1 (T009–T011) ∥ US3 (T012–T013) → US5 (T014–T015) → Dashboard (T016–T020) → Polish.
