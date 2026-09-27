# Research: Reference Data

**Feature**: [spec.md](./spec.md) · **Date**: 2026-09-27

## R1. Tables exactly as the schema

**Decision**: Migrations reproduce `docs/Database schema/01_schema_core.sql` §2 verbatim: the `piece_category` enum, `karat`, `piece_type`, `branch`, `branch_hours` and `branch_closure`, including their CHECKs and unique keys. Four additions, recorded in the schema doc first (Constitution III):
- `piece_type_weight_order CHECK (typical_min_g IS NULL OR typical_max_g IS NULL OR typical_min_g <= typical_max_g)` (spec US4).
- `karat_code_range CHECK (karat_code BETWEEN 1 AND 24)` (FR-022).
- `staff.branch_id` gains `REFERENCES branch(branch_id)` (the schema already declares it on the table; the earlier migration left it unconstrained because `branch` did not exist).
- `created_at`/`updated_at` are not added. Changes are tracked in `audit_log`.

**Existing data**: before adding the FK, set `staff.branch_id = NULL` wherever no branch row matches. Local development seeded `igi_branch@` with branch 1; the local seeder now creates branches first.

## R2. Overlapping hours

**Decision**: The primary key `(branch_id, dow, opens_at)` plus `branch_hours_order` are the DB guarantees. Non-overlap within a day is validated by the Action (`ReplaceBranchHoursAction`), which receives the whole week at once. There is no exclusion constraint: that needs the `btree_gist` extension, which the app's DB role cannot create.

## R3. Resolver

**Decision**: `App\Support\WorkingHours\WorkingHoursResolver::addWorkingMinutes(CarbonImmutable $start, int $minutes, int $branchId): CarbonImmutable`.
- It loads the branch timezone, the weekly template, and the closures for `branch_id = ? OR branch_id IS NULL` inside the search window. It then walks day by day in the branch timezone from `$start` (converted to it), consuming minutes inside each open interval that ends after the cursor. Closure dates contribute zero.
- It returns the instant in UTC-aware `CarbonImmutable` at minute precision.
- It throws `WorkingHoursUnavailable` after 366 days with no remaining capacity (FR-011).
- "Ends exactly at closing": the loop returns `opens + remaining` when remaining equals the rest of the interval, so the closing instant itself.
- A pure calculation on already-loaded data (`WorkingCalendar` value object) keeps the scenario tests fast and database-free (SC-002). The resolver is a thin loader around it.

## R4. Endpoints (Dashboard, all `auth:staff` + `staff.standing`)

| Method | Path | Permission |
|---|---|---|
| GET | `/dashboard/karats` | `reference.view` |
| POST | `/dashboard/karats` | `karats.create` |
| POST | `/dashboard/karats/{code}/toggle` `{enabled}` | `karats.toggle` (explicit target state, so a retry is harmless) |
| GET | `/dashboard/branches` (with hours) | `reference.view` |
| POST | `/dashboard/branches` (with hours) | `branches.manage` |
| PATCH | `/dashboard/branches/{branch}` (fields and/or the whole `hours` week) | `branches.manage` |
| GET | `/dashboard/branch-closures` | `reference.view` |
| POST | `/dashboard/branch-closures` | `branches.manage` |
| DELETE | `/dashboard/branch-closures/{closure}` (future only → `409 closure_in_past`) | `branches.manage` |
| PUT | `/dashboard/staff/{staff}/branch` `{branch_id|null, reason}` | `roles.manage` (not self → `escalation_denied`) |

Part 2 names the karat toggle under `/admin`. The implemented surface is `/dashboard`, as with every Dashboard route since spec 001.

## R5. Audit

New events:
- `reference.karat.created`
- `reference.karat.toggled`
- `reference.branch.created`
- `reference.branch.updated`
- `reference.branch.hours_replaced`
- `reference.closure.added`
- `reference.closure.removed`
- `authz.staff.branch_changed`

Integer ids go in the payload, since `audit_log.entity_id` is a UUID (same as roles in spec 002).

## R6. Tests and factories

`StaffFactory::withRole('igi_branch')` sets `branch_id = 1`. With the new FK, the factory creates or reuses a branch via `Branch::factory()`. New factories: `Karat` (states), `Branch` (with a Sunday–Thursday 10–18 week by default), `BranchClosure`.

## R7. Dashboard

- Two pages following the design: `/dashboard/karats` and `/dashboard/branches`. They reuse `DataTable`, `DModal`, `FormField`, `StatusTag` and `ConfirmDialog`.
- The nav items "Karats" and "Branches and hours" are unhidden, gated on `reference.view`.
- The staff Permissions dialog gains a branch select, sent through the new endpoint only when it changed.
- Columns with no Backend data (price today, pieces listed, in progress, average time) are omitted, not faked.
- The design's explanatory note is reworded to the correct example.
