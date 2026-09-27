# Reference Data — karats, branches, hours and closures

> File: `docs/features/reference-data.md` · Branch: `feature/reference-data` (backend, dashboard; Flutter has no repo yet)
> Status: in progress · Date: 2026-09-27

## Goal

Make the operator-editable reference tables real data managed from the Dashboard, per Backend spec 004
([`specs/004-reference-data/`](../../specs/004-reference-data/spec.md)):

- **Finance** turns karats on and off; **the COO** adds karats; **COO and Operations** add and edit inspection
  branches, their weekly hours, and full-day closures and public holidays. Anyone with `reference.view` sees them.
- **Role managers** assign a staff member to a branch.
- The Backend gains the single **working-hours deadline resolver** (Technical Spec Part 3 §1), which later
  features (orders, inspections) use.

## Impact Summary

```
Backend:      YES
Database:     YES
API:          YES
Dashboard:    YES
Customer App: NO
Auth:         NO
Permissions:  YES
```

## Backend Impact

- Models `Karat`, `PieceType`, `Branch`, `BranchHour`, `BranchClosure`; enum `PieceCategory`.
- Actions (`app/Actions/Reference`): `CreateKaratAction`, `ToggleKaratAction`, `CreateBranchAction`,
  `UpdateBranchAction` (replaces the whole week), `AddBranchClosureAction`, `RemoveBranchClosureAction`;
  `app/Actions/Authorization/SetStaffBranchAction`.
- `app/Support/WorkingHours`: `WorkingCalendar` (pure), `WorkingHoursResolver` (loads a branch's week and
  closures), `WorkingHoursUnavailable`, `PlatformCalendar` ("today" per branch timezone).
- `RoleEscalationGuard::assertNotSelf` (shared by role and branch assignment).
- Controllers `KaratController`, `BranchController`, `BranchClosureController`, `StaffController::updateBranch`.

## Database Impact

- Migration `2026_09_27_000010_create_reference_data`: `piece_category` enum, `karat`, `piece_type`, `branch`,
  `branch_hours`, `branch_closure` (verbatim from `docs/Database schema/01_schema_core.sql` §2, plus the
  `karat_code_range` and `piece_type_weight_order` checks). Seeds karats 24/22/21/20/18 (22 and 20 off) and 18
  piece types in every environment.
- Migration `2026_09_27_000020_staff_branch_foreign_key`: `staff.branch_id` → `branch(branch_id)`.
- `LocalReferenceSeeder` (local/testing only): two IGI branches and three all-branch holidays.

## API Changes

All under `/api/v1/dashboard`, `auth:staff` + `staff.standing`. **Non-breaking** (new endpoints).

| Endpoint | Permission | Result / errors |
|---|---|---|
| `GET /karats` | `reference.view` | `Karat[] {code, purity, is_enabled, sort_order}` |
| `POST /karats` `{code, purity, sort_order?}` | `karats.create` | `201 Karat` (off) · 422 |
| `POST /karats/{code}/toggle` `{enabled}` | `karats.toggle` | `200 Karat` · 404 |
| `GET /branches` | `reference.view` | `Branch[] {id, name_en/ar, address_en/ar, timezone, is_enabled, hours[]}` |
| `POST /branches` · `PATCH /branches/{id}` | `branches.manage` | `201`/`200 Branch` · 422 `hours.N` |
| `GET /branch-closures` | `reference.view` | `Closure[] {id, branch_id\|null, closure_date, reason_en, reason_ar}` |
| `POST /branch-closures` | `branches.manage` | `201` · `409 closure_exists` · 422 past date |
| `DELETE /branch-closures/{id}` | `branches.manage` | `204` · `409 closure_in_past` |
| `PUT /staff/{staff}/branch` `{branch_id\|null, reason}` | `roles.manage` | `200 StaffMember` · `403 escalation_denied` · `422 reason_required` |

## Dashboard Impact

- `types/api.ts` (+`ApiKarat`, `ApiBranch`, `ApiBranchHour`, `ApiBranchClosure`), new `types/reference.ts`,
  `api/endpoints.ts`, new `services/reference.service.ts`, new `composables/useReference.ts`,
  `services/errors.ts` (`closure_exists`, `closure_in_past` → conflict), `PERMISSIONS` +4.
- Pages `pages/karats/index.vue` and `pages/branches/index.vue` (with `components/reference/*`), replacing the
  placeholders; nav items unhidden and gated on `reference.view`; buttons gated on their own permissions.
- The staff "Permissions" dialog gains a branch select (enabled branches + "No branch"), sent through
  `PUT /staff/{id}/branch` only when it changed and only when the manager can see branches.
- Omitted columns (no Backend data yet): "Price today", "Pieces listed", "In progress", "Average time to a result".
- The design's "Why this matters" example is corrected: Thursday 4pm + 12 working hours at a Sun–Thu 10–18
  branch is **Monday 12:00**, not Sunday afternoon.

## Customer App Impact

Not affected — by decision (spec 004 Clarification Q1). The customer-facing read endpoint for karats, piece
types and branches, and the Customer App wiring (`ContentRepository.branches`, `BranchScreen`, the hard-coded
kinds and karats in `services/sell_draft.dart`), come with the listings feature, where the values are first used.
**Backend requirement for that feature:** a `/customer/*` read endpoint returning enabled karats, enabled piece
types and enabled branches.

## Authentication / Authorization

Staff surface only. Viewing: `reference.view`. Changes: `karats.toggle`, `karats.create`, `branches.manage`.
Staff branch: `roles.manage`, never on yourself, reason required, applies on the target's next request.

## Permissions

New catalogue codes (additive sync gives each to `ceo` plus its seed roles):

| Code | Seed roles |
|---|---|
| `reference.view` | coo, finance, operations |
| `karats.toggle` | finance |
| `karats.create` | coo |
| `branches.manage` | coo, operations |

Roles stay Dashboard-managed; these are only the seed.

## Validation

Backend (authoritative): karat code 1..24 and unique, purity in (0, 1] with at most 5 decimals; IANA timezone;
hours `HH:MM`, closing after opening, no overlap or shared start on the same day; closure date today or later
(branch timezone, platform timezone for all branches); staff branch must be enabled. The Dashboard mirrors the
karat, time and overlap rules only to help while typing.

## Error Handling

`closure_exists` → "That day is already closed…"; `closure_in_past` → "Past closures stay…" (and the Remove
button is disabled for today and earlier). `escalation_denied`, `reason_required`, `validation_failed` and
`permission_denied` read as on the access-control screens.

## UI States

Both pages: loading, error with retry (not for 403), empty ("No karats yet", "No branches yet", "No upcoming
holidays"), success toasts. Turning off the last karat or disabling the last branch is allowed and warns.

## Testing

- Backend: `tests/Feature/Reference/{Karat,BranchManagement,BranchClosure,WorkingHoursResolver}Test.php`,
  `tests/Unit/WorkingHours/WorkingCalendarTest.php`, `tests/Feature/Authorization/StaffBranchAssignmentTest.php`;
  OpenAPI and principal-isolation lists updated.
- Dashboard: type-check, lint (changed files), build; browser check of both pages against the running Backend.
- Postman: **Dashboard → Reference Data**, and **Access Control → Set Staff Branch**.

## Breaking Changes

None. `/dashboard/staff*` responses already carried `branch_id`.

## Migration / Compatibility

Deploy the Backend first (migrations, then `php artisan db:seed --class=DashboardRolesAndPermissionsSeeder`,
the additive catalogue sync that adds the four new codes to `ceo` and their seed roles), then the Dashboard. Existing
`staff.branch_id` values without a branch are cleared by the FK migration. Rollback drops the tables and the FK.
