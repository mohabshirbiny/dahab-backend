# Feature Specification: Reference Data — Karats, Piece Types, Branches, Working Hours

**Feature Branch**: `feature/reference-data` (backend and dashboard; the Customer App has no repository yet)

**Created**: 2026-09-27

**Status**: Draft

**Input**: User description: "Reference data (feature 004): karat, piece_type, branch, branch_hours, branch_closure from docs/Database schema/01_schema_core.sql §2; the working-hours deadline resolver (Part 3 §1); staff branch assignment (deferred from spec 002). Dashboard Karats and Branches and hours screens per the design reference; branch picker in the staff Permissions dialog. Permissions from the Part 1 §4 matrix. Seeds: karats 18/20/21/22/24, piece types from the Customer App's sell flow; branches and holidays entered from the Dashboard. Multi-project rule applies."

## Context

- **Sources**:
  - `docs/Database schema/01_schema_core.sql` §2 (the "data not code" rule: every karat, piece type and branch is a row an operator edits, never a literal in code)
  - Technical Spec Part 3 §1 (the working-hours resolver)
  - Part 2 §10 (karat toggle)
  - Part 1 §4 (permission seed)
  - Dashboard design reference screens "Karats" and "Branches and hours"
  - `docs/dahabctoblueprint.md` is not a reference.
- **Why now**: the next features depend on this data.
  - **Listings** need karats, piece types and a seller's willing branches.
  - **Pricing** (005) needs karat purity.
  - **Accept** needs the reach-branch deadline, which is counted in working hours at the chosen branch.
  - **IGI branch scoping** (spec 002) needs real branches to assign staff to.
- **Out of scope**:
  - gold price and settings (feature 005);
  - a customer-facing reference endpoint and Customer App wiring (the listings feature);
  - piece-type management;
  - the design's "Price today", "Pieces listed", "In progress" and "Average time to a result" columns (they need pricing, listings and orders);
  - category stop/pause controls ("Switches", feature 005).

## Clarifications

### Session 2026-09-27

- Q: Does the Customer App read karats, piece types and branches from the Backend in this feature? → A: No. The customer-facing read endpoint and the Customer App wiring come with the listings feature, where the values are first used. Customer App: not affected by 004.
- Q: Does the Dashboard get a piece-type management screen? → A: No. Piece types are seeded only. Management endpoints and a screen come when there is a design for them.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage branches, their weekly hours and closures (Priority: P1)

An operations manager opens "Branches and hours". They see each inspection branch with where it is, the days and hours it is open, and the days it is closed. They add a branch with its address (English and Arabic) and weekly hours, and can edit it later. They also maintain the holiday list: a date, what it is, and whether it applies to all branches or one branch.

**Why this priority**: Every deadline a seller must meet is counted in working hours at a branch. Without correct hours and holidays, the app would chase people on days the branch is shut.

**Independent Test**: Add a branch open Sunday to Thursday 10:00–18:00, add a national holiday, and read it back in the Dashboard exactly as entered.

**Acceptance Scenarios**:

1. **Given** a staff member allowed to manage branches, **When** they add a branch with names, addresses, timezone and weekly hours, **Then** it appears in the list with its open days and hours, and the change is audited.
2. **Given** a branch, **When** its weekly hours are replaced (including a split day, for example 10:00–13:00 and 14:00–18:00), **Then** the new hours take effect for deadlines computed from then on.
3. **Given** hours where a closing time is not after the opening time, or two intervals on the same day overlap, **When** they are saved, **Then** the save is refused with a clear message.
4. **Given** a holiday for all branches or one branch, **When** it is added, **Then** it appears in the holidays list. Adding the same date twice for the same scope is refused.
5. **Given** a branch that is no longer used, **When** it is disabled, **Then** it disappears from choices offered to customers, but past records that reference it keep it.
6. **Given** a staff member without the permission, **When** they try any of these changes, **Then** they are refused.

---

### User Story 2 - Working-hours deadlines (Priority: P1)

Whenever the platform needs "N working hours from now at branch B" (for example, a seller's time to reach the branch after accepting), one shared calculation returns the exact deadline. It uses that branch's weekly hours, its closures, and national holidays, in the branch's timezone.

**Why this priority**: It is the single most reused rule in the system and the one most likely to drift if copied, so it must exist, and be proven correct, before any module uses it.

**Independent Test**: For a branch open Sunday to Thursday 10:00–18:00, 12 working hours starting Thursday 16:00 end on **Monday 12:00**: 2 hours on Thursday and 8 on Sunday make 10, and the last 2 land on Monday morning. The design reference's explanatory note says "Sunday afternoon" for this example. That is illustrative copy that doesn't hold with 10:00–18:00 hours, and the spec follows Part 3 §1. The Dashboard note is reworded accordingly.

**Acceptance Scenarios**:

1. **Given** a branch open Sunday to Thursday 10:00–18:00 in Africa/Cairo, **When** 12 working hours are counted from Thursday 16:00, **Then** the deadline is Monday 12:00. Friday and Saturday add nothing, Thursday gives 2 hours, and Sunday gives 8 hours.
2. **Given** the start falls outside open hours (for example Friday), **When** counting, **Then** the clock starts at the next opening.
3. **Given** a national holiday (all branches) or a branch closure on a normally open day, **When** counting across it, **Then** that day contributes nothing.
4. **Given** a split day, **When** counting, **Then** the lunch gap contributes nothing.
5. **Given** a branch with no open hours at all, **When** a deadline is requested, **Then** the calculation refuses with a clear error instead of looping forever.
6. **Given** a daylight-saving or timezone difference between the server and the branch, **When** counting, **Then** all arithmetic is in the branch's timezone.

---

### User Story 3 - Karats (Priority: P1)

A finance or CEO user opens "Karats" and sees each karat with its purity and whether sellers can choose it. They turn a karat on or off. Turning one on makes it available in the app immediately, with no release. Turning one off stops new listings, and pieces already listed carry on. A founder can add a new karat.

**Why this priority**: Listings and pricing both key on karats and their purity.

**Independent Test**: Turn 22K on; the customer-facing list of karats includes it immediately. Turn it off; it disappears from new choices.

**Acceptance Scenarios**:

1. **Given** the seed, **When** the Dashboard loads, **Then** it shows 24K (999), 22K (916, off), 21K (875), 20K (833, off) and 18K (750), in display order.
2. **Given** a staff member holding the karat permission, **When** they turn a karat on or off, **Then** it takes effect at once and is audited.
3. **Given** a founder, **When** they add a karat with a code and purity between 0 and 1, **Then** it is created, switched off, and placed in display order. A duplicate code is refused.
4. **Given** an existing karat, **When** anyone tries to change its code or purity, **Then** it is refused, because prices and listings depend on them.

---

### User Story 4 - Piece types (Priority: P2)

The kinds of piece a seller can list (ring, earrings, chain, …) per category (gold, diamond, gold with diamond) are reference data. They are seeded from the Customer App's current sell flow, each with an optional typical weight range. Managing them from the Dashboard is deferred until there is a design (Clarification Q2); this feature seeds them.

**Why this priority**: Listings (a later feature) require a piece type. The seed is enough to start.

**Independent Test**: The seeded piece types match the Customer App's current choices per category.

**Acceptance Scenarios**:

1. **Given** the seed, **When** read, **Then** gold has ring, earrings, chain, bangle, pendant and other; diamond and gold-with-diamond each have ring, earrings, pendant, bridal set, bracelet and other. Each has English and Arabic names.
2. **Given** a typical weight range in the seed, **When** both ends are set, **Then** minimum ≤ maximum (enforced by the database).

---

### User Story 5 - Assign a staff member to a branch (Priority: P2)

A role manager opens a staff member's Permissions dialog and sets their branch, or none. Permissions marked branch-scoped (spec 002) then only work for records of that branch. The IGI inspection flow is the first to use this.

**Why this priority**: Spec 002 deferred it until branches exist. It is needed before inspection, not before listings.

**Independent Test**: Assign an IGI staff member to branch 1; their profile shows branch 1. Assign none; their branch is empty.

**Acceptance Scenarios**:

1. **Given** a role manager, **When** they set a staff member's branch (with a written reason), **Then** it is saved, audited, and applies on the staff member's next request.
2. **Given** a disabled or unknown branch, **When** assigned, **Then** it is refused.
3. **Given** a role manager editing themselves, **When** they change their own branch, **Then** it is refused, like any change to their own access (spec 002).

---

### Edge Cases

- **Deadline start exactly at closing time**: counts from the next opening.
- **Deadline ending exactly at a closing time**: the deadline is that closing instant, not the next opening.
- **Fractional hours** (for example 1.5 working hours): supported to the minute.
- **Holiday on a normally closed day**: no effect.
- **Branch closure plus a national holiday on the same date**: counted once (zero).
- **Branch in a different timezone**: its own calendar governs, never the customer's or the server's.
- **Editing hours while deadlines are already set**: stored deadlines don't change. Only future calculations use the new hours.
- **Disabling a branch that staff are assigned to**: allowed. They keep the assignment until changed, and branch-scoped actions on its records still work, since records keep their branch.
- **Deleting a holiday that is already in the past**: refused. Past deadlines were computed with it. Only future holidays can be removed.
- **The last enabled karat or branch turned off**: allowed. Customers simply have no choice until one is turned on. The Dashboard warns.

## Requirements *(mandatory)*

### Functional Requirements

**Branches, hours, closures**

- **FR-001**: Staff holding the branch permission MUST be able to list, add and edit branches:
  - English and Arabic name and address;
  - timezone (default Africa/Cairo);
  - enabled flag.

  Branches are never deleted, only disabled.
- **FR-002**: Branch weekly hours MUST be replaceable as a whole per branch, as intervals per day of week (Sunday = 0 … Saturday = 6), several allowed per day. Closing must be after opening, and intervals on the same day must not overlap.
- **FR-003**: Staff holding the branch permission MUST be able to add closures (a date, an optional reason in English and Arabic, and one branch or all branches) and remove future ones. The same date cannot be added twice for the same scope.
- **FR-004**: Every branch, hours and closure change MUST be audited with actor, before and after.

**Working-hours resolver**

- **FR-010**: There MUST be exactly one working-hours deadline calculation: start instant plus a working-time amount plus a branch gives the deadline instant. It follows Part 3 §1:
  - branch timezone;
  - weekly template;
  - branch plus all-branch closures contribute zero;
  - the clock starts at the next open interval;
  - minute precision.
- **FR-011**: The calculation MUST refuse, rather than loop, when the branch has no open time within a bounded search window (at least 366 days).
- **FR-012**: Calendar deadlines (storage windows) MUST NOT use this calculation (Part 3 §1.3).

**Karats**

- **FR-020**: Karats MUST be listable with code, purity, enabled flag and display order.
- **FR-021**: Staff holding the karat permission MUST be able to turn a karat on or off (Part 2 §10). The change takes effect immediately and is audited.
- **FR-022**: A founder MUST be able to add a karat (code 1–24, purity greater than 0 and at most 1, display order). New karats start switched off.
- **FR-023**: A karat's code and purity MUST NOT change after creation.

**Piece types**

- **FR-030**: Piece types MUST be seeded per category as listed in User Story 4, with English and Arabic names.
- **FR-031**: Piece types are read-only in this feature (seed only). The typical weight range is optional, and when both ends are given, minimum ≤ maximum. Management is deferred (Clarification Q2).

**Staff branch assignment**

- **FR-040**: A role manager MUST be able to set or clear a staff member's branch. It needs a written reason, is audited, refuses disabled or unknown branches, refuses changes to one's own branch, and applies on the next request.
- **FR-041**: A staff member's branch MUST reference a real branch.

**Customer-facing reference data**

- **FR-050**: No customer-facing reference endpoint in this feature. It is delivered with the listings feature, together with the Customer App wiring (Clarification Q1).

**Permissions (seed; editable from the Dashboard per spec 002)**

- **FR-060**: New permission codes, with their seed roles (`ceo` always receives every code, spec 002):
  - `reference.view`: see the Karats and Branches screens. Seed: CEO, COO, Finance, Operations.
  - `karats.toggle`: turn a karat on or off (Part 2 §10). Seed: CEO, Finance.
  - `karats.create`: add a karat. It is not in the Part 1 matrix, so founders only. Seed: CEO, COO.
  - `branches.manage`: add or edit a branch, its hours and closures (Part 1 §4.3 "Add or edit an inspection branch"). Seed: CEO, COO, Operations.
  - Staff branch assignment reuses `roles.manage` (spec 002).

**Seeds and docs**

- **FR-070**:
  - Karats 18 (0.750), 20 (0.833, off), 21 (0.875), 22 (0.916, off) and 24 (0.999) are seeded in every environment.
  - Piece types are seeded in every environment.
  - Branches, hours and holidays are seeded only for local development (two sample IGI branches and three national holidays, as in the design reference).
- **FR-071**: Documentation MUST match what is built:
  - schema §2;
  - Part 2 (the reference-data endpoints);
  - the Dashboard integration doc;
  - the cross-project feature doc.

### Key Entities

- **Karat**: code (for example 21), purity (0.875), enabled, display order. Immutable code and purity.
- **Piece type**: category, English and Arabic name, optional typical weight range, enabled.
- **Branch**: English and Arabic name and address, timezone, enabled. Has weekly hours and closures.
- **Weekly hours**: day of week plus opening and closing time. Several per day allowed.
- **Closure**: a date, an optional reason, and one branch or all branches.
- **Staff branch**: an optional link from a staff member to a branch, used by branch-scoped permissions.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An operator can add a branch with weekly hours and a holiday in under 3 minutes, with no code change or release.
- **SC-002**: The deadline calculation matches hand-computed expectations for 100% of a documented set of at least 12 scenarios: weekends, holidays, split days, start outside hours, end at closing, other timezone, fractional hours.
- **SC-003**: Turning a karat on or off is reflected in every consumer on its next read, with no release.
- **SC-004**: 0 karat, piece-type or branch values are hard-coded in Backend business logic or the Dashboard after this feature. The Customer App follows with the listings feature.
- **SC-005**: 100% of reference-data changes appear in the audit log with actor and before/after.

## Assumptions

- The permission catalogue grows with new codes, seeded as spec 002 requires.
- The resolver is an internal capability. No customer-facing endpoint exposes it in this feature; accept (a later feature) is its first caller.
- Branch `timezone` values are IANA names.
- Reference data is small (tens of rows), so the Dashboard lists are not paginated.
- Stored deadlines are never recomputed when hours change.
